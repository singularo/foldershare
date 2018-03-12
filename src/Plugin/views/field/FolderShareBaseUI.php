<?php

namespace Drupal\foldershare\Plugin\views\field;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RedirectDestinationTrait;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;
use Drupal\views\ResultRow;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Entity\FolderShareAccessControlHandler;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface;

/**
 * Builds a base user interface for a view of FolderShare entities.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * The base user interface is a non-Javascript form containing a menu
 * of commands, a submit button, and a selection based upon a checkbox
 * on each view row. Selecting a menu command and pressing the submit
 * button invokes the command, which may refresh the page or send the
 * user to a new form to prompt for additional operands.
 *
 * To use this plugin, it must be added as a column on a view of
 * FolderShare entities. It should be the first column so that the
 * row checkboxes it creates are at the left side of each row.
 *
 * @see \Drupal\foldershare\Entity\FolderShareViewsData
 * @see \Drupal\foldershare\Entity\FolderShare
 *
 * @ViewsField("foldershare_views_field_baseui")
 */
class FolderShareBaseUI extends FieldPluginBase implements CacheableDependencyInterface {

  use RedirectDestinationTrait;
  use UncacheableFieldHandlerTrait;

  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   *--------------------------------------------------------------------*/

  /**
   * The current validated, but not yet executed command instance.
   *
   * The command already has its configuration set. It has been
   * validated, but has not yet had access controls checked and it
   * has not been executed.
   *
   * @var \Drupal\foldershare\FolderShareCommand\FolderShareCommandInterface
   */
  private $command;

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   * These functions create an instance using dependency injection to
   * cache important services needed later during execution.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a new object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $pluginId
   *   The plugin ID for the plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition) {

    parent::__construct($configuration, $pluginId, $pluginDefinition);

    $this->command = NULL;
    $this->usesOptions = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $pluginId,
    $pluginDefinition) {

    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition
    );
  }

  /*--------------------------------------------------------------------
   *
   * Cache support.
   * (Implements CacheableDependencyInterface)
   *
   * These functions support caching of the column of values managed
   * by this plugin on a view. Since the column actually has no values,
   * just checkboxes, there is nothing to cache.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return [];
  }

  /*--------------------------------------------------------------------
   *
   * Miscellaneous get/set.
   *
   *--------------------------------------------------------------------*/

  /**
   * Gets the view's parent folder ID.
   *
   * The parent ID must be the one and only contextual filter argument
   * to the view. If it is missing or malformed, this function defaults
   * to a -1 to indicate there is no parent entity. Otherwise the parent
   * ID is returned.
   *
   * @return int
   *   Returns the parent folder ID. The special value -1 could have
   *   been given as a view argument and means all folders with no parent.
   *   If no view argument was given at all, a -1 is returned.
   */
  private function getParentId() {
    // Loop through the arguments looking for a numeric ID.
    foreach ($this->getView()->argument as $arg) {
      // Skip arguments that are:
      // - Broken.
      // - Not for a 'parentid' argument.
      // - Are not numeric.
      // - Are empty.
      // - Or have more than one value.
      if ($arg->broken() === TRUE ||
          $arg->realField !== 'parentid' ||
          get_class($arg) !== 'Drupal\views\Plugin\views\argument\NumericArgument' ||
          empty($arg->value) === TRUE ||
          count($arg->value) !== (1)) {
        continue;
      }

      // Get the numeric value and use it as the parent ID. A -1 is legal,
      // but map more negative values to -1.
      $value = (int) $arg->value[0];
      return ($value < (-1)) ? (-1) : $value;
    }

    // Nothing suitable was given.
    return (-1);
  }

  /**
   * {@inheritdoc}
   */
  protected function getView() {
    return $this->view;
  }

  /*--------------------------------------------------------------------
   *
   * Views rows.
   * (Overrides FieldPluginBase)
   *
   * These functions support the checkbox column added by this plugin.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    // The checkbox column is not usable for row sorting.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    // Implementation quirk: For a regular field, getValue() would return
    // a value. But for this plugin, there is no value to return. Instead
    // we use the field to show a checkbox to select the row. We have three
    // apparent options:
    //
    // - Return a checkbox form element here.
    //
    // - Return a checkbox form element from the plugin's render() method.
    //
    // - Add a checkbox in our viewsForm() when the header form is built.
    //
    // The first option does not work because getValue() must return a
    // value, not a form element.
    //
    // The second option does not work because form elements added by
    // render() are not seen by Drupal's form API and do not contribute
    // to form state.
    //
    // The third option is what we use BUT it will not work by itself.
    // If a form element is added for the field, it is ignored UNLESS
    // a magic HTML comment is returned here. The HTML comment has a
    // fixed form:
    //
    // - <!--form-item
    // - FORM_ELEMENT_NAME
    // - --
    // - ROW_ID
    // - -->
    //
    // This is treated as a substitution set up in the views module's
    // ViewsFormMainForm class, in buildForm(). When the HTML comment is
    // seen in getValue()'s result, it is replaced with whatever form
    // element is set for the column in our viewsForm(). Very odd.
    $pluginId = $this->options['id'];
    $rowIndex = $row->index;
    return '<!--form-item-' . $pluginId . '--' . $rowIndex . '-->';
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    // There is no label for the checkbox column.
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // There is nothing to query for the checkbox column.
  }

  /*--------------------------------------------------------------------
   *
   * Views form.
   *
   * A form at the top of the view includes a command menu and submit
   * button.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function viewsForm(array &$form, FormStateInterface $formState) {

    //
    // Setup
    // -----
    // Disable caching this form.
    $form['#cache']['max-age'] = 0;

    // Maintain the tree structure of rows.
    $pluginId = $this->options['id'];
    $data = 'data-' . $pluginId . '-';
    $form[$pluginId]['#tree'] = TRUE;

    // Append style library.
    $form['#attached']['library'][] = Constants::LIBRARY_MODULE;

    // Get current selected rows.
    $rowSelections = $formState->getValue($pluginId);

    //
    // Add row checkboxes
    // ------------------
    // Loop through rows and add checkboxs. Note that this must be done
    // here, as we build the view form, rather than later when we render
    // the value, so that the checkboxes are included in the form state.
    foreach ($this->getView()->result as $rowIndex => $row) {
      // Get row entity.
      $entity = $this->getEntity($row);

      // Get access summary and clean out FALSEs.
      $access = [];
      $summary = FolderShareAccessControlHandler::getAccessSummary($entity);
      foreach ($summary as $op => $tf) {
        if ($tf === TRUE) {
          $access[] = $op;
        }
      }

      // Add checkbox, marked with entity attributes.
      //
      // Implementation note:  The kind and access attributes added here
      // to each row are NOT used by this plugin's base UI. They are only
      // needed by the companion enhanced UI plugin, which includes
      // Javascript that uses these values to decide what menu items
      // are active. If that plugin is not installed on the view, or if
      // the browser does not have Javascript enabled, these attributes
      // are unused.
      $form[$pluginId][$rowIndex] = [
        '#type'            => 'checkbox',
        '#title'           => $this->t('Select this row'),
        '#title_display'   => 'invisible',
        '#default_value'   => ($rowSelections[$rowIndex] === TRUE) ? 1 : 0,
        '#return_value'    => $entity->id(),
        '#attributes'      => [
          $data . 'kind'   => rawurlencode($entity->getKind()),
          $data . 'access' => rawurlencode(json_encode($access)),
        ],
      ];
    }

    //
    // Create UI
    // ---------
    // The UI is wrapped in a container annotated with parent attributes.
    $uiClass     = $pluginId . '-ui';
    $menuClass   = $pluginId . '-command-select';
    $submitClass = $pluginId . '-command-submit';

    $commands = Settings::getAllowedCommandDefinitions();
    $commandNames = [];
    foreach ($commands as $id => $def) {
      $commandNames[$id] = $def['label'];
    }

    $form[$uiClass] = [
      '#type'              => 'container',
      '#weight'            => -100,
      '#attributes'        => [
        'class'            => [$uiClass],
      ],

      // Add basic menu of commands.
      $menuClass           => [
        '#type'            => 'select',
        '#title'           => t('Commands'),
        '#options'         => $commandNames,
        '#attributes'      => [
          'class'          => [$menuClass],
        ],
      ],

      // Copy parent class's actions button group (including the
      // submit button) up into this form.
      $submitClass         => $form['actions'],
    ];

    // Update the submit button title, and remove the original submit button
    // at the bottom of the page.
    $form[$uiClass][$submitClass]['submit']['#value'] = t('Apply');
    unset($form['actions']);
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormValidate(&$form, FormStateInterface $formState) {
    //
    // Get command
    // -----------
    // The command's plugin ID is set in the command field. Get it and
    // the command definition.
    $pluginId      = $this->options['id'];
    $menuClass     = $pluginId . '-command-select';
    $rowSelections = $formState->getValue($pluginId);
    $commandId     = $formState->getValue($menuClass);

    if (empty($commandId) === TRUE) {
      $formState->setErrorByName(
        $menuClass,
        $this->t('Please select a command from the menu.'));
      return;
    }

    //
    // Create configuration
    // --------------------
    // Create an initial command configuration.
    $configuration = [
      'parentId'      => $this->getParentId(),
      'destinationId' => (-1),
      'selectionIds'  => array_filter($rowSelections),
    ];

    //
    // Prevalidate
    // -----------
    // Create a command instance and pre-validate. On an error,
    // there is no command and we've been given an error message.
    $command = $this->prevalidateCommand($commandId, $configuration);
    if (is_string($command) === TRUE) {
      $formState->setErrorByName($menuClass, $command);
      return;
    }

    $this->command = $command;
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $formState) {

    // If there is no command, previous validation failed.
    if ($this->command === NULL) {
      return;
    }

    //
    // Redirect to page
    // ----------------
    // Some commands redirect to a separate form page.
    if ($this->command->hasRedirect() === TRUE) {
      $this->command->setRedirect($formState);
      $this->command = NULL;
      return;
    }

    //
    // Redirect to form
    // ----------------
    // Some commands require a form to prompt for additional configuration
    // values. Host the form in a new page. Encode the command and
    // configuration in a URL parameter.
    if ($this->command->hasConfigurationForm() === TRUE) {
      $encoded = $this->encodeParameters(
        $this->command->getPluginId(),
        $this->command->getConfiguration(),
        \Drupal::request()->getRequestUri());

      $formState->setRedirect(
        'entity.foldersharecommand.plugin',
        ['encoded' => $encoded]);
      $this->command = NULL;
      return;
    }

    //
    // Execute
    // -------
    // Finish validating, check permissions, then execute.
    if ($this->validateCommand($this->command) === FALSE) {
      $this->command = NULL;
      return;
    }

    if ($this->validateCommandAccess($this->command) === FALSE) {
      $this->command = NULL;
      return;
    }

    $this->executeCommand($this->command);
    $this->command = NULL;
  }

  /*--------------------------------------------------------------------
   *
   * Utilities.
   *
   * These functions provide miscellaneous features to assist in the
   * code above.
   *
   *--------------------------------------------------------------------*/

  /**
   * Encodes plugin information to pass to a form via its URL.
   *
   * @param string $pluginId
   *   The ID of the command plugin that is in progress.
   * @param array $configuration
   *   The current plugin confirmation that needs to be passed on to
   *   the receiving page.
   * @param string $url
   *   The URL of the page to return to after the plugin's form is handled.
   *
   * @return string
   *   Returns the base64 encoded string suitable for inclusion as a parameter
   *   on a URL.
   */
  private function encodeParameters(
    string $pluginId,
    array $configuration,
    string $url) {
    //
    // Encoded parameters must include a structured set of data, including
    // the plugin ID, return URL, and a nested configuration that includes
    // file and folder IDs, etc. We could save this into a temp store, or
    // pass it on the URL. The temp store approach clutters up a file system
    // with little junk files of parameters. The URL approach requires that
    // we encode the data into something easily passed as one or more URL
    // parameters.
    //
    // Here we build a parameter array then use JSON encoding to turn the
    // array into a string, and then use base64 encoding to turn that string
    // into something that can be easily included in a URL.
    $parameters = [
      'pluginId'      => $pluginId,
      'configuration' => $configuration,
      'url'           => $url,
    ];

    return base64_encode(json_encode($parameters));
  }

  /*--------------------------------------------------------------------
   *
   * Command handling.
   *
   *--------------------------------------------------------------------*/

  /**
   * Creates an instance of a command and pre-validates it.
   *
   * @param string $commandId
   *   The command plugin ID.
   * @param array $configuration
   *   The initial command configuration.
   *
   * @return string|\Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface
   *   Returns an initialized and pre-validated command, or an error
   *   message if the command is not recognized or cannot be validated.
   */
  private function prevalidateCommand(
    string $commandId,
    array $configuration) {

    $container = \Drupal::getContainer();
    $manager = $container->get('foldershare.plugin.manager.foldersharecommand');
    if ($manager === NULL) {
      return t('Missing command plugin manager');
    }

    // Get the command definition.
    $commandDef = $manager->getDefinition($commandId);
    if ($commandDef === NULL) {
      return t(
        'Unrecognized command ID "@id".',
        [
          '@id' => $commandId,
        ]);
    }

    // Create a command instance.
    $command = $manager->createInstance($commandId, $configuration);

    // Validate with parent constraints.
    try {
      $command->validateParentConstraints();
    }
    catch (\Exception $e) {
      // There are only two errors possible:
      // - Bad parent ID.
      // - Bad parent type.
      //
      // The parent ID comes from the view argument and could be invalid.
      //
      // The parent type comes from the parent, and it may be inappropriate
      // for the command.
      return t(
          'The "@command" command is not available here. @message',
          [
            '@command' => $commandDef['label'],
            '@message' => $e->getMessage(),
          ]);
    }

    // Validate the selection constraints.
    try {
      $command->validateSelectionConstraints();
    }
    catch (\Exception $e) {
      // There are a variety of errors possible:
      // - Selection provided for a command that doesn't want one.
      // - No selection provided for a command that does want one.
      // - Selection has too many items.
      // - Selection has the wrong type of items.
      return $e->getMessage();
    }

    return $command;
  }

  /**
   * Validates a command and reports errors.
   *
   * The command is validated with its current configuration. On an error,
   * a message is posted to drupal_set_message().
   *
   * @param \Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface $command
   *   The command to validate. The configuration should already be set.
   *
   * @return bool
   *   Returns FALSE on failure, TRUE on success.
   */
  private function validateCommand(FolderShareCommandInterface $command) {
    try {
      $command->validateConfiguration();
      return TRUE;
    }
    catch (\Exception $e) {
      drupal_set_message($e->getMessage(), 'error');
      return FALSE;
    }
  }

  /**
   * Validates a command's access permissions and reports errors.
   *
   * Access permissions are checked for the command. On an error,
   * a message is posted to drupal_set_message().
   *
   * @param \Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface $command
   *   The command to validate. The configuration should already be set.
   *
   * @return bool
   *   Returns FALSE on failure, TRUE on success.
   */
  private function validateCommandAccess(FolderShareCommandInterface $command) {
    $user = \Drupal::currentUser();
    if ($command->access($user, FALSE) === FALSE) {
      $label = $command->getPluginDefinition()['label'];
      drupal_set_message(t(
        'You do not have sufficient permissions to perform the "@command" command on the selected items.',
        [
          '@command' => $label,
        ]),
        'error');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Executes a command using the current configuration.
   *
   * @param \Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface $command
   *   The command to execute. The configuration should already be set.
   *
   * @return bool
   *   Returns FALSE on failure, TRUE on success.
   */
  private function executeCommand(FolderShareCommandInterface $command) {
    try {
      // Execute!
      $command->clearExecuteMessages();
      $command->execute();

      // Get its messages, if any, and transfer them to the page.
      foreach ($command->getExecuteMessages() as $type => $list) {
        switch ($type) {
          case 'error':
          case 'warning':
          case 'status':
            break;

          default:
            $type = 'status';
            break;
        }

        foreach ($list as $message) {
          drupal_set_message($message, $type);
        }
      }

      return TRUE;
    }
    catch (\Exception $e) {
      drupal_set_message($e->getMessage(), 'error');
      return FALSE;
    }
  }

}
