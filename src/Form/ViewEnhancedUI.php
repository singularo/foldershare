<?php

namespace Drupal\foldershare\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\File;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Url;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Entity\FolderShareAccessControlHandler;
use Drupal\foldershare\Plugin\FolderShareCommandManager;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface;
use Drupal\foldershare\Ajax\OpenErrorDialogCommand;
use Drupal\foldershare\Ajax\FormDialog;
use Drupal\foldershare\Form\EditCommand;

/**
 * Creates a form for the enhanced user interface associated with a view.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * The form includes hidden elements for a hierarchical menu, menu button,
 * support fields, and a submit button. All of these are controlled by an
 * attached Javascript that uses jQuery to create a nice menu and button,
 * and trigger form submits.
 *
 * This form *requires* that a view nearby on the page contain a base UI
 * view field plugin that adds selection checkboxes and entity attributes
 * to all rows in the view.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Plugin\views\field\FolderShareBaseUI
 */
class ViewEnhancedUI extends FormBase {

  use RedirectDestinationTrait;

  /*--------------------------------------------------------------------
   *
   * Constants.
   *
   *--------------------------------------------------------------------*/

  /**
   * Indicate whether to enable AJAX support in the form.
   *
   * @var bool
   */
  const ENABLE_AJAX = FALSE;

  /**
   * The maximum number of commands in a group before creating a submenu.
   *
   * @var int
   */
  const MENU_MAX_BEFORE_SUBMENU = 3;

  /**
   * Hide commands unavailable for the current page and/or user.
   *
   * @var bool
   */
  const MENU_HIDE_UNAVAILABLE = TRUE;

  /**
   * The names of well-known command groups, in menu order.
   *
   * The group name order here reflects the preferred ordering of command
   * groups in the Javascript user interface. Groups that have no commands
   * are skipped in the user interface.
   *
   * @var string[]
   */
  static private $MENU_WELL_KNOWN_COMMAND_GROUPS = [
    'new',
    'import',
    'export',
    'edit',
    'delete',
    'copy',
    'settings',
    'archive',
    'administer',
  ];

  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   *--------------------------------------------------------------------*/

  /**
   * The plugin command manager.
   *
   * @var \Drupal\foldershare\FolderShareCommand\FolderShareCommandManager
   */
  protected $commandPluginManager;

  /**
   * The current validated command prior to its execution.
   *
   * The command already has its configuration set. It has been
   * validated, but has not yet had access controls checked and it
   * has not been executed.
   *
   * @var \Drupal\foldershare\FolderShareCommand\FolderShareCommandInterface
   */
  protected $command;

  /*--------------------------------------------------------------------
   *
   * Construction.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a new form.
   *
   * @param \Drupal\foldershare\FolderShareCommand\FolderShareCommandManager $pm
   *   The command plugin manager.
   */
  public function __construct(FolderShareCommandManager $pm) {
    $this->commandPluginManager = $pm;
    $this->command = NULL;
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
    return mb_convert_case(str_replace('\\', '_', get_class($this)), MB_CASE_LOWER);
  }

