<?php

namespace Drupal\foldershare\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AlertCommand;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Utilities;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Plugin\FolderShareCommandManager;

/**
 * Creates a form to prompt for plugin command parameters.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This form is invoked to prompt the user for additional plugin
 * command-specific parameters, beyond primary parameters for the parent
 * folder (if any), destination folder (if any), and selection (if any).
 * Each plugin has its own (optional) form fragment that prompts for its
 * parameters. This form page incorporates that fragment and adds a
 * page title and submit button. It further orchestrates creation of
 * the plugin and invokation of its functions.
 *
 * The form page accepts a URL with a single required 'encoded' parameter
 * that includes a base64-encoded JSON-encoded associative array that
 * has the plugin ID, configuration (parent, destination, selection),
 * and URL to get back to the invoking page.
 *
 * @ingroup foldershare
 */
class EditCommand extends FormBase {

  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   * These fields provided cached values from dependency injection.
   *
   *--------------------------------------------------------------------*/

  /**
   * The plugin manager for folder shared commands.
   *
   * @var \Drupal\foldershare\FolderShareCommand\FolderShareCommandManager
   */
  protected $commandPluginManager;

  /**
   * The command's plugin ID from URL parameters.
   *
   * The plugin ID is a unique string that identifies the plugin within
   * the set of loaded plugins in the plugin manager.
   *
   * @var string
   */
  protected $pluginId;

  /**
   * An instance of the command plugin.
   *
   * The command instance is created using the plugin ID from the
   * URL parameters.
   *
   * @var \Drupal\foldershare\FolderShareCommand\FolderShareCommandInterface
   */
  protected $command;

  /**
   * The URL to redirect to (or back to) after form submission.
   *
   * The URL comes from the URL parameters and indicates the original
   * page to return to after the command executes.
   *
   * @var string
   */
  protected $url;

