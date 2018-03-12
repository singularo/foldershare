<?php

namespace Drupal\foldershare\Plugin;

use Drupal\Component\Plugin\CategorizingPluginManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\CategorizingPluginManagerTrait;
use Drupal\Core\Plugin\DefaultPluginManager;

use Drupal\foldershare\Constants;

/**
 * Defines a manager for plugins for commands on a shared folder.
 *
 * Similar to Drupal core Actions, shared folder commands encapsulate
 * logic to perform a single operation, such as creating, editing, or
 * deleting files or folders.
 *
 * This plugin manager keeps a list of all plugins after validating
 * that their definitions are well-formed.
 *
 * @ingroup foldershare
 */
class FolderShareCommandManager extends DefaultPluginManager implements CategorizingPluginManagerInterface {

  use CategorizingPluginManagerTrait;

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   * These functions create an instance of the command manager using
   * dependency injection.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a new command manager.
   *
   * @param \Traversable $namespaces
   *   An object containing the root paths, keyed by the corresponding
   *   namespace, in which to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The backend cache to use to store plugin information.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cacheBackend,
    ModuleHandlerInterface $moduleHandler) {

    // Create a manager and indicate the name of the directory for plugins,
    // the namespaces to look through, the module handler, the interface
    // plugins should implement, and the annocation class that describes
    // the plugins.
    parent::__construct(
      'Plugin/FolderShareCommand',
      $namespaces,
      $moduleHandler,
      'Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandInterface',
      'Drupal\foldershare\Annotation\FolderShareCommand',
      []);

    // Define module hooks that alter FolderShareCommand information to be
    // named "XXX_foldersharecommand_info_alter", where XXX is the module's
    // name.
    $this->alterInfo('foldersharecommand_info');

    // Clear cache definitions and set up the cache backend.
    $this->clearCachedDefinitions();
    $this->setCacheBackend($cacheBackend, 'foldersharecommand_info');
  }

  /*--------------------------------------------------------------------
   *
   * Validate.
   *
   * These functions validate a plugin command to insure that it has
   * specified all required annotation values, and that those values
   * are reasonable.
   *
   *--------------------------------------------------------------------*/

  /**
   * Checks that a plugin's definition is complete and valid.
   *
   * A complete definition must have a label, category, parent constraints,
   * selection constraints, destination constraints, and special handling.
   * All values must be valid.
   *
   * The definition is adjusted in-place to create upper/lower case use
   * to standardize values.
   *
   * The definition is regularized in-place to provide default values
   * if the plugin did not specify them explicitly.
   *
   * If the definition is not valid, error messages are logged and FALSE
   * is returned. Otherwise TRUE is returned for valid definitions.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise.
   */
  private function validateDefinition(array &$pluginDefinition) {
    // Label, title, and menu.
    if ($this->validateLabel($pluginDefinition) === FALSE) {
      return FALSE;
    }

    // Description and tooltip.
    if ($this->validateDescription($pluginDefinition) === FALSE) {
      return FALSE;
    }

    // Category.
    if ($this->validateCategory($pluginDefinition) === FALSE) {
      return FALSE;
    }

    // Weight.
    if ($this->validateWeight($pluginDefinition) === FALSE) {
      return FALSE;
    }

    // Parent constraints.
    if ($this->validateParentConstraints($pluginDefinition) === FALSE) {
      return FALSE;
    }

    // Selection constraints.
    if ($this->validateSelectionConstraints($pluginDefinition) === FALSE) {
      return FALSE;
    }

    // Destination constraints.
    if ($this->validateDestinationConstraints($pluginDefinition) === FALSE) {
      return FALSE;
    }

    // Special handling.
    if ($this->validateSpecialHandling($pluginDefinition) === FALSE) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates the definition's label, title, and menu names.
   *
   * The definition's label must be non-empty. It is automatically updated
   * to use title case.
   *
   * The remaining values may be empty and default to lesser values.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   */
  private function validateLabel(array &$pluginDefinition) {
    //
    // Validate label
    // --------------
    // The label cannot be empty.
    if (empty($pluginDefinition['label']) === TRUE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] .
        '": a "label" value must be defined.');
      return FALSE;
    }

    // Map label to title case.
    $label = mb_convert_case(
      (string) $pluginDefinition['label'],
      MB_CASE_TITLE);
    $pluginDefinition['label'] = $label;

    //
    // Validate title
    // --------------
    // If no title is given, use the label.
    // Otherwise, map it to title case too.
    if (empty($pluginDefinition['title']) === TRUE) {
      $pluginDefinition['title'] = $pluginDefinition['label'];
    }
    else {
      $title = mb_convert_case(
        (string) $pluginDefinition['title'],
        MB_CASE_TITLE);
      $pluginDefinition['title'] = $title;
    }

    //
    // Validate menu name
    // -------------------
    // If no menu name is given, use the title.
    // Otherwise, map it to title case too.
    if (empty($pluginDefinition['menu']) === TRUE) {
      $pluginDefinition['menu'] = $pluginDefinition['title'];
    }
    else {
      $menu = mb_convert_case(
        (string) $pluginDefinition['menu'],
        MB_CASE_TITLE);
      $pluginDefinition['menu'] = $menu;
    }

    return TRUE;
  }

  /**
   * Validates the definition's description and tooltip.
   *
   * The definition's description may be empty.
   *
   * If the tooltip is empty, it defaults to the label.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   */
  private function validateDescription(array &$pluginDefinition) {
    if (empty($pluginDefinition['tooltip']) === TRUE) {
      $pluginDefinition['tooltip'] = $pluginDefinition['label'];
    }

    return TRUE;
  }

  /**
   * Validates the definition's category.
   *
   * The definition's category must be non-empty. It is automatically updated
   * to use lower case.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   */
  private function validateCategory(array &$pluginDefinition) {
    // The category cannot be empty.
    if (empty($pluginDefinition['category']) === TRUE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] .
        '": the "category" value must be defined.');
      return FALSE;
    }

    // Map category to lower-case.
    $category = mb_convert_case(
      (string) $pluginDefinition['category'],
      MB_CASE_LOWER);
    $pluginDefinition['category'] = $category;

    return TRUE;
  }

  /**
   * Validates the definition's weight.
   *
   * The definition's weight must be an integer and defaults to zero.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   */
  private function validateWeight(array &$pluginDefinition) {
    // Default an empty weight to zero. Otherwise convert to integer.
    if (empty($pluginDefinition['weight']) === TRUE) {
      $pluginDefinition['weight'] = (int) 0;
    }
    else {
      // Insure the stored value is an integer.
      $pluginDefinition['weight'] = (int) $pluginDefinition['weight'];
    }

    return TRUE;
  }

  /**
   * Validates the 'kinds' field for constraints.
   *
   * Used for parent, destination, and selection constraints, the 'kinds'
   * field names the kinds of FolderShare object that may be used by a
   * command. Kinds must be recognized by the FolderShare entity.
   * Additional special kinds may be used by some constraints, such as
   * 'rootlist'.
   *
   * The 'kinds' field must be an array, if given, Kinds are always
   * lower case.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   * @param array $constraints
   *   The constraints array with a 'kinds' field. The field is added if
   *   missing, and adjusted to use lower case if needed.
   * @param string $default
   *   The default value to use if 'kinds' is omitted.
   */
  private function validateKinds(array &$pluginDefinition, array &$constraints, string $default = 'any') {
    if (empty($constraints['kinds']) === TRUE) {
      $constraints['kinds'] = [$default];
      return TRUE;
    }

    if (is_array($constraints['kinds']) === FALSE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] . '": the "kinds" value must be an array.');
      return FALSE;
    }

    foreach ($constraints['kinds'] as $index => $k) {
      $constraints['kinds'][$index] = mb_convert_case($k, MB_CASE_LOWER);
    }

    return TRUE;
  }

  /**
   * Validates the 'access' field for constraints.
   *
   * Used for parent, destination, and selection cnstraints, the 'access'
   * field names an access control operation that must be permitted for
   * the current user in order for the command to execute. Operation
   * names must be recognized by the FolderShare access control handler.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   * @param array $constraints
   *   The constraints array with an 'access' field. The field is added if
   *   missing, and adjusted to use lower case if needed.
   * @param string $default
   *   The default value to use if 'access' is omitted.
   */
  private function validateAccess(array &$pluginDefinition, array &$constraints, string $default = 'none') {
    if (empty($constraints['access']) === TRUE) {
      $constraints['access'] = $default;
      return TRUE;
    }

    if (is_string($constraints['access']) === FALSE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] . '": the "access" value must be a scalar string.');
      return FALSE;
    }

    $constraints['access'] = mb_convert_case($constraints['access'], MB_CASE_LOWER);
    return TRUE;
  }

  /**
   * Validates the 'types' field for selection constraints.
   *
   * Used for selection cnstraints, the 'types' field lists the types of
   * selection that are supported by the command. Values must be one of:
   * - 'none': the command does not use a selection.
   * - 'parent': the command can use the parent if no selection is provided.
   * - 'one': the command can use a single selection.
   * - 'many': the command can use multiple selected items.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   * @param array $constraints
   *   The constraints array with a 'types' field. The field is added if
   *   missing, and adjusted to use lower case if needed.
   * @param string $default
   *   The default value to use if 'types' is omitted.
   */
  private function validateTypes(array &$pluginDefinition, array &$constraints, string $default = 'none') {
    if (empty($constraints['types']) === TRUE) {
      $constraints['types'] = [$default];
      return TRUE;
    }

    if (is_array($constraints['types']) === FALSE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] . '": the "types" value must be an array.');
      return FALSE;
    }

    foreach ($constraints['types'] as $index => $t) {
      $t = mb_convert_case($t, MB_CASE_LOWER);
      $constraints['types'][$index] = $t;

      switch ($t) {
        case 'none':
        case 'parent':
        case 'one':
        case 'many':
          break;

        default:
          \Drupal::logger(Constants::MODULE)->error(
            'Invalid FolderShareCommand plugin, ID "' .
            $pluginDefinition['id'] . '": unrecognized type "' .
            $t . '".');
          return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Validates the definition's parent constraints.
   *
   * The parent for a command provides context. It is typically a parent
   * folder that contains content a command is acting upon. Constraints
   * specify the kinds of parents supported by the command, and the type
   * of access the user must have to the parent.
   *
   * If omitted or empty, the parent constraints default to:
   * - 'kinds' = ['any'].
   * - 'access' = 'view'.
   *
   * If 'kinds' is omitted or empty, it defaults to:
   * - 'kinds' = ['any'].
   *
   * If 'kinds' includes 'any', it is simplified to just be 'any'.
   *
   * 'kinds' values are not validated but should be known FolderShare kinds,
   * plus 'any' and 'rootlist'.  Kinds are automatically converted to
   * lower case.
   *
   * If 'access' is omitted or empty, it defaults to:
   * - 'access' = 'view'.
   *
   * 'access' values are not validated but should be known access operations,
   * plus 'none'. Access operations are automatically converted to
   * lower case.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   *
   * @see \Drupal\foldershare\Entity\FolderShare
   * @see \Drupal\foldershare\Entity\FolderShareAccessControlHandler
   */
  private function validateParentConstraints(array &$pluginDefinition) {
    // If there are no constraints, use defaults.
    if (empty($pluginDefinition['parentConstraints']) === TRUE) {
      $pluginDefinition['parentConstraints'] = [
        'kinds'  => ['any'],
        'access' => 'view',
      ];
      return TRUE;
    }

    // Constraints must be an array.
    $constraints = &$pluginDefinition['parentConstraints'];
    if (is_array($constraints) === FALSE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] . '": the "parentConstraints" value must be an array');
      return FALSE;
    }

    // Validate kinds.
    if ($this->validateKinds($pluginDefinition, $constraints, 'any') === FALSE) {
      return FALSE;
    }

    // Validate access.
    if ($this->validateAccess($pluginDefinition, $constraints, 'view') === FALSE) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates the definition's selection requirements.
   *
   * The seletion for a command provides operands, such as the files or
   * folders to be edited, deleted, etc. Some commands do not require
   * a selection.
   *
   * If omitted or empty, the selection constraints default to:
   * - 'types' = ['none'].
   * - 'kinds' = ['any'].
   * - 'access' = 'view'.
   *
   * If 'types' is omitted or empty, it defaults to:
   * - 'types' = ['none'].
   *
   * 'Types' values are validated and must be one of: 'none', 'parent',
   * 'one', or 'many'. Given values are automatically converted to
   * lower case before validation.
   *
   * If 'kinds' is omitted or empty, it defaults to:
   * - 'kinds' = ['any'].
   *
   * If 'kinds' includes 'any', it is simplified to just be 'any'.
   *
   * 'kinds' values are not validated but should be known FolderShare kinds,
   * plus 'any' and 'rootlist'.  Kinds are automatically converted to
   * lower case.
   *
   * If 'access' is omitted or empty, it defaults to:
   * - 'access' = 'view'.
   *
   * 'access' values are not validated but should be known access operations,
   * plus 'none'. Access operations are automatically converted to
   * lower case.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   *
   * @see \Drupal\foldershare\Entity\FolderShare
   * @see \Drupal\foldershare\Entity\FolderShareAccessControlHandler
   */
  private function validateSelectionConstraints(array &$pluginDefinition) {
    // If there are no constraints, use defaults.
    if (empty($pluginDefinition['selectionConstraints']) === TRUE) {
      $pluginDefinition['selectionConstraints'] = [
        'types'  => ['none'],
        'kinds'  => ['any'],
        'access' => 'view',
      ];
      return TRUE;
    }

    // Constraints must be an array.
    $constraints = &$pluginDefinition['selectionConstraints'];
    if (is_array($constraints) === FALSE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] . '": the "selectionConstraints" value must be an array.');
      return FALSE;
    }

    // Validate kinds.
    if ($this->validateKinds($pluginDefinition, $constraints, 'any') === FALSE) {
      return FALSE;
    }

    // Validate access.
    if ($this->validateAccess($pluginDefinition, $constraints, 'view') === FALSE) {
      return FALSE;
    }

    // Validate types.
    if ($this->validateTypes($pluginDefinition, $constraints, 'none') === FALSE) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates the definition's destination requirements.
   *
   * The destination for a command provides the parent for any new content
   * created by, or moved to that location. Most commands do not have a
   * destination.
   *
   * If omitted or empty, the destination constraints default to:
   * - 'kinds' = ['none'].
   * - 'access' = 'update'.
   *
   * If 'kinds' is omitted or empty, it defaults to:
   * - 'kinds' = ['none'].
   *
   * If 'kinds' includes 'any', it is simplified to just be 'any'.
   *
   * 'kinds' values are not validated but should be known FolderShare kinds,
   * plus 'none', 'any', and 'rootlist'.  Kinds are automatically converted to
   * lower case.
   *
   * If 'access' is omitted or empty, it defaults to:
   * - 'access' = 'update'.
   *
   * 'access' values are not validated but should be known access operations,
   * plus 'none'. Access operations are automatically converted to
   * lower case.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   */
  private function validateDestinationConstraints(array &$pluginDefinition) {
    // If there are no constraints, use defaults.
    if (empty($pluginDefinition['destinationConstraints']) === TRUE) {
      $pluginDefinition['destinationConstraints'] = [
        'kinds'  => ['none'],
        'access' => 'update',
      ];
      return TRUE;
    }

    // Constraints must be an array.
    $constraints = &$pluginDefinition['destinationConstraints'];
    if (is_array($constraints) === FALSE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] . '": the "destinationConstraints" value must be an array.');
      return FALSE;
    }

    // Validate kinds.
    if ($this->validateKinds($pluginDefinition, $constraints, 'none') === FALSE) {
      return FALSE;
    }

    // Validate access.
    if ($this->validateAccess($pluginDefinition, $constraints, 'update') === FALSE) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates the definition's special handling.
   *
   * Some commands require special handling. This is indicated by one or
   * more of:
   * - 'create': the command creates content, so create access is required
   *   by the user.
   * - 'createroot': the command creates root folders, so create root access
   *   is required by the user.
   * - 'upload': the command uploads files, so file upload handling must
   *   be done prior to invoking the command.
   *
   * @param array $pluginDefinition
   *   The definition of the plugin.
   *
   * @return bool
   *   Returns TRUE if the plugin definition is complete and valid,
   *   and FALSE otherwise. When FALSE, a message is logged.
   */
  private function validateSpecialHandling(array &$pluginDefinition) {
    // If there is no special handling, use defaults.
    if (empty($pluginDefinition['specialHandling']) === TRUE) {
      $pluginDefinition['specialHandling'] = [];
      return TRUE;
    }

    // Special handling must be an array.
    $handling = &$pluginDefinition['specialHandling'];
    if (is_array($handling) === FALSE) {
      \Drupal::logger(Constants::MODULE)->error(
        'Invalid FolderShareCommand plugin, ID "' .
        $pluginDefinition['id'] . '": the "specialHandling" value must be an array.');
      return FALSE;
    }

    foreach ($handling as $index => $h) {
      $h = mb_convert_case($h, MB_CASE_LOWER);
      $handling[$index] = $h;

      switch ($h) {
        case 'create':
        case 'createroot':
        case 'upload':
          break;

        default:
          \Drupal::logger(Constants::MODULE)->error(
            'Invalid FolderShareCommand plugin, ID "' .
            $pluginDefinition['id'] . '": unrecognized special handling "' .
            t . '".');
          return FALSE;
      }
    }

    return TRUE;
  }

  /*--------------------------------------------------------------------
   *
   * Search.
   *
   * These functions look through the manager's list of plugin commands
   * for those that meet certain criteria.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  protected function findDefinitions() {
    // Let the parent class discover definitions, process them, and
    // let other modules alter them. The parent class also filters out
    // definitions for modules that have been uninstalled.
    $definitions = parent::findDefinitions();

    // At this point, the definitions have been created and modules
    // have had a chance to alter them. Now we can check that the
    // definitions are complete and valid. If not, remove them from
    // the definitions list.
    foreach ($definitions as $pluginId => $pluginDefinition) {
      if ($this->validateDefinition($pluginDefinition) === FALSE) {
        // Bad definition. Remove it from the list.
        unset($definitions[$pluginId]);
      }
      else {
        // Validation may have corrected the definition. Save it.
        $definitions[$pluginId] = $pluginDefinition;
      }
    }

    return $definitions;
  }

  /**
   * Get an array of definitions that work with the given parent kinds.
   *
   * The array argument is a list of one or more parent kinds, as defined
   * by the FolderShare entity. The additional kinds are also recognized:
   * - 'any': any FolderShare kind.
   * - 'none': no FolderShare kind.
   * - 'rootlist': the root folder list context.
   *
   * @param string[] $parentKinds
   *   The kinds of parent for which to get definitions.
   *
   * @return Drupal\foldershare\FolderShareCommand\FolderShareCommandInterface[]
   *   Returns an array of plugin definitions for plugins for which one
   *   of the given parent types meets parent requirements.
   */
  public function getDefinitionsByParentKind(array $parentKinds) {
    // If there are no parent kinds to check, then there are no
    // plugin definitions to return.
    if (empty($parentKinds) === TRUE) {
      return [];
    }

    // Loop through all the definitions and find those that match at
    // least one of the parent kinds.
    $result = [];
    foreach ($this->getDefinitions() as $id => $plugin) {
      foreach ($plugin['parentConstraints']['kinds'] as $k) {
        if ($k === 'none') {
          // Never included.
          continue;
        }

        if ($k === 'any') {
          // Always included.
          $result[$id] = $plugin;
          continue;
        }

        if (in_array($k, $parentKinds, TRUE) === TRUE) {
          // One of the requested kinds.
          $result[$id] = $plugin;
          break;
        }
      }
    }

    return $result;
  }

  /**
   * Returns a list of categorized commands ordered by weight.
   *
   * @return array
   *   Returns an associative array where keys are category names, and
   *   values are arrays of plugins in those categories. Categories are
   *   in an unspecified order. Plugins within a category are sorted
   *   from high to low by weight, and by label within the same weight.
   */
  public function getCategories() {
    // Sift plugins into categories.
    $categories = [];
    foreach ($this->getDefinitions() as $plugin) {
      $cat = $plugin['category'];

      // Add to category.
      if (isset($categories[$cat]) === TRUE) {
        $categories[$cat][] = $plugin;
      }
      else {
        $categories[$cat] = [$plugin];
      }
    }

    // Sort each category by weight.
    foreach ($categories as $cat => &$group) {
      usort(
        $group,
        [
          $this,
          'compareWeight',
        ]);
    }

    return $categories;
  }

  /**
   * Returns a numeric value comparing weights or labels of two plugins.
   *
   * @param mixed $a
   *   The first plugins definition to compare.
   * @param mixed $b
   *   The second plugins definition to compare.
   *
   * @return int
   *   Returns -1 if $a < $b, 1 if $a > $b, and 0 if they are equal.
   *   When weights are equal, labels are are compared to get an alphabetic
   *   order.
   */
  public static function compareWeight($a, $b) {
    $aweight = (int) $a['weight'];
    $bweight = (int) $b['weight'];

    if ($aweight === $bweight) {
      return strcmp($a['label'], $b['label']);
    }

    return ($aweight < $bweight) ? (-1) : 1;
  }

  /*--------------------------------------------------------------------
   *
   * Debug.
   *
   * These functions assist in debugging by printing out information
   * about commands in the manager.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns a string containing a list of plugin commands.
   *
   * @return string
   *   A string containing a table of available plugin commands.
   */
  public function __toString() {
    $s = '';
    foreach ($this->getDefinitions() as $def) {
      // Basics. Always exist.
      $s .= $def['id'] . "\n";
      $s .= '  label:           "' . $def['label'] . "\"\n";
      $s .= '  title:           "' . $def['title'] . "\"\n";
      $s .= '  menu:            "' . $def['menu'] . "\"\n";
      $s .= '  tooltip:         "' . $def['tooltip'] . "\"\n";
      $s .= '  description:     "' . $def['description'] . "\"\n";
      $s .= '  category:        "' . $def['category'] . "\"\n";
      $s .= '  weight:          ' . $def['weight'] . "\n";

      // Parent constraints.
      $req = $def['parentConstraints'];
      $s .= "  parent constraints:\n";
      if (empty($req['kinds']) === TRUE) {
        $s .= "    kinds: (empty)\n";
      }
      else {
        $s .= '    kinds:';
        foreach ($req['kinds'] as $c) {
          $s .= ' ' . $c;
        }

        $s .= "\n";
      }

      if (empty($req['access']) === TRUE) {
        $s .= "    access: (empty)\n";
      }
      else {
        $s .= '    access: ' . $req['access'] . "\n";
      }

      // Selection constraints.
      $req = $def['selectionConstraints'];
      $s .= "  selection constraints:\n";
      if (empty($req['types']) === TRUE) {
        $s .= "    types: (empty)\n";
      }
      else {
        $s .= '    types:';
        foreach ($req['types'] as $c) {
          $s .= ' ' . $c;
        }

        $s .= "\n";
      }

      if (empty($req['kinds']) === TRUE) {
        $s .= "    kinds: (empty)\n";
      }
      else {
        $s .= '    kinds:';
        foreach ($req['kinds'] as $c) {
          $s .= ' ' . $c;
        }

        $s .= "\n";
      }

      if (empty($req['access']) === TRUE) {
        $s .= "    access: (empty)\n";
      }
      else {
        $s .= '    access: ' . $req['access'] . "\n";
      }

      // Destination constraints.
      $req = $def['destinationConstraints'];
      $s .= "  destination constraints:\n";
      if (empty($req['kinds']) === TRUE) {
        $s .= "    kinds: (empty)\n";
      }
      else {
        $s .= '    kinds:';
        foreach ($req['kinds'] as $c) {
          $s .= ' ' . $c;
        }

        $s .= "\n";
      }

      if (empty($req['access']) === TRUE) {
        $s .= "    access: (empty)\n";
      }
      else {
        $s .= '    access: ' . $req['access'] . "\n";
      }

      // Special handling.
      if (empty($req['specialHandling']) === TRUE) {
        $s .= "    specialHandling: (empty)\n";
      }
      else {
        $s .= '    specialHandling:';
        foreach ($req['specialHandling'] as $c) {
          $s .= ' ' . $c;
        }

        $s .= "\n";
      }
    }

    return $s;
  }

}