  /*--------------------------------------------------------------------
   *
   * Form build.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $formState = NULL) {

    //
    // Get context attributes
    // ----------------------
    // Get attributes of the current context, including:
    // - the ID of the parent entity, if any
    // - the parent's kind.
    // - the user's access permissions.
    //
    // The parent entity ID comes from the form build arguments.
    //
    $args = $formState->getBuildInfo()['args'];

    if (empty($args) === TRUE || (int) $args[0] === (-1)) {
      // There is no parent entity ID in the build arguments, or that
      // ID is a (-1). This indicates this form is being built for
      // a root folder list where there is no parent entity.
      $parentId = (int) (-1);
      $parent   = NULL;
      $kind     = 'none';
      $perm     = FolderShareAccessControlHandler::getAccessSummary(NULL);
    }
    else {
      // There is a parent entity ID in the build arguments. Load that
      // entity. If loading the entity fails, revert to the root folder
      // list case.
      $parentId = (int) $args[0];
      $parent   = FolderShare::load($parentId);
      if ($parent !== NULL) {
        $kind = $parent->getKind();
      }
      else {
        $kind = 'none';
      }

      $perm = FolderShareAccessControlHandler::getAccessSummary($parent);
    }

    // Create an array that lists the names of access control operators
    // (e.g. "view", "update", "delete") if the user has permission for
    // those operators on this parent entity (if there is one).
    $access = [];
    foreach ($perm as $op => $allowed) {
      if ($allowed === TRUE) {
        $access[] = $op;
      }
    }

    //
    // Form setup
    // ----------
    // If AJAX is enabled, add a flag to Drupal settings for use by the
    // attached Javascript.
    $formId = $this->getFormId();
    $form['#attributes']['class'][] = $formId;

    $form['#attached']['drupalSettings'][$formId] = [
      'formAjaxEnabled' => self::ENABLE_AJAX,
    ];

    // Set up classes.
    $uiClass        = $formId . '-ui';
    $submitClass    = $formId . '-command-submit';
    $uploadClass    = $formId . '-command-upload';
    $buttonClass    = $formId . '-command-menu-button';
    $menuClass      = $formId . '-command-menu';
    $commandClass   = $formId . '-command';
    $selectionClass = $formId . '-command-selection';
    $data           = 'data-' . $formId . '-';

    //
    // Create UI
    // ---------
    // The UI is wrapped in a container annotated with parent attributes.
    // Children of the container include a menu, menu button, submit button,
    // and a file field. Everything is hidden initially, and only selectively
    // exposed by Javascript, if the browser supports Javascript.
    $form[$uiClass] = [
      '#type'              => 'container',
      '#weight'            => -100,
      '#attributes'        => [
        'class'            => [
          $uiClass,
          'hidden',
        ],
        $data . 'parentid' => $parentId,
        $data . 'kind'     => rawurlencode($kind),
        $data . 'access'   => rawurlencode(json_encode($access)),
      ],

      // Add a hierarchical menu of commands. Javascript uses jQuery.ui
      // to build a menu from this and presents it from a menu button.
      // The menu was built and marked as hidden.
      //
      // Implementation note: The field is built using an inline template
      // to avoid Drupal's HTML cleaning that can remove classes and
      // attributes on the menu items, which we need to retain as a
      // description of the commands. That description is used by Javascript
      // to enable/disable menu items based upon what the corresponding
      // command requires from the selection, parent entity, etc.
      $menuClass           => [
        '#type'            => 'inline_template',
        '#template'        => '{{ menu|raw }}',
        '#context'         => [
          'menu'           => $this->buildMenu($access),
        ],
      ],

      // Add a button to trigger the menu. Javascript binds a behavior
      // to the button to make it pop up the menu. Mark the field as
      // hidden initially.
      //
      // Implementation note: The field is built using an inline template
      // so that we get a <button>. If we used the '#type' 'button',
      // Drupal instead creates an <input>. Since we specifically want a
      // button so that jQuery.button() will button-ize it, we have to
      // bypass Drupal.
      $buttonClass         => [
        '#type'            => 'inline_template',
        '#template'        => '{{ button|raw }}',
        '#context'         => [
          'button'         => '<button type="button" class="hidden ' .
            $buttonClass . '"><span>' . t('Menu') . '</span></button>',
        ],
      ],

      // Add fields that are always hidden.
      'hiddenGroup'       => [
        '#type'            => 'container',
        '#attributes'      => [
          'class'          => ['hidden'],
        ],

        // Add a hidden file field for uploading files. Later, when a user
        // selects a command that needs to upload a file, Javascript invokes
        // the browser's file dialog to set this field.
        //
        // The field needs to have a processing callback to set up file
        // extension filtering, if file extension limitations are enabled
        // for the module.
        //
        // Implementation note: The 'file' element type does not recognize
        // '#attributes' and class. Instead we have to wrap the element with
        // a gratuitous <div> with class 'hidden' to hide it.
        $uploadClass         => [
          '#type'            => 'file',
          '#multiple'        => TRUE,
          '#process'         => [
            [
              get_class($this),
              'processFileField',
            ],
          ],
        ],

        // Add a hidden command ID field to indicate the selected command.
        // Later, when a user selects a command, Javascript sets this field
        // to the command plugin's unique ID.
        //
        // Implementation note: There is no documented maximum plugin ID
        // length, but most Drupal IDs are limited to 256 characters. So
        // we use that limit.
        $commandClass        => [
          '#type'            => 'textfield',
          '#maxlength'       => 256,
          '#size'            => 1,
          '#default_value'   => '',
        ],

        // Add a hidden selection field that lists the IDs of selected rows.
        // Later, when a user selects a command, Javascript sets this field
        // to a list of entity IDs for zero or more items currently selected
        // from the view.
        //
        // Implementation note: The textfield will be set with a JSON-encoded
        // string containing the list of numeric entity IDs for selected
        // entities in the view. There is no maximum view length if the site
        // disables paging. Drupal's default field maximum is 128 characters,
        // which is probably sufficient, but it is conceivable it could be
        // exceeded for a large selection. The HTML default maximum is
        // 524288 characters, so we use that.
        $selectionClass      => [
          '#type'            => 'textfield',
          '#maxlength'       => 524288,
          '#size'            => 1,
          '#default_value'   => $formState->getValue($selectionClass),
        ],

        // Add a hidden submit button for the form. Javascript triggers the
        // submit when a command is selected from the menu.
        //
        // Implementation quirk: The 'submit' form element creates an
        // <input> button and adds the class 'button'. If we use '#attributes'
        // to set the class and add 'hidden', the 'button' class occurs
        // second and overrides whatever 'hidden' sets. Since 'hidden'
        // says display:none, and 'button' says display:inline-block,
        // the button becomes visible despite being marked hidden. To
        // hide the button once-and-for-all, we have to wrap it with a
        // gratuitous <div> marked as hidden.
        $submitClass         => [
          '#type'            => 'submit',
          '#value'           => '',
          '#name'            => $submitClass,
        ],
      ],
    ];

    // When AJAX is enabled, add an AJAX callback to the submit button.
    if (self::ENABLE_AJAX === TRUE) {
      $form[$uiClass]['hiddenGroup'][$submitClass]['#ajax'] = [
        'callback'   => '::submitFormAjax',
        'event'      => 'submit',
        'trigger_as' => $submitClass,
      ];
    }

    return $form;
  }

  /**
   * Process the file field in the view UI form to add extension handling.
   *
   * The 'file' field directs the browser to prompt the user for one or
   * more files to upload. This prompt is done using the browser's own
   * file dialog. When this module's list of allowed file extensions has
   * been set, and this function is added as a processing function for
   * the 'file' field, it adds the extensions to the list of allowed
   * values used by the browser's file dialog.
   *
   * @param mixed $element
   *   The form element to process.
   * @param Drupal\Core\Form\FormStateInterface $formState
   *   The current form state.
   * @param mixed $completeForm
   *   The full form.
   */
  public static function processFileField(
    &$element,
    FormStateInterface $formState,
    &$completeForm) {

    // Let the file field handle the '#multiple' flag, etc.
    File::processFile($element, $formState, $completeForm);

    // Get the list of allowed file extensions for FolderShare files.
    $extensions = FolderShare::getFileAllowedExtensions();

    // If there are extensions, add them to the form element.
    if (empty($extensions) === FALSE) {
      // The extensions list is space separated without leading dots. But
      // we need comma separated with dots. Map one to the other.
      $list = [];
      foreach (mb_split(' ', $extensions) as $ext) {
        $list[] = '.' . $ext;
      }

      $element['#attributes']['accept'] = implode(',', $list);
    }

    return $element;
  }