  /**
   * Whether to enable AJAX.
   *
   * The flag comes from the command parameters given to buildForm().
   * By default, this is FALSE, but it can be set TRUE if the caller
   * sets the flag in the parameters. This would be the case if the
   * caller has embedded the form within an AJAX dialog and therefore
   * requires that this form continue to use AJAX as well.
   *
   * @var bool
   */
  protected $enableAjax;

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   * These functions create an instance of the form.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a new form.
   *
   * @param \Drupal\foldershare\FolderShareCommand\FolderShareCommandManager $commandPluginManager
   *   The command plugin manager.
   */
  public function __construct(FolderShareCommandManager $commandPluginManager) {
    // Save the plugin manager, which we'll need to create a plugin
    // instance based upon a parameter on the form's URL.
    $this->commandPluginManager = $commandPluginManager;
    $this->enableAjax = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('foldershare.plugin.manager.foldersharecommand')
    );
  }

  /*--------------------------------------------------------------------
   *
   * Form setup.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    // Base the form ID on the namespace-qualified class name, which
    // already has the module name as a prefix.  PHP's get_class()
    // returns a string with "\" separators. Replace them with underbars.
    return str_replace('\\', '_', get_class($this));
  }

  /*--------------------------------------------------------------------
   *
   * Form build.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $formState = NULL,
    string $encoded = NULL) {

    // Build a form that wraps the plugin command's configuration form
    // to create a full page form, including a page title and submit button.
    //
    // Decode parameters passed on a URL to this form.
    $parameters = $this->decodeParameters($encoded);
    if (empty($parameters) === TRUE) {
      return $form;
    }

    // Get the plugin ID, configuration, URL, and AJAX flag.
    $this->pluginId   = $parameters['pluginId'];
    $configuration    = $parameters['configuration'];
    $this->url        = $parameters['url'];
    if (isset($parameters['enableAjax']) === TRUE) {
      $this->enableAjax = $parameters['enableAjax'];
    }
    else {
      $this->enableAjax = FALSE;
    }

    //
    // Instantiate plugin
    // ------------------
    // The plugin ID gives us the name of the plugin. Create one and pass
    // in the configuration. This initializes the plugin's internal
    // structure, but does not validate or execute it. The plugin's form
    // below will prompt the user for additional configuration values.
    $this->command = $this->commandPluginManager->createInstance(
      $this->pluginId,
      $configuration);
    $def = $this->command->getPluginDefinition();

    //
    // Pre-validate
    // ------------
    // The invoker of this form should already have checked that the
    // parent and destination are valid for this command. But validate
    // again now to insure that internal cached values from validation
    // are set before we continue. If validation fails, there is no
    // point in building much of a form.
    try {
      $this->command->validateParentConstraints();
      $this->command->validateSelectionConstraints();
    }
    catch (\Exception $e) {
      // Validation failed. This should not be possible since the caller
      // should have already checked things.
      drupal_set_message($e->getMessage(), 'error');
      return $form;
    }

    //
    // Load selection
    // --------------
    // Loop through the selection IDs and load all of them.
    $selectionIds = $configuration['selectionIds'];
    if (empty($selectionIds) === TRUE) {
      $selectionIds = [$configuration['parentId']];
    }

    $unsortedSelection = FolderShare::loadMultiple($selectionIds);

    $selection = [];
    foreach ($unsortedSelection as $entity) {
      if ($entity === NULL) {
        continue;
      }

      $kind = $entity->getKind();
      if (isset($selection[$kind]) === TRUE) {
        $selection[$kind][] = $entity;
      }
      else {
        $selection[$kind] = [$entity];
      }
    }

    //
    // Set up form
    // -----------
    // The command provides its own form fragment to prompt for the
    // values it needs to complete its configuration.
    //
    // Attach library.
    $form['#attached']['library'][] = Constants::LIBRARY_MODULE;

    // Mark form as tree-structured and non-collapsable.
    $form['#tree'] = TRUE;

    //
    // Add actions
    // -----------
    // Add the submit button.
    $form['actions'] = [
      '#type'          => 'actions',
      '#weight'        => 100,
      'submit'         => [
        '#type'        => 'submit',
        '#value'       => t($this->command->getSubmitButtonName()),
        '#button_type' => 'primary',
      ],
    ];

    // When AJAX is enabled, add an AJAX callback to the submit button.
    if ($this->enableAjax === TRUE) {
      $form['actions']['submit']['#ajax'] = [
        'callback'   => '::submitFormAjax',
        'event'      => 'submit',
        'trigger_as' => 'submit',
      ];
    }

    //
    // Set title
    // ---------
    // Use the command's title and insert an operand description.
    $form['#title'] = Utilities::createTitle($def['title'], $selection);

    //
    // Set description
    // ---------------
    // Add description.
    $form['description'] = [
      '#type'  => 'html_tag',
      '#tag'   => 'p',
      '#value' => $def['description'],
    ];

    //
    // Add command form
    // ----------------
    // Add the command's form.
    $form = $this->command->buildConfigurationForm($form, $formState);

    return $form;
  }

  /*--------------------------------------------------------------------
   *
   * Form validate.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $formState) {
    //
    // Validate the user's input for the form by passing them to the
    // command to validate.
    if ($this->command === NULL) {
      // Fail. No command?
      return;
    }

    //
    // Validate additions
    // ------------------
    // Let the command's form validate the user's input.
    $this->command->validateConfigurationForm($form, $formState);
    if ($formState->hasAnyErrors() === TRUE) {
      // The command's validation failed. There is no point in continuing.
      return;
    }

    //
    // Validate rest
    // -------------
    // The command's configuration form should have validated whatever
    // additional parameters it has added. And earlier we validated the
    // parent and selection. But just to be sure, validate everything
    // one last time. Since validation skips work if it has already
    // been done, this will be fast if everything is validated fine already.
    try {
      $this->command->validateConfiguration();
    }
    catch (\Exception $e) {
      // Validation failed. This should not be possible if all prior
      // validation checks occurred as they were supposed to.
      drupal_set_message($e->getMessage(), 'error');
    }

    //
    // Check access
    // ------------
    // Check that the user has access. This could check role-based
    // permissions and folder-based access controls on the parent (if any),
    // destination (if any), and selection (if any).
    $result = $this->command->access(NULL, FALSE);
    if ($result === FALSE) {
      // Fail.
      //
      // The user does not have sufficient permissions to execute the command.
      drupal_set_message(
        t('You do not have sufficient permissions or granted access to perform the "@command" command on the selected items.',
          ['@command' => $this->command->label()]),
        'error');
    }
  }

  /*--------------------------------------------------------------------
   *
   * Form submit (no-AJAX).
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState) {
    if ($this->enableAjax === TRUE) {
      return;
    }

    if ($this->command === NULL) {
      // Fail. No command?
      return;
    }

    // Submit the user's input to update the command's configuration,
    // then execute it. This is under the control of the command's submit
    // function.
    $this->command->submitConfigurationForm($form, $formState);

    // When the command is done, redirect the user back to where they
    // started, if we know.
    if ($this->url !== NULL) {
      $formState->setRedirectUrl($this->url);
    }
  }

  /*--------------------------------------------------------------------
   *
   * Form submit (AJAX).
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function submitFormAjax(array &$form, FormStateInterface $formState) {
    // Submit the user's input to update the command's configuration,
    // then execute it. This is under the control of the command's submit
    // function.
    $this->command->submitConfigurationForm($form, $formState);

    $response = new AjaxResponse();
    $response->addCommand(new AlertCommand('done with command'));
    return $response;
  }

  /*--------------------------------------------------------------------
   *
   * Utilities.
   *
   * These utility functions provide support for the above functionality.
   *
   *--------------------------------------------------------------------*/

  /**
   * Decodes plugin information passed to the form via its URL.
   *
   * @param string $encoded
   *   The base64 encoded string included as a parameter on the form URL.
   */
  private function decodeParameters(string $encoded) {
    //
    // The incoming parameters are an encoded associative array that provides
    // the plugin ID, configuration, and redirect URL. Encoding has expressed
    // the array as a base64 string so that it could be included as a
    // URL parameter.  Decoding reverses the base64 encode, and a JSON decode
    // to return an object, which we cast to an array as needed.
    //
    // Decode parameters.
    $parameters = (array) json_decode(base64_decode($encoded), TRUE);

    if (empty($parameters) === TRUE) {
      // Fail. No parameters?
      //
      // This should not be possible because the route requires that there
      // be parameters. At a minimum, we need the command plugin ID.
      drupal_set_message(t('Missing parameters for plugin form'), 'error');
      return [];
    }

    // Get plugin ID.
    if (empty($parameters['pluginId']) === TRUE) {
      // Fail. No plugin ID?
      //
      // The parameters are malformed and missing the essential plugin ID.
      drupal_set_message(t('Malformed parameters passed to plugin form'), 'error');
      return [];
    }

    // Get initial configuration.
    if (isset($parameters['configuration']) === FALSE) {
      // Fail. No configuration?
      //
      // The parameters are malformed and missing the essential configuration.
      drupal_set_message(t('Malformed parameters to plugin form'), 'error');
      return [];
    }

    // Get the redirect URL.
    if (empty($parameters['url']) === TRUE) {
      // Bad, but not failure.
      //
      // There should have been a return URL in the parameters. There wasn't,
      // so we have no page to return the user to.
      $parameters['url'] = NULL;
    }
    else {
      $parameters['url'] = Url::fromUserInput($parameters['url']);
    }

    // Insure the configuration is an array.
    $parameters['configuration'] = (array) $parameters['configuration'];

    return $parameters;
  }

}
