<?php

namespace Drupal\foldershare\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines the annotation for command plugins.
 *
 * A command is similar to a Drupal action. It has a configuration containing
 * operands for the command, and an execute() function to apply the command
 * to the configuration. Some commands also have configuration forms to
 * prompt for operands.
 *
 * Commands differ from Drupal actions by including extensive annotation
 * that governs how that command appears in menus and under what circumstances
 * the command may be applied. For instance, annotation may indicate if
 * the command applies to files or folders or both, whether it requires a
 * selection and if that selection can include more than one item, and
 * what access permissions the user must have on the parent folder and on
 * the selection.
 *
 * Annotation for a command can be broken down into groups of information:
 *
 * - Identification:
 *   - the unique machine name for the plugin.
 *
 * - User interface:
 *   - the labels for the plugin.
 *   - a description for the plugin.
 *   - the user interface category and weight for presenting the command.
 *
 * - Constraints and access controls:
 *   - the parent folder constraints and access controls required, if needed.
 *   - the selection constraints and access controls required, if needed.
 *   - the destination constraints and access controls required, if needed.
 *   - any special handling needed.
 *
 * There are several plugin labels that may be specified. Each is used in
 * a different context:
 *
 * - "label" is a generic name for the command, such as "Edit" or "Delete".
 *   This label may be used in error messages, such as "The Delete command
 *   requires a selection."
 *
 * - "title" is the title used for the command's configuration form
 *   title. The label may include the "@operand" marker, which will be
 *   replaced with the name of an item or a kind, such as "Delete @operand".
 *   If not given, this defaults to "label".
 *
 * - "menu" is the menu name to use in menus. The menu text may include
 *   the "@operand" marker, which will be replaced with the name of an item
 *   or a kind, such as "Edit @operand...". If not given, this defaults
 *   to "label".
 *
 * The "@operand" in "title" and "menu" is replaced with text that varies
 * depending upon use.  Possibilities include:
 *
 * - Replaced with a singular name of the kind of operand, such as
 *   "Delete file" or "Delete folder". If the context is the current item,
 *   the word "this" may be inserted, such as "Delete this file".
 *
 * - Replaced with a plural name of the kind for all operands, such as
 *   "Delete files" or "Delete folders".
 *
 * - Replaced with a plural "items" if the kinds of the operands is mixed,
 *   such as "Delete items".
 *
 * - Replaced with the quoted singular name of the operand when there is
 *   just one item (typically for form titles), such as "Delete "Bob's folder"".
 *
 * The plugin namespace for commands is "Plugin\FolderShareCommand".
 *
 * @ingroup foldershare
 *
 * @Annotation
 */
class FolderShareCommand extends Plugin {

  /*--------------------------------------------------------------------
   * Identification
   *--------------------------------------------------------------------*/

  /**
   * The unique machine-name plugin ID.
   *
   * The value is taken from the plugin's 'id' annotation.
   *
   * The ID must be a unique string used to identify the plugin. Often it
   * is closely related to a module namespace and class name.
   *
   * @var string
   */
  public $id = '';

  /*--------------------------------------------------------------------
   * User interface
   *--------------------------------------------------------------------*/