  /**
   * Builds the HTML for a hierarchical command menu.
   *
   * Module settings enable the site admin to select which commands are
   * included in the command menu. These commands are sorted into groups,
   * the groups are ordered into a well-known order, and the groups
   * used to generiate a hierarchical list using <ul> and <li>. The
   * individual menu commands are annotated with attributes of the command
   * and the user's access permissions for that command. Javascript on
   * the page uses these to enable/disable commands based upon the current
   * selection context.
   *
   * @param string[] $access
   *   The $access array lists access control operators (e.g. "update")
   *   that the user has permission to perform on the current parent.
   *
   * @return string
   *   Returns HTML for a hierarchical menu of commands.
   */
  private function buildMenu(array $access) {

    //
    // Get menu items
    // ---------------
    // Get the list of available menu commands from module settings (based
    // on the site admin's choices). Sort these into categories.
    $categories = [];
    foreach (Settings::getAllowedCommandDefinitions() as $def) {
      $cat = $def['category'];
      if (isset($categories[$cat]) === TRUE) {
        $categories[$cat][] = $def;
      }
      else {
        $categories[$cat] = [$def];
      }
    }

    // Order the categories based upon a set of well-known categories
    // and their order.
    $ordered = [];
    foreach (self::$MENU_WELL_KNOWN_COMMAND_GROUPS as $cat) {
      if (empty($categories[$cat]) === FALSE) {
        $ordered[$cat] = &$categories[$cat];
        unset($categories[$cat]);
      }
    }

    // Sort the remaining non-well-known categories by name and
    // append them to the ordered category list.
    if (empty($categories) === FALSE) {
      asort($categories);
      $ordered = array_merge($ordered, $categories);
    }

    unset($categories);

    //
    // Filter menu items
    // -----------------
    // Sweep through all of the menu items and check if they are available
    // in the current context (i.e. with the current parent and for the
    // current user). Menu items that are not available are dropped.
    if (self::MENU_HIDE_UNAVAILABLE === TRUE) {
      $culled = [];
      foreach ($ordered as $groupName => $group) {
        $culled[$groupName] = [];
        foreach ($group as $def) {
          // Get the parent access required from the parent constraints.
          $parentConstraints = (array) $def['parentConstraints'];
          $parentAccessRequired = $parentConstraints['access'];

          // Get the selection access required from the selection constraints.
          $selectionConstraints = (array) $def['selectionConstraints'];
          $selectionAccessRequired = $selectionConstraints['access'];

          // Get special handling access required.
          $specialHandling = (array) $def['specialHandling'];

          // Check if that access is possible for the current user
          // and parent entity.
          $canAccessParent = $parentAccessRequired === 'none' ||
            in_array($parentAccessRequired, $access) === TRUE;
          $canAccessSelection = $selectionAccessRequired === 'none' ||
            in_array($selectionAccessRequired, $access) === TRUE;
          $canAccessSpecial = TRUE;

          if (in_array('create', $specialHandling) === TRUE &&
              in_array('create', $access) === FALSE) {
              $canAccessSpecial = FALSE;
          }

          if (in_array('createroot', $specialHandling) === TRUE &&
              in_array('createroot', $access) === FALSE) {
              $canAccessSpecial = FALSE;
          }

          if ($canAccessParent === TRUE && $canAccessSelection === TRUE &&
              $canAccessSpecial === TRUE) {
            // The user has sufficient access.
            $culled[$groupName][] = $def;
          }
        }

        // If the group is now empty, remove the group.
        if (count($culled[$groupName]) === 0) {
          unset($culled[$groupName]);
        }
      }

      $ordered = $culled;
    }

    //
    // Build menu
    // ----------
    // Create a menu using <ul><li>...</li></ul> for later use by
    // Javascript and jQuery.ui to create a hierarchical menu.
    // Mark each menu item with the associated command's plugin ID.
    // Mark the menu has initially hidden.
    $formId = $this->getFormId();
    $addSeparator = FALSE;

    // Start the menu.
    $menuHtml = '<ul class="hidden ' . $formId . '-command-menu">';

    // Add the menu items.
    foreach ($ordered as $groupName => &$group) {
      // Add a separator before the next group of commands.
      if ($addSeparator === TRUE) {
        $menuHtml .= '<li class="' . $formId . '-command-menu-item">-</li>';
      }

      $addSeparator = TRUE;

      // Create a submenu if the group is large enough.
      $addSubmenu = FALSE;
      if (count($group) > self::MENU_MAX_BEFORE_SUBMENU) {
        // Use the group name, converted to title-case and translated
        // to the user's language, as the name of the submenu.
        $groupTitle = t(mb_convert_case($groupName, MB_CASE_TITLE));
        $menuHtml .= '<li><div>' . $groupTitle . '</div><ul>';
        $addSubmenu = TRUE;
      }

      // Add the group's menu items.
      foreach ($group as $def) {
        $menuHtml .= $this->buildMenuItem($def);
      }

      if ($addSubmenu === TRUE) {
        $menuHtml .= '</ul></li>';
      }
    }

    // Finish the menu.
    $menuHtml .= '</ul>';

    return $menuHtml;
  }

