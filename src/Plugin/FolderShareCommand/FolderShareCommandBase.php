<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Plugin\ConfigurablePluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Access\AccessResult;

use Drupal\foldershare\Utilities;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Entity\Exception\ValidationException;

/**
 * Defines the base class for plugins for shared folder commands.
 *
 * A command performs an operation within the context of a parent folder
 * or list of folders, and operates upon a selection of FolderShare objects.
 * Typical operations are to create, delete, edit, rename, copy, move,
 * share, or change the ownership of items.
 *
 * A command implementation always includes three parts:
 *
 * - Validation that checks if a given configuration of parameters is
 *   consistent and valid for the command.
 *
 * - Access checks that confirm the user has permission to do the command.
 *
 * - Execution that performs the command.
 *
 * All commands use a configuration containing named parameters used as
 * operands for the command. These parameters may include the ID of the
 * parent folder, the IDs of operand files and folders, and so forth.
 *
 * Some commands may provide an optional form where a user may enter
 * additional parameters. A "folder rename" command, for instance, may
 * offer a form for entering a new name. When used, the form is expected
 * to create a configuration that is then passed to validation, access,
 * and execution methods to do the operation.
 *
 * Callers may skip the command form and use their own mechanism to prompt
 * for configuration parameters. They may then call the command's validation,
 * access, and execution methods directly to do the operation.
 *
 * For example, here are several situations that might all use the same
 * command:
 *
 * - Direct use. Code may instantiate a command plugin, set the configuration,
 *   then call the plugin's validation, access, and execution methods directly
 *   to perform an operation. No command form or UI would be used.
 *
 * - REST use. Code that responds to REST commands may marshal arguments from
 *   the REST interface, then invoke the command as above for direct use.
 *
 * - Custom UI. Code that creates its own user interface, with or without
 *   Javascript, may prompt the user for parameters, the invoke the command
 *   as above for direct use. The command's own forms would not be used.
 *
 * - Form page UI. Drupal's convention of full-page forms may be used to
 *   host a plugin command's forms plus a mechanism to choose a parent
 *   folder context and select files and folders to operate upon (these
 *   are not normally part of a command's own forms). On a submit, the
 *   command maps the form's values to its configuration, then validates,
 *   access checks, and executes.
 *
 * - Embedded form UI. When an overlay/dialog can be used, the plugin
 *   command's forms may be embedded within the overlay. Any mechanism to
 *   select the parent folder context and select files and folders must be
 *   done before this overlay. On a submit of the overlay form, the
 *   command maps the form's values to its configuration, then validates,
 *   access checks, and executes.
 *
 * @ingroup foldershare
 */
abstract class FolderShareCommandBase extends PluginBase implements ConfigurablePluginInterface, FolderShareCommandInterface {

  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   * These fields provide state set during different stages of command
   * handling. The parent, destination, and selection are set as the
   * command is validatedfor a specific configuration, which is held
   * in the parent class. The validation flags are set during stages
   * of validation so that repeated calls to validate don't repeat
   * the associated work.
   *
   *--------------------------------------------------------------------*/

  /**
   * The loaded parent folder, if any.
   *
   * @var \Drupal\foldershare\FolderShareInterface
   */
  private $parent;

  /**
   * The loaded destination folder, if any.
   *
   * @var \Drupal\foldershare\FolderShareInterface
   */
  private $destination;

  /**
   * The loaded selection.
   *
   * The selection is an associative array where keys are kinds, and
   * values are arrays of FolderShare entities.
   *
   * @var \Drupal\foldershare\FolderShareInterface[]
   */
  private $selection;

  /**
   * The command's translated completion messages.
   *
   * The variable is an array indexed by message type ('status', 'warning',
   * or 'error') that stores translated messages set by a command while
   * it executes.
   *
   * Commands may issue multiple messages, which are accumulated in the
   * array in the order in which they are set.
   *
   * User interfaces may present messages by retrieving the array and
   * sending them to a web page, dialog, or command-line response.
   *
   * @var array
   * @see ::clearExecuteMessages
   * @see ::addExecuteMessage
   * @see ::getExecuteMessages
   */
  private $executeMessages;