  /**
   * The generic command label for user interfaces.
   *
   * The value is taken from the plugin's 'label' annotation and should
   * be singular and translated.
   *
   * The label may be used in menus, buttons, and error messages.
   *
   * The label will be automatically converted to title case (each word
   * capitalized).
   *
   * Examples:
   * - "Change owner".
   * - "Delete".
   * - "Edit".
   * - "Rename".
   * - "Share".
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label = '';

  /**
   * The title for user interface pages, dialogs, and forms.
   *
   * The value is taken from the plugin's 'title' annotation and should
   * be translated. The title may include "@operand" to mark where the name
   * of an item or item kind should be inserted.
   *
   * The title may be used in page and dialog titles for the command's
   * configuration form, if any.
   *
   * The title will be automatically converted to title case (each word
   * capitalized).
   *
   * If no title is given, the title defaults to the label, with no
   * "@operand".
   *
   * Examples:
   * - "Change owner of @operand".
   * - "Delete @operand".
   * - "Edit @operand".
   * - "Rename @operand".
   * - "Share @operand".
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $title = '';

  /**
   * The name for user interface menus.
   *
   * The value is taken from the plugin's 'menu' annotation and should be
   * translated. The menu value may include "@operand" to mark where the name
   * of an item or item kind should be inserted. If the menu has a configuration
   * form or some other way of prompting for user input, users of the command
   * may append "...".
   *
   * The menu name will be automatically converted to title case (each word
   * capitalized).
   *
   * If no menu name is given, the menu name defaults to the title.
   *
   * Examples:
   * - "Change owner of @operand...".
   * - "Delete @operand...".
   * - "Edit @operand...".
   * - "Rename @operand...".
   * - "Share @operand...".
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $menu = '';

  /**
   * The command tooltip for user interfaces.
   *
   * The value is taken from the plugin's 'tooltip' annotation and
   * should be very brief (just a few words), translated, and without
   * ending punctionation (e.g. 'Create new thing', 'Delete thing').
   *
   * The tooltip may be used as a tool tip (mouse hover help) for
   * menus and buttons.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $tooltip = '';

  /**
   * The command description for user interfaces.
   *
   * The value is taken from the plugin's 'description' annotation and
   * should be a sentence or two, translated, and end with punctuation
   * (e.g. 'Edit the thing to change its description and other attributes.').
   *
   * The description may be used as explanatory text in a dialog or form
   * that prompts for input for the command.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description = '';

  /**
   * The command's category within user interfaces.
   *
   * The value is taken from the plugin's 'category' annotation and
   * should be a verb in lower case.
   *
   * User interfaces may group together all commands in the same category
   * and present them as a menu, group of toolbar buttons, etc.  Category
   * names may be anything, but they are typically single-word verbs.
   *
   * Several standard categories are available, though additional categories
   * may be defined by simply using other category names:
   *
   * - 'new': commands to create new files and folders (e.g. New folder).
   *
   * - 'import': commands to import files and folders (e.g. Upload files).
   *
   * - 'export': commands to export files and folders (e.g. Download).
   *
   * - 'edit': commands to edit or change files and folders in-place (e.g.
   *   Edit, Rename).
   *
   * - 'delete': commands to delete files and folders (e.g. Delete).
   *
   * - 'copy': commands to duplicate, copy, or move files and folders
   *   (e.g. Copy, Duplicate, Move).
   *
   * - 'settings': commands to show or adjust file or folder attributes
   *   (e.g. Share).
   *
   * - 'present': commands to present files and folders visually (e.g.
   *   Show image, Visualize data, Play audio, Play video).
   *
   * - 'administer': commands used by administrators to delete or modify
   *   content (e.g. Change owner, Delete all).
   *
   * @var string
   *
   * @see \Drupal\Core\Plugin\CategorizingPluginManagerTrait
   * @see \Drupal\Component\Plugin\CategorizingPluginManagerInterface
   */
  public $category = '';

  /**
   * The commands weight among other commands in the same category.
   *
   * The value is taken from the plugin's 'weight' annotation and should
   * be a positive or negative integer.
   *
   * Weights are used to sort commands within a category before they
   * are presented within a menu, toobar of buttons, etc. Higher weights
   * are listed later in the category.
   *
   * @var int
   */
  public $weight = 0;

  /*--------------------------------------------------------------------
   * Constraints and access controls
   *--------------------------------------------------------------------*/