  /**
   * Builds the HTML for a single menu item.
   *
   * @param string[] $def
   *   The command definition.
   *
   * @return string
   *   Returns HTML for a single <li>...</li> menu item for the command.
   */
  private function buildMenuItem(array $def) {
    // Get command definition values.
    $id                   = rawurlencode($def['id']);
    $label                = $def['label'];
    $menuBase             = rawurlencode($def['label']);
    $menuOperand          = rawurlencode($def['menu']);
    $parentConstraints    = (array) $def['parentConstraints'];
    $selectionConstraints = (array) $def['selectionConstraints'];
    $specialHandling      = (array) $def['specialHandling'];

    // Encode constraints and special handling so that we can include
    // them as menu item attributes without causing problems with
    // HTML syntax.
    $parentConstraints    = rawurlencode(json_encode($parentConstraints));
    $selectionConstraints = rawurlencode(json_encode($selectionConstraints));
    $specialHandling      = rawurlencode(json_encode($specialHandling));

    // Start the menu item.
    $formId = $this->getFormId();
    $itemHtml = '<li class="' . $formId . '-command-menu-item" ';

    // Add attributes.
    $itemHtml .= 'data-' . $formId . '-command="' . $id . '" ';
    $itemHtml .= 'data-' . $formId . '-menu-base="' . $menuBase . '" ';
    $itemHtml .= 'data-' . $formId . '-menu-operand="' . $menuOperand . '" ';
    $itemHtml .= 'data-' . $formId . '-parent-constraints="' . $parentConstraints . '" ';
    $itemHtml .= 'data-' . $formId . '-selection-constraints="' . $selectionConstraints . '" ';
    $itemHtml .= 'data-' . $formId . '-special-handling="' . $specialHandling . '" ';
    $itemHtml .= '>';

    // Add the menu base text. This will be replaced by Javascript
    // based upon the current selection, but it defaults to the base
    // menu item text (e.g. "Delete").
    $itemHtml .= '<div>' . $label . '</div>';

    // Finish the menu item.
    $itemHtml .= '</li>';

    return $itemHtml;
  }

