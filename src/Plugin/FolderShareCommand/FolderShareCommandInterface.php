<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Plugin\ConfigurablePluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Executable\ExecutableInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines an interface for plugins for commands on a shared folder.
 *
 * A command may be triggered by a user interaction with a web page via
 * AJAX, a Drupal form, a REST request, a drush command line, or some
 * other interface.  Commands report errors via a well-defined set of
 * exceptions, which the external trigering mechanism may turn into
 * AJAX responses, REST responses, or Drupal form responses.
 *
 * Subclasses implement specific commands that use a set of parameters
 * and operate upon referenced FolderShare objects. Commands
 * could create new folders or files, delete them, move them, copy them,
 * or update them in some way.
 *
 * A command is responsible for validating parameters, performing access
 * control checks, and issuing calls to the Folder and File data models
 * to cause an operation to take place.
 *
 * @ingroup foldershare
 */
interface FolderShareCommandInterface extends PluginFormInterface, ExecutableInterface, PluginInspectionInterface, ConfigurablePluginInterface {

  /*---------------------------------------------------------------------
   *
   * Configuration forms.
   *
   * Optionally, a command may have a configuration form to prompt the
   * user for command operands (a.k.a. parameters).
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns TRUE if the command has a configuration form.
   *
   * When TRUE, the class must implement the form methods of
   * PluginFormInterface, including:
   *
   * - buildConfigurationForm().
   * - validateConfigurationForm().
   * - submitConfigurationForm().
   *
   * @return bool
   *   Returns true if there is a configuration form.
   *
   * @see PluginFormInterface::buildConfigurationForm()
   * @see PluginFormInterface::validateConfigurationForm()
   * @see PluginFormInterface::submitConfigurationForm()
   */
  public function hasConfigurationForm();

  /**
   * Returns the untranslated name of the form's submit button.
   *
   * @return string
   *   Returns the name for the submit button.
   */
  public function getSubmitButtonName();

  /*---------------------------------------------------------------------
   *
   * Redirects.
   *
   * Optionally, commands may redirect the user to a separate page that
   * may provide content or a form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns TRUE if the command redirects to another page.
   *
   * When TRUE, the class must implement getRedirect() to return a URL
   * for the redirect page.
   *
   * @return bool
   *   Returns TRUE if there is a redirect.
   *
   * @see ::getRedirect()
   */
  public function hasRedirect();

  /**
   * Returns a URL for the command's redirect, if any.
   *
   * The class must implement hasRedirect() to return TRUE and flag that
   * execution of the command must be handled after redirecting to another
   * page first.
   *
   * @return \Drupal\Core\Url
   *   Returns the URL for a new page to which the command needs to redirect
   *   in order to complete the command. This may be a page with a form to
   *   collect additional information.
   *
   * @see ::hasRedirect()
   */
  public function getRedirect();

  /*---------------------------------------------------------------------
   *
   * Validate.
   *
   * These functions handle validation of the command's configuration,
   * checking that the configuration meets the command's constraints
   * on the parent, destination, and selection.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns TRUE if the command's configuration has been validated.
   *
   * Validation of the command's configuration is done by
   * validateConfiguration().
   *
   * @return bool
   *   Returns TRUE if the command has been validated.
   *
   * @see ::validateConfiguration()
   */
  public function isValidated();

  /**
   * Validates the current configuration.
   *
   * The configuration's parent, selection, and destination constraints
   * are checked and all indicated files and folders loaded and checked
   * for validity.
   *
   * An exception is thrown if the constraints are not met.
   *
   * Upon successful completion of configuration validation, the
   * isValidated() function returns TRUE.
   *
   * @return bool
   *   Returns TRUE upon successful validation, and FALSE otherwise.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws a validation exception with a message describing a problem
   *   with the parent.
   *
   * @see ::isValidated()
   * @see ::validateParentConstraints()
   */
  public function validateConfiguration();

  /**
   * Checks if the configuration parent meets command constraints.
   *
   * The configuration's parent folder is checked to see if it meets
   * the command's constraints. These may include that there be no
   * parent, or that the parent be of a specific kind.
   *
   * An exception is thrown if the constraints are not met.
   *
   * This function is automatically called by validateConfiguration(),
   * but it may be called directly to validate the parent configuration only.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws a validation exception with a message describing a problem
   *   with the parent.
   *
   * @see ::validateConfiguration()
   */
  public function validateParentConstraints();