  /**
   * The command's parent constraints, if any.
   *
   * Defined by the 'parentConstraints' annotation, this optional value
   * is an associative array with keys:
   * - 'kinds': an optional list of the kinds of parents supported.
   * - 'access': an optional access operation that the parent must support.
   *
   * User interfaces may build different menus for different kinds of parent
   * (such as for folders vs. root folders) and place commands into those
   * menus based upon their parent constraints.
   *
   * <b>Kinds:</b>
   * The optional 'kinds' field lists the kinds of parent supported.
   * Valid values are:
   * - Any kind supported by FolderShare (e.g. 'file', 'folder').
   * - 'none': command does not use a parent.
   * - 'any': command is used with any item.
   *
   * If omitted or empty, 'kinds' defaults to 'none'.
   *
   * <b>Access:</b>
   * The optional 'access' field names an access control operation that
   * must be supported by the parent. Valid values are:
   * - Any access control operation supported by FolderShare (e.g. 'view').
   * - 'none' to indicate no parent access is required.
   *
   * If omitted or empty, 'access' defaults to 'none' if 'kinds' is only
   * 'none', and 'view' otherwise.
   *
   * @var array
   */
  public $parentConstraints = NULL;

  /**
   * The command's selection constraints, if any.
   *
   * Defined by the 'selectionConstraints' annotation, this optional
   * value is an associative array with keys:
   * - 'types': an optional indicator of the selection size.
   * - 'kinds': an optional list of the kinds of selected items supported.
   * - 'access': an optional access operation that the selection must support.
   *
   * User interfaces must provide a way for a user to specify the selection
   * of files and folders.
   *
   * <b>Types:</b>
   * The optional 'types' field lists the types of selection allowed.
   * Valid values are:
   * - 'none': the command supports having no selection.
   * - 'parent': the command supports use of the parent instead of a selection.
   * - 'one': the command supports having a single item selection.
   * - 'many': the command supports having a multiple item selection.
   *
   * If omitted or empty, 'types' defaults to 'none'.
   *
   * <b>Kinds:</b>
   * The optional 'kinds' field lists the kinds of selected items supported.
   * Valid values are:
   * - Any kind supported by FolderShare (e.g. 'file', 'folder').
   * - 'any' for use with any item.
   *
   * If omitted or empty, 'kinds' defaults to 'none' if 'types' is only
   * 'none', and 'any' otherwise.
   *
   * <b>Access:</b>
   * The optional 'access' field names an access control operation that
   * must be supported by the selection. The field must be present if
   * the access 'types' is anything but 'none'.  Valid values are:
   * - Any access control operation supported by FolderShare (e.g. 'view').
   *
   * If omitted or empty, 'access' defaults to 'none' if 'kinds' is only
   * 'none', and 'view' otherwise.
   *
   * @var array
   */
  public $selectionConstraints = NULL;

  /**
   * The command's destination constraints, if any.
   *
   * Defined by the 'destinationConstraints' annotation, this optional
   * value is an associative array with keys:
   * - 'kinds': an optional list of the kinds of selected items supported.
   * - 'access': an optional access operation that the selection must support.
   *
   * User interfaces must provide a way for a user to specify the destination
   * file or folder.
   *
   * <b>Kinds:</b>
   * The optional 'kinds' field lists the kinds of destination items supported.
   * Valid values are:
   * - Any kind supported by FolderShare (e.g. 'file', 'folder').
   * - 'any' for use with any item.
   *
   * If omitted or empty, 'kinds' defaults to 'any'.
   *
   * <b>Access:</b>
   * The optional 'access' field names an access control operation that
   * must be supported by the destination. Valid values are:
   * - Any access control operation supported by FolderShare (e.g. 'view').
   *
   * If omitted or empty, 'access' defaults to 'none' if 'kinds' is only
   * 'none', and 'update' otherwise.
   *
   * @var array
   */
  public $destinationConstraints = NULL;

  /**
   * The command's special needs, if any.
   *
   * Defined by the 'specialHandling' annotation, this optional value
   * lists special case handling required by the command. Valid values are:
   * - 'create': the command creates FolderShare objects, so create access
   *   is required by the user.
   * - 'upload': the command uploads files, so special file processing is
   *   required.
   *
   * If omitted or empty, 'special' defaults to no special handling.
   *
   * @var array
   */
  public $specialHandling = NULL;

}