  /*--------------------------------------------------------------------
   *
   * Form validate
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $formState) {
    //
    // Setup
    // -----
    // Set up classes.
    $formId         = $this->getFormId();
    $commandClass   = $formId . '-command';
    $selectionClass = $formId . '-command-selection';
    $buttonClass    = $formId . '-command-menu-button';
    $uploadClass    = $formId . '-command-upload';

    // Get parent entity ID, if any.
    $args = $formState->getBuildInfo()['args'];
    if (empty($args) === TRUE) {
      // No parent entity. Default to showing a root folder list.
      $parentId = (int) (-1);
    }
    else {
      // Parent entity ID should be the sole argument. Load it.
      // Loading could fail and return a NULL if the ID is bad.
      $parentId = (int) $args[0];
    }

    //
    // Get command from form
    // ---------------------
    // The command's plugin ID is set in the command field. Get it and
    // the command definition.
    $commandId = $formState->getValue($commandClass);
    if (empty($commandId) === TRUE) {
      // This should never happen since the form cannot be submitted except
      // by Javascript, and that script should always set the command. If it
      // did not set the command, something is wrong.
      $formState->setErrorByName(
        $buttonClass,
        $this->t('Please select a command from the menu.'));
      return;
    }

    //
    // Create configuration
    // --------------------
    // Create an initial command configuration.
    $configuration = [
      'parentId'      => $parentId,
      'destinationId' => (-1),
      'selectionIds'  => json_decode($formState->getValue($selectionClass), TRUE),
      'uploadClass'   => $uploadClass,
    ];

    //
    // Prevalidate
    // -----------
    // Create a command instance and pre-validate.
    $command = $this->prevalidateCommand($commandId, $configuration);
    if (is_string($command) === TRUE) {
      $formState->setErrorByName($buttonClass, $command);
      return;
    }

    $this->command = $command;
  }

  /*--------------------------------------------------------------------
   *
   * Form submit (no-AJAX)
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState) {
    //
    // Setup
    // -----
    // If AJAX is in use, let the AJAX callback handle the command.
    // It is automatically called after calling this function.
    if (self::ENABLE_AJAX === TRUE) {
      return;
    }

    // If there is no command, previous validation failed. Validation
    // errors should already have been reported.
    if ($this->command === NULL) {
      return;
    }

    //
    // Redirect to page
    // ----------------
    // If the command needs to redirect to a special page (such as a form),
    // go there.
    if ($this->command->hasRedirect() === TRUE) {
      $url = $this->command->getRedirect();
      if (empty($url) === TRUE) {
        $url = Url::fromRoute('<current>');
      }

      $formState->setRedirectUrl($url);
      return;
    }

    //
    // Redirect to form
    // ----------------
    // If the command needs a configuration form, redirect to a page that
    // hosts the form.
    if ($this->command->hasConfigurationForm() === TRUE) {
      $parameters = [
        'pluginId'      => $this->command->getPluginId(),
        'configuration' => $this->command->getConfiguration(),
        'url'           => \Drupal::request()->getRequestUri(),
      ];

      $encoded = base64_encode(json_encode($parameters));

      $formState->setRedirect(
        'entity.foldersharecommand.plugin',
        ['encoded' => $encoded]);
      return;
    }

    //
    // Execute
    // -------
    // The command doesn't need more operands. Validate, check permissions,
    // then execute. Failures either set form element errors or set page
    // errors with drupal_set_message().
    if ($this->validateCommand($this->command) === TRUE ||
        $this->validateCommandAccess($this->command) === TRUE) {
      $this->executeCommand($this->command);
    }

    $this->command = NULL;
  }

  /*--------------------------------------------------------------------
   *
   * Form submit (AJAX)
   *
   *--------------------------------------------------------------------*/