  /**
   * A flag indicating if the entire command has been validated.
   *
   * @var bool
   */
  protected $validated;

  /**
   * A flag indicating if parent constraints have been validated.
   *
   * @var bool
   */
  protected $parentValidated;

  /**
   * A flag indicating if selection constraints have been validated.
   *
   * @var bool
   */
  protected $selectionValidated;

  /**
   * A flag indicating if destination constraints have been validated.
   *
   * @var bool
   */
  protected $destinationValidated;

  /**
   * A flag indicating if additional parameters have been validated.
   *
   * @var bool
   */
  protected $parametersValidated;

  /**
   * A flag indicating if access has been checked.
   *
   * @var bool
   */
  protected $accessible;

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   * These functions create an instance of a command. Every instance
   * has a definition that describes the command, and a configuration
   * that provides operands for the command. The definition never
   * changes, but the configuration may change as the command is
   * prepared for execution. Validation focuses on the configuration,
   * while the plugin manager has already insured that the command
   * definition is proper.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a new plugin with the given configuration.
   *
   * @param array $configuration
   *   The array of named parameters for the new command instance.
   * @param string $pluginId
   *   The ID for the new plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin's implementation definition.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition) {

    parent::__construct($configuration, $pluginId, $pluginDefinition);

    // Set the configuration, which adds in defaults and clears
    // cached values.
    $this->setConfiguration($configuration);
    $this->clearExecuteMessages();
  }

  /*--------------------------------------------------------------------
   *
   * Configuration.
   * (Implements ConfigurablePluginInterface)
   *
   * These functions handle the command's configuration, which is an
   * associative array of named operands for the command. All commands
   * support several well-known operands:
   *
   * - parentId: the entity ID of the parent item, such as a folder.
   *   The special value -1 flags that there is no parent.
   *
   * - destinationId: the entity ID of the destination item, such as a
   *   folder. The special value -1 flags that there is no destination.
   *
   * - selectionIds: an array of entity IDs of selected items, such as
   *   files and folders. All of these values should be non-negative
   *   valid entity IDs. A value of -1 is not allowed.
   *
   * When a command is created, an initial configuration is set by the
   * caller, combined with a default configuration. Thereafter the caller
   * may change the configuration. On any change, internal state is reset
   * to insure that the new configuration gets validated and used in
   * further calls to the command.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns a required default configuration.
   *
   * The related function, defaultConfiguration(), is intended to provide
   * a default initial configuration for the command. Subclasses may
   * override this to change the default. Unfortunately, they also may
   * override this and fail to provide a default.
   *
   * To insure that there is always a valid starting default configuration,
   * this function returns the required minimal default configuration.
   *
   * @return array
   *   Returns an associative array with keys and values set for a
   *   required minimal configuration.
   *
   * @see defaultConfiguration()
   */
  private function requiredDefaultConfiguration() {
    //
    // The required minimal default configuration has the well-known
    // common configuration keys and initial values for:
    // - no parent.
    // - no destination.
    // - no selection.
    return [
      'parentId'      => (-1),
      'destinationId' => (-1),
      'selectionIds'  => [],
      'uploadClass'   => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    //
    // Return a default fully-initialized configuration used during
    // construction of the command, or to reset it for repeated use.
    //
    // Subclasses may override this function to add further configuration
    // defaults.
    return $this->requiredDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    //
    // Return the current configuration, which is an associative array
    // with well-known and subclass-specific keys for command operands.
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    //
    // Set the configuration.
    //
    // To insure that the configuration always has minimal default settings,
    // the given configuration is merged with the command's defaults and
    // a minimal required set of defaults.
    $this->configuration = ($configuration + $this->defaultConfiguration());
    $this->configuration += $this->requiredDefaultConfiguration();

    // Clear cached values for the parent (if any), destination (if any),
    // and selection (if any).
    $this->parent      = NULL;
    $this->destination = NULL;
    $this->selection   = [];

    // Clear validation flags. The new configuration is not validated until
    // the validation functions are called explicitly, or until the
    // configuration is used.
    $this->validated            = FALSE;
    $this->parentValidated      = FALSE;
    $this->selectionValidated   = FALSE;
    $this->destinationValidated = FALSE;
    $this->parametersValidated  = FALSE;

    // Clear access flags. Access control checks are not done until the
    // access function is called explicitly, or until the configuration
    // is used.
    $this->accessible = FALSE;

    // If the configuration has a list of selected entity IDs, clean the
    // list by converting all values to integers and tossing anything
    // invalid. This makes later use of the list easier.
    $ids = $this->configuration['selectionIds'];
    foreach ($ids as $index => $id) {
      $iid = (int) $id;
      if ($iid >= 0) {
        $ids[$index] = $iid;
      }
    }

    $this->configuration['selectionIds'] = $ids;

    // If the configuration has parent or destination IDs, make sure
    // they are integers.
    $this->configuration['parentId']      = (int) $this->configuration['parentId'];
    $this->configuration['destinationId'] = (int) $this->configuration['destinationId'];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [];
  }

  /*--------------------------------------------------------------------
   *
   * Configuration shortcuts.
   *
   * These utility functions provide simplified access to specific
   * configuration operands. Where needed, they also load parent,
   * destination, and selection entities and cache them in the command.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns true if the command uses file uploads.
   *
   * @return bool
   *   Returns TRUE if the command's special handling includes 'upload'.
   */
  public function doesFileUploads() {
    $def = $this->getPluginDefinition();
    return (in_array('upload', $def['specialHandling']));
  }

  /**
   * Gets the configuration's parent folder ID, if any.
   *
   * @return int
   *   Returns the folder ID of the parent folder, or a -1 if there is
   *   no parent.
   */
  protected function getParentId() {
    return (int) $this->configuration['parentId'];
  }

  /**
   * Gets and loads the configuration's parent folder, if any.
   *
   * If the parent folder has not been loaded yet, it is loaded and
   * cached. If there is no parent folder, a NULL is returned.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns the parent folder, or NULL if there is no parent.
   */
  protected function getParent() {
    // If there is a loaded parent, return it.
    if ($this->parent !== NULL) {
      return $this->parent;
    }

    // If there is no parent in the configuration, return NULL.
    $parentId = $this->getParentId();
    if ($parentId === (-1)) {
      return NULL;
    }

    // Load the parent and cache the result.
    $this->parent = FolderShare::load($parentId);
    return $this->parent;
  }

  /**
   * Gets the configuration's destination folder ID, if any.
   *
   * @return int
   *   Returns the folder ID of the destination folder, or a -1 if there
   *   is no destination.
   */
  protected function getDestinationId() {
    return $this->configuration['destinationId'];
  }

  /**
   * Gets and loads the configuration's destination folder, if any.
   *
   * If the destination folder has not been loaded yet, it is loaded
   * and cached. If there is no destination folder, a NULL is returned.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns the destination folder, or NULL if there is no destination.
   */
  protected function getDestination() {
    // If there is a loaded destination, return it.
    if ($this->destination !== NULL) {
      return $this->destination;
    }

    // If there is no destination in the configuration, return NULL.
    $destinationId = $this->getDestinationId();
    if ($destinationId === (-1)) {
      return NULL;
    }

    // Load the destination and cache the result.
    $this->destination = FolderShare::load($destinationId);
    return $this->destination;
  }

  /**
   * Gets the configuration's array of selected entity IDs.
   *
   * @return int[]
   *   Returns an array of integer entity IDs for selected items, or an
   *   empty array if there are no selected items.
   */
  protected function getSelectionIds() {
    return $this->configuration['selectionIds'];
  }

  /**
   * Gets and loads the configuration's array of selected items, if any.
   *
   * If the selected items have not been loaded yet, they are loaded
   * and cached. If there are no selected items, an empty array is returned.
   * If any file cannot be loaded, a NULL is included in the array.
   *
   * The returned associative array has FolderShare kind names as keys,
   * and values that are arrays of loaded FolderShare objects.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an array of selected items, or an empty array if there
   *   are no selected items.
   */
  protected function getSelection() {
    // If there are loaded selected items, return them.
    if (empty($this->selection) === FALSE) {
      return $this->selection;
    }

    // If there is no selection in the configuration, return an empty array.
    $ids = $this->getSelectionIds();
    if (empty($ids) === TRUE) {
      return [];
    }

    // Load the items, sort them by kind, and cache the result.
    $items = FolderShare::loadMultiple($ids);
    $sel = [];
    foreach ($items as $item) {
      if ($item === NULL) {
        continue;
      }

      $kind = $item->getKind();
      if (isset($sel[$kind]) === FALSE) {
        $sel[$kind] = [$item];
      }
      else {
        $sel[$kind][] = $item;
      }
    }

    $this->selection = $sel;
    return $this->selection;
  }

  /**
   * Uses the selection to create an operand name like "Files" or "Items".
   *
   * If there is no selection, the empty string is returned.
   *
   * If there is a single selection, it's kind is returned.
   *
   * If there are mutiple selections of the same kind, the plural kind
   * is returned.
   *
   * Otherwise the plural "items" is returned.
   *
   * All returned values are title case and translated.
   *
   * @return string
   *   The selection operand name.
   */
  protected function getSelectionOperandName() {
    if (empty($this->selection) === TRUE) {
      return '';
    }

    if (count($this->selection) === 1) {
      // Just one kind.
      $kind = reset(array_keys($this->selection));
      if (count($this->selection[$kind]) === 1) {
        // Just one item selected.
        return mb_convert_case(Utilities::mapKindToTerm($kind), MB_CASE_TITLE);
      }

      // Multiple items selected.
      return mb_convert_case(Utilities::mapKindToTerms($kind), MB_CASE_TITLE);
    }

    // Multiple kinds.
    return t('Items');
  }

  /*--------------------------------------------------------------------
   *
   * Validate.
   *
   * These functions validate the current configuration in stages or all
   * at once. Validation uses the parent, destination, selection, and
   * command-specific operands and checks them against the command's
   * definition. That definition includes requirements on where the
   * command may be used, such as only with specific kinds of parent,
   * or only with specific types of selection.
   *
   * Each of the validation stages set flags upon completion so that the
   * validation doesn't have to be repeated if nothing changes in the
   * configuration.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function isValidated() {
    return $this->validated;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration() {
    //
    // Validate the configuration by validating each of the individual
    // parts of the configuration. Validation necessarily also loads
    // the appropriate files and folders to check IDs and types.
    //
    // If the configuration has already been validated, we're done.
    if ($this->validated === TRUE) {
      return;
    }

    // Validate the parent constraints.
    //
    // This also validates the parent ID and loads the parent.
    $this->validateParentConstraints();

    // Validate the selection constraints.
    //
    // This also validates the selection's file and folder ID and loads them.
    $this->validateSelectionConstraints();

    // Validate the destination constraints.
    //
    // This also validates the destination ID and loads the destination.
    $this->validateDestinationConstraints();

    // Validate any other parameters for the command.
    $this->validateParameters();

    $this->validated = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateParentConstraints() {
    //
    // Validate the configuration in light of the command's parent
    // constraints. Those constraints specify whether there must be
    // a parent, and what kind it can be.
    //
    // If the configuration has already been validated, we're done.
    if ($this->parentValidated === TRUE) {
      return;
    }

    //
    // Setup
    // -----
    // Get the command's definition and its constraints.
    $def = $this->getPluginDefinition();
    $label = $def['label'];

    // Get the parent's kind, which we need in order to verify that
    // it is supported by the command.
    if ($this->getParentId() === (-1)) {
      // There is no parent, so it has no kind.
      $parentKind = 'none';
    }
    else {
      // Load the parent entity so that we can get its kind. If the
      // parent has already been loaded, this gets the cached entity.
      $parent = $this->getParent();
      if ($parent === NULL) {
        throw new ValidationException(t(
          'Programmer error: "@command" configuration has an invalid parent ID "@id". Please report this to the developer.',
          [
            '@command' => $label,
            '@id'      => $this->getParentId(),
          ]));
      }

      $parentKind = $parent->getKind();
    }

    //
    // Check parent kind
    // -----------------
    // Compare the parent kind against the list of supported kinds
    // for the command. These cases:
    // - $parentKind == 'none'. Be sure the command accepts 'none'.
    // - $parentKind != 'none'. Be sure the command accepts the parent's
    //   kind or 'any'.
    $kinds = $def['parentConstraints']['kinds'];

    if ($parentKind === 'none' && in_array('none', $kinds) === FALSE) {
      // There is no parent AND this command does not support having
      // no parent.
      throw new ValidationException(t(
        '"@command" is not supported in this situation.',
        ['@command' => $label]));
    }

    if (in_array($parentKind, $kinds) === FALSE &&
        in_array('any', $kinds) === FALSE) {
      // This parent's kind is not in the command's supported list
      // AND this command doesn't include the generic 'any' kind.
      throw new ValidationException(t(
        '"@command" is not supported in this situation.',
        ['@command' => $label]));
    }

    $this->parentValidated = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateSelectionConstraints() {
    //
    // Validate the configuration in light of the command's selection
    // constraints. Those constraints specify whether there must be
    // a selection, and what kind of items can be in the selection.
    //
    // If the configuration has already been validated, we're done.
    if ($this->selectionValidated === TRUE) {
      return;
    }

    //
    // Setup
    // -----
    // Get the command's definition and its selection constraints.
    $def   = $this->getPluginDefinition();
    $label = $def['label'];

    // Count the total number of selected items.
    $selectionIds      = $this->getSelectionIds();
    $nSelected         = count($selectionIds);
    $parentId          = $this->getParentId();
    $selectionIsParent = FALSE;

    //
    // Check selection size
    // --------------------
    // Insure the selection size is compatible with the command.
    //
    // If the command does not use a selection, then we can skip all of this.
    $types       = $def['selectionConstraints']['types'];
    $canHaveOne  = in_array('one', $types);
    $canHaveMany = in_array('many', $types);

    if (in_array('none', $types) === TRUE) {
      $this->selectionValidated = TRUE;
      return;
    }

    // The command uses a selection, so check it.
    if ($nSelected === 0) {
      // There is no selection.
      //
      // Does the command support defaulting to operating on the parent,
      // if there is one?
      if ($parentId !== (-1) && in_array('parent', $types) === TRUE) {
        // There is a parent, and this command accepts defaulting the
        // selection to the parent. Flag that.
        $nSelected = 1;
        $selectionIsParent = TRUE;
      }
      else {
        // Command does not default to the parent when there is no
        // selection, and we already checked that the command does not
        // work when there is no selection.
        throw new ValidationException(($canHaveMany === TRUE) ?
          t('Please select one or more items.') :
          t('Please select one item.'));
      }
    }
    elseif ($nSelected === 1) {
      // There is a single item selected.
      if ($canHaveOne === FALSE) {
        // But the command does not support having just one item.
        throw new ValidationException(
          t('Please select multiple items.'));
      }
    }
    else {
      // There are multiple items selected.
      if ($canHaveMany === FALSE) {
        // But the command does not support having multiple items.
        throw new ValidationException(
          t('Please select just one item.'));
      }
    }

    if ($selectionIsParent === FALSE) {
      $selection = $this->getSelection();
    }
    else {
      $parent = $this->getParent();
      $selection[$parent->getKind()] = [$parent];
    }

    //
    // Check selection kinds
    // ---------------------
    // Insure the kinds of items in the selection are compatible with
    // the command.
    $kinds = $def['selectionConstraints']['kinds'];

    if (in_array('any', $kinds) === FALSE) {
      // The command has specific kind requirements since it did not use
      // the catch-all 'any'. Loop through the selection and make sure
      // every kind is supported.
      foreach (array_keys($selection) as $kind) {
        if (in_array($kind, $kinds) === FALSE) {
          throw new ValidationException(t(
            'This operation does not support @kind items.',
            [
              '@kind' => Utilities::mapKindToTerm($kind),
            ]));
        }
      }
    }

    //
    // Check selection parentage
    // -------------------------
    // Confirm that all of the selected items are children of the parent,
    // if any. Skip this check if the selection has been defaulted to
    // the parent.
    if ($selectionIsParent === FALSE) {
      foreach ($selection as $kind => $items) {
        foreach ($items as $item) {
          if ($item->getParentFolderId() !== $parentId) {
            throw new ValidationException(t(
              '"Programmer error:  @command" requires that all selected items be children of the parent, but item ID "@id" is not. Please report this to the developer.',
              [
                '@command' => $label,
                '@id'      => $item->id(),
              ]));
          }
        }
      }
    }

    $this->selectionValidated = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateDestinationConstraints() {
    //
    // Validate the configuration in light of the command's destination
    // constraints. Those constraints specify whether there must be
    // a destination, and what kind it must be.
    //
    // If the configuration has already been validated, we're done.
    if ($this->destinationValidated === TRUE) {
      return;
    }

    // Get definition and destination constraints.
    $def = $this->getPluginDefinition();
    $kinds = $def['destinationConstraints']['kinds'];
    $label = $def['label'];
    $anyKinds = in_array('any', $kinds);

    // Get the destinations's kind, if any.
    if ($this->getDestinationId() === (-1)) {
      $destinationKind = 'none';
    }
    else {
      // Verify that the destination loads.
      $destination = $this->getDestination();
      if ($destination === NULL) {
        // Invalid. Bad parent ID.
        //
        // Likely programmer error. The caller should have insured the
        // destination ID was valid before providing it to the command.
        throw new ValidationException(t(
          'Programmer error: "@command" configuration has an invalid destination ID "@id". Please report this to the developer.',
          [
            '@command' => $label,
            '@id'      => $this->getDestinationId(),
          ]));
      }

      $destinationKind = $destination->getKind();
    }

    // Check that the destination is a supported kind.
    if ($anyKinds === FALSE && in_array($destinationKind, $kinds) === FALSE) {
      throw new ValidationException(t(
        '"@command" is not supported in this situation.',
        ['@command' => $label]));
    }

    $this->destinationValidated = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateParameters() {
    //
    // Subclasses may override this method to validate command-specific
    // parameters.
    $this->parametersValidated = TRUE;
  }

  /*--------------------------------------------------------------------
   *
   * Access.
   *
   * These functions check if the user has suitable access to the parent,
   * destination, and selection. Every command specifies what access
   * operation it needs for these items (such as 'view' or 'update'),
   * and this code checks that the user indeed has permission for this.
   *
   * Access checks go through the entity's access controller, which
   * checks role-based permissions as well as folder-based access
   * controls for folder sharing.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function access(
    AccountInterface $account = NULL,
    $returnAsObject = FALSE) {
    //
    // Commands may have a parent, a destination, and a selection, and
    // they may need to create content. The user needs appropriate
    // access for all of these. Below we check each one and return
    // an indication of success or failure.
    //
    // The command configuration must already have been validated. Among
    // other things, this checks that the parent, destination, and
    // selection exist (if needed) and loads them.
    //
    // If the command hasn't been validated yet, do so now. This may
    // throw an exception.
    $this->validateConfiguration();

    // If the command's access has already been checked, we're done.
    if ($this->accessible === TRUE) {
      return ($returnAsObject === FALSE) ? TRUE : AccessResult::allowed();
    }

    // Get the plugin definition.
    $def = $this->getPluginDefinition();

    // If there is a parent and access is required, check for access.
    // Commands that do not require a parent, or parent access, have
    // an access operation of 'none'.
    $op = $def['parentConstraints']['access'];
    if ($op !== 'none') {
      $parent = $this->getParent();
      if ($parent !== NULL && $parent->access($op, $account, FALSE) === FALSE) {
        return ($returnAsObject === FALSE) ? FALSE : AccessResult::forbidden();
      }
    }

    // If there is a destination and access is required, check for access.
    // Commands that do not require a destination, or destination access, have
    // an access operation of 'none'.
    $op = $def['destinationConstraints']['access'];
    if ($op !== 'none') {
      $destination = $this->getDestination();
      if ($destination !== NULL && $destination->access($op, $account, FALSE) === FALSE) {
        return ($returnAsObject === FALSE) ? FALSE : AccessResult::forbidden();
      }
    }

    // If there is a selection and access is required, check for access.
    // Commands that do not require a selection, or selection access, have
    // an access operation of 'none'.
    $op = $def['selectionConstraints']['access'];
    if ($op !== 'none') {
      $selection = $this->getSelection();
      foreach ($selection as $items) {
        foreach ($items as $item) {
          if ($item->access($op, $account, FALSE) === FALSE) {
            return ($returnAsObject === FALSE) ? FALSE : AccessResult::forbidden();
          }
        }
      }
    }

    // If special handling indicates create access is required, check
    // for access.
    if (in_array('create', $def['specialHandling']) === TRUE ||
        in_array('createroot', $def['specialHandling']) === TRUE) {
      $accessController = \Drupal::entityTypeManager()->getAccessControlHandler(FolderShare::ENTITY_TYPE_ID);
      if ($accessController->createAccess(
        NULL,
        $account,
        ['parentId' => $this->getParentId()],
        FALSE) === FALSE) {
        return ($returnAsObject === FALSE) ? FALSE : AccessResult::forbidden();
      }
    }

    $this->accessible = TRUE;
    return ($returnAsObject === FALSE) ? TRUE : AccessResult::allowed();
  }

  /*--------------------------------------------------------------------
   *
   * Execute.
   *
   * These functions execute the command using the current configuration.
   * Subclasses must define this.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  abstract public function execute();

  /**
   * {@inheritdoc}
   */
  public function clearExecuteMessages() {
    $this->executeMessages = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getExecuteMessages(string $type = NULL) {
    if ($type === NULL) {
      return $this->executeMessages;
    }

    return [
      $type => $this->executeMessages[$type],
    ];
  }

  /**
   * Appends an execution message.
   *
   * Execution messages may report on the success, or failure, of a
   * command. Execution may append multiple messages through multiple
   * calls and all messages will be saved for later presentation to
   * a user.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $message
   *   The message to save that reports on command execution. A NULL
   *   clears the message.
   * @param string $type
   *   (optional) The message type, or severity. Supported values are
   *   'status' (default), 'warning', and 'error'.
   */
  protected function addExecuteMessage($message, string $type = 'status') {
    if (isset($this->executeMessages) === FALSE) {
      $this->executeMessages = [];
    }

    if (isset($this->executeMessages[$type]) === FALSE) {
      $this->executeMessages[$type] = [];
    }

    $this->executeMessages[$type][] = $message;
  }

  /*---------------------------------------------------------------------
   *
   * Redirects.
   *
   * These functions support an optional redirect that sends the user to
   * a new page whenever the command is used. In this case, execution
   * does nothing and the command is merely a placeholder.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function hasRedirect() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRedirect() {
    return NULL;
  }

  /*---------------------------------------------------------------------
   *
   * Configuration forms.
   *
   * These functions build and validate an optional configuration form
   * to prompt the user for additional parameters needed by the command.
   * Subclasses must fill in these functions if needed.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function hasConfigurationForm() {
    //
    // Subclasses may override this method and return TRUE if they provide
    // a configuration form.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmitButtonName() {
    return 'Save';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $formState) {
    //
    // Subclasses that provide a configuration form must override this
    // method and build their form.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $formState) {
    //
    // Subclasses that provide a configuration form must override this
    // method and validate their form.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $formState) {
    //
    // Subclasses that provide a configuration form must override this
    // method and submit their form.
  }

  /*--------------------------------------------------------------------
   *
   * Debug.
   *
   * These functions help in debugging commands by printing out command
   * information in a human-readable form.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns a string representation of the plugin.
   *
   * @return string
   *   A string representation of the plugin.
   */
  public function __toString() {
    $def = $this->getPluginDefinition();
    $s = 'Views Field Plugin: ' . $def['id'] . "\n";

    $s .= '  parent ID: "' . $this->getParentId() . "\"\n";
    $s .= '  destination ID: "' . $this->getDestinationId() . "\"\n";
    $s .= '  selection IDs: ';
    $ids = $this->getSelectionIds();
    if (empty($ids) === TRUE) {
      $s .= '(empty)';
    }
    else {
      foreach ($ids as $id) {
        $s .= ' "' . $id . '"';
      }
    }

    $s .= "\n";

    $s .= '  configuration keys: ';
    foreach (array_keys($this->configuration) as $key) {
      $s .= ' "' . $key . '"';
    }

    $s .= "\n";

    return $s;
  }

}