  /**
   * Checks if the configuration selection meets command constraints.
   *
   * The configuration's selected entity IDs are checked to see if the
   * number and kind of items meets the command's constraints.
   *
   * An exception is thrown if the constraints are not met.
   *
   * This function is automatically called by validateConfiguration(),
   * but it may be called directly to validate the selection configuration only.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws a validation exception with a message describing a problem
   *   with the selection.
   *
   * @see ::validateConfiguration()
   */
  public function validateSelectionConstraints();

  /**
   * Checks if the configuration destination meets command constraints.
   *
   * The configuration's destination folder is checked to see if it meets
   * the command's constraints. These may include that there be no
   * destination, or that the destination be of a specific kind.
   *
   * An exception is thrown if the constraints are not met.
   *
   * This function is automatically called by validateConfiguration(),
   * but it may be called directly to validate the destination
   * configuration only.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws a validation exception with a message describing a problem
   *   with the destination.
   *
   * @see ::validateConfiguration()
   */
  public function validateDestinationConstraints();

  /**
   * Checks if additional parameters in the configuration are valid.
   *
   * An exception is thrown if the constraints are not met.
   *
   * This function is automatically called by validateConfiguration(),
   * but it may be called directly to validate the configuration's
   * additional parameters only.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws a validation exception with a message describing a problem
   *   with the parameters.
   *
   * @see ::validateConfiguration()
   */
  public function validateParameters();

  /*---------------------------------------------------------------------
   *
   * Access.
   *
   * These functions check that the user has the access permissions
   * required by the command prior to doing its work.
   *
   *---------------------------------------------------------------------*/

  /**
   * Checks for valid user access to execute this command.
   *
   * The command may require a parent, a destination, a selection, and
   * additional parameters. The command also may create new content. All
   * of these may require an access to check to insure that the user has
   * permission to execute the command. The specific access permissions
   * required vary from command to command and are specified in the command's
   * definition.
   *
   * If the command configuration has not been validated yet, it is validated
   * before checking for access. This may throw an exception.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional, NULL default) The user for which to check access,
   *   or NULL to check access for the current user.
   * @param bool $returnAsObject
   *   (optional, FALSE default) When FALSE, the function returns TRUE/FALSE
   *   indicating if access has been granted. When TRUE, the function returns
   *   an access result object indicating if access is allowed or forbidden.
   *
   * @return bool|\Drupal\Core\Access\AccessResultInterface
   *   Returns either a boolean or an access result object, depending
   *   upon the value of the $returnAsObject argument.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the command has not been validated yet, it is validated and may
   *   throw a validation exception with a message describing a problem
   *   with the configuration.
   *
   * @see ::isValidated()
   * @see ::validateConfiguration()
   * @see \Drupal\foldershare\Annotation\FolderShareCommand
   */
  public function access(
    AccountInterface $account = NULL,
    $returnAsObject = FALSE);

  /*---------------------------------------------------------------------
   *
   * Execute.
   *
   * Classes must implement ExecutableInterface by providing an execute()
   * function, which uses the current configuration and returns nothing.
   * Commands that wish to return a completion message should return it
   * via getExecuteMessages().
   *
   *---------------------------------------------------------------------*/

  /**
   * Clears any saved execution messages.
   */
  public function clearExecuteMessages();

  /**
   * Returns messages reporting on the most recent command execution.
   *
   * After calling the command's execute() function, messages may be
   * available via this function. Callers may show this on a
   * page returned to a user, within a dialog box, or in a message
   * printed to a command line.
   *
   * The returned associative array uses message type keys of 'status',
   * 'warning', or 'error'. For each key, an array of messages is included.
   * The array may be empty if there were no messages of that type posted.
   *
   * @param string $type
   *   (optional) Limit the messages returned to be of a specific type.
   *   Defaults to NULL, meaning all types are returned.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   Returns a message reporting on the most recent command execution.
   *   A NULL is returned if there is no message.
   */
  public function getExecuteMessages(string $type);

}