  /**
   * Handles form submission via AJAX.
   *
   * @param array $form
   *   An array of form elements.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The input values for the form's elements.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Returns an AJAX response.
   */
  public function submitFormAjax(array &$form, FormStateInterface $formState) {

    //
    // Report form errors
    // ------------------
    // If prevalidation failed, there will be form errors to report.
    if ($formState->hasAnyErrors() === TRUE) {
      $response = new AjaxResponse();

      $dialog = new OpenErrorDialogCommand(t('Errors'));
      $dialog->setFromFormErrors($formState);
      $response->addCommand($dialog);

      return $response;
    }

    //
    // Redirect to page
    // ----------------
    // If the command needs to redirect to a special page (such as a form),
    // go there.
    if ($this->command->hasRedirect() === TRUE) {
      $url = $this->command->getRedirect();
      if (empty($url) === TRUE) {
        // For some reason, the command claimed it has a redirect, but then
        // it didn't provide a redirect URL. So, default to redirecting
        // back to the current page.
        $url = Url::fromRoute('<current>');
      }

      // Return a redirect response.
      $response = new AjaxResponse();
      $response->addCommand(new RedirectCommand($url->toString()));
      return $response;
    }

    //
    // Embed a form in a dialog
    // ------------------------
    // If the command needs a configuration form, add that form to a dialog.
    if ($this->command->hasConfigurationForm() === TRUE) {
      $parameters = [
        'pluginId'      => $this->command->getPluginId(),
        'configuration' => $this->command->getConfiguration(),
        'url'           => \Drupal::request()->getRequestUri(),
        'enableAjax'    => self::ENABLE_AJAX,
      ];
      $encoded = base64_encode(json_encode($parameters));

      // Build a form for the command.
      $form = \Drupal::formBuilder()->getForm(
        EditCommand::class,
        $encoded);

      $form['#attached']['library'][] = Constants::LIBRARY_ENHANCEDUI;

      $response = new AjaxResponse();
      $response->setAttachments($form['#attached']);

      $title = $this->command->getPluginDefinition()['label'];
      $dialog = new FormDialog(
        $title,
        $form,
        [
          'width' => '75%',
        ]);
      $response->addCommand($dialog);

      return $response;
    }

    //
    // Execute
    // -------
    // The command doesn't need more operands. Validate, check permissions,
    // then execute. Failures either set form element errors or set page
    // errors with drupal_set_message(). Pull out those errors and report them.
    if ($this->validateCommand($this->command) === FALSE ||
        $this->validateCommandAccess($this->command) === FALSE) {
      $this->command = NULL;

      $response = new AjaxResponse();

      if ($formState->hasAnyErrors() === TRUE) {
        $cmd = new OpenErrorDialogCommand(t('Errors'));
        $cmd->setFromFormErrors($formState);
        $response->addCommand($cmd);
        return $response;
      }

      if (drupal_set_message() !== NULL) {
        $cmd = new OpenErrorDialogCommand(t('Errors'));
        $cmd->setFromPageMessages();
        $response->addCommand($cmd);
        return $response;
      }

      // Otherwise validation or access checks failed, but didn't
      // report errors? Unlikely.
      $cmd = new OpenErrorDialogCommand(t('Confused!'), t('Unknown failure!'));
      $response->addCommand($cmd);
      return $response;
    }

    $this->executeCommand($this->command);
    $this->command = NULL;

    // If there were any error or warning messages, report them.
    $msgs      = drupal_get_messages(NULL, FALSE);
    $nErrors   = count($msgs['error']);
    $nWarnings = count($msgs['warning']);
    $nStatus   = count($msgs['status']);
    if (($nErrors + $nWarnings + $nStatus) !== 0) {
      $response = new AjaxResponse();
      $cmd = new OpenErrorDialogCommand(t('Notice'));
      $cmd->setFromPageMessages();
      $response->addCommand($cmd);
      return $response;
    }

    // Otherwise the command executed and did not provide any error,
    // warning, or status messages. Just refresh the page.
    $response = new AjaxResponse();
    $url = Url::fromRoute('<current>');
    $response->addCommand(new RedirectCommand($url->toString()));
    return $response;
  }

  /*--------------------------------------------------------------------
   *
   * Command handling
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
  protected function prevalidateCommand(
    string $commandId,
    array $configuration) {

    if ($this->commandPluginManager === NULL) {
      return (string) t('Missing command plugin manager');
    }

    // Get the command definition.
    $commandDef = $this->commandPluginManager->getDefinition($commandId, FALSE);
    if ($commandDef === NULL) {
      return (string) t(
        'Unrecognized command ID "@id".',
        [
          '@id' => $commandId,
        ]);
    }

    // Create a command instance.
    $command = $this->commandPluginManager->createInstance(
      $commandDef['id'],
      $configuration);

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
      return (string) t(
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
  protected function validateCommand(FolderShareCommandInterface $command) {
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
   * @param \Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface $command
   *   The command to validate. The configuration should already be set.
   *
   * @return bool
   *   Returns FALSE on failure, TRUE on success.
   */
  protected function validateCommandAccess(FolderShareCommandInterface $command) {
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
   * Executes a command and reports errors.
   *
   * @param \Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface $command
   *   The command to execute. The configuration should already be set.
   *
   * @return bool
   *   Returns FALSE on failure, TRUE on success.
   */
  protected function executeCommand(FolderShareCommandInterface $command) {
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
