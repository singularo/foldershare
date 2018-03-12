<?php

namespace Drupal\foldershare\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Utilities;

/**
 * Defines a user form for editing folder share entity fields.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * The form presents the current values of all fields, except
 * for the name and other internal fields. The set of fields
 * shown always includes the description. Additional fields may
 * include the language choice, if the Drupal core Languages module
 * is enabled, plus any other fields a site has added to the folder type.
 *
 * The user is prompted to change any of the presented fields. A 'Save'
 * button triggers the form and saves the edits.
 *
 * <b>Access control:</b>
 * The route to this form must invoke the access control handler to
 * insure that the user is the admin or the owner of the folder subject.
 *
 * <b>Route:</b>
 * The route to this form must include a $foldershare argument.
 *
 * @ingroup foldershare
 */
class EditFolderShare extends ContentEntityForm {

  /*---------------------------------------------------------------------
   *
   * Form.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    // Base the form ID on the namespace-qualified class name, which
    // already has the module name as a prefix.  PHP's get_class()
    // returns a string with "\" separators. Replace them with underbars.
    return str_replace('\\', '_', get_class($this));
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $formState) {
    //
    // Validate arguments
    // ------------------
    // Check that the $foldershare argument is valid.
    $item = $this->getEntity();
    if ($item === NULL) {
      $message = $this->t(
        'The @form form was improperly invoked without a FolderShare entity parameter.',
        ['@form' => get_class($this)]);

      // Report the message to the user.
      drupal_set_message($this->t(
        '@module: Programmer error: @msg',
        [
          '@module' => Constants::MODULE,
          '@msg'    => $message,
        ]));

      // Log the message for the admin.
      \Drupal::logger(Constants::MODULE)->error($message);
      return [];
    }

    //
    // Setup
    // -----
    // Let the parent class build the default form for the entity.
    // The form may include any editable fields accessible by the
    // current user. This will not include the name field, or other
    // internal fields, which are blocked from being listed by
    // the base field definitions for the Folder entity.
    //
    // The order of fields on the form will honor the site's choices
    // via the field_ui.
    //
    // The EntityForm parent class automatically adds a 'Save' button
    // to submit the form.
    $form = parent::form($form, $formState);

    $form['#title'] = Utilities::createTitle(
      'Edit @operand',
      [
        $item->getKind() => [$item],
      ]);

    //
    // Nothing else at this time.  The default form is fine.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $formState) {
    // Save
    // -----
    // The parent class handles saving the entity.
    parent::save($form, $formState);

    //
    // Redirect
    // --------
    // Return to the view page for the folder.
    $formState->setRedirect(Constants::ROUTE_FOLDERSHARE,
      [Constants::ROUTE_FOLDERSHARE_ID => $this->getEntity()->id()]);
  }

}
