<?php

namespace Drupal\foldershare\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Entity\Exception\ValidationException;
use Drupal\foldershare\Entity\Exception\NotFoundException;

/**
 * Creates a form to upload and add a file to a folder.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This form is invoked by web service clients in order to upload a file
 * into a folder. It exists solely because the Drupal 8.5 (and earlier)
 * REST module does not support base class features needed for file uploads.
 *
 * The route to this form requires authentication, so there is a current
 * user.
 *
 * @ingroup foldershare
 */
class FileUpload extends FormBase {

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
  public function buildForm(array $form, FormStateInterface $formState = NULL) {
error_log("FolderShare POST: upload build");
    //
    // Define form
    // -----------
    // The form has two fields:
    // - A file field that triggers the file upload.
    // - A destination field that names where the uploaded file should go.
    $form['file'] = [
      '#type'  => 'file',
      '#title' => 'The local file to upload.',
      '#required' => FALSE,
    ];

    $form['path'] = [
      '#type'  => 'textfield',
      '#title' => 'The path destination for the file.',
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Upload'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /*--------------------------------------------------------------------
   *
   * Form submit.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $formState) {
error_log("FolderShare POST: upload validate");
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState) {
error_log("FolderShare POST: upload");
    //
    // Get destination
    // ---------------
    // The form contains a destination path. The path may be:
    //
    // - The path to an existing folder to contain the uploaded file.
    //
    // - The path to a non-existant file, but with an existing parent. The
    //   name of the non-existant file is the intended name of the
    //   uploaded file into that parent.
    $destinationPath = $formState->getValue('path');

    if (empty($destinationPath) === TRUE) {
error_log("Empty destination path");
      throw new BadRequestHttpException(t(
        "The path to the upload destination is empty."));
    }

    try {
      // Parse the path. This will fail if:
      // - The path is malformed.
      $parts = FolderShare::parseAndValidatePath($destinationPath);
    }
    catch (ValidationException $e) {
error_log("Invalid destination path");
      throw new BadRequestHttpException($e->getMessage());
    }

    if ($parts['path'] === '/') {
      // A destination of '/' is not allowed for file uploads.
error_log("Destination path can't be '/'");
      throw new BadRequestHttpException(t(
        "Files may not be uploaded into the '/' folder. Please select a subfolder."));
    }

    $destinationId     = (-1);
    $destinationEntity = NULL;
    $destinationName   = '';

    // Try to get the entity. This will fail if:
    // - The destination path refers to a non-existent entity.
    try {
      $destinationId = FolderShare::getEntityIdForPath($destinationPath);
    }
    catch (NotFoundException $e) {
      // The path was parsed but it doesn't point to a valid entity.
      // Back out one folder in the path and try again.
      //
      // Break the path into folders.
      $folders = mb_split('/', $parts['path']);

      // Skip the leading '/'.
      array_shift($folders);

      // Pull out the last name on the path as the proposed name of the
      // new entity. This will fail:
      // - The destination name is empty.
      $destinationName = array_pop($folders);
      if (empty($destinationName) === TRUE) {
error_log("Empty destination path name");
        throw new BadRequestHttpException(t(
          "The name for the uploaded file is empty."));
      }

      // If the folder parts array is now empty, then we had a path like
      // "/fred", which tries to place the uploaded file in "/", which is
      // not allowed.
      if (count($folders) === 0) {
error_log("Can't upload to /");
        throw new BadRequestHttpException(t(
          "Files may not be uploaded into the '/' folder. Please select a subfolder."));
      }

      // Rebuild the path, including the original scheme and UID.
      $parentPath = $parts['scheme'] . '://' . $parts['uid'];
      foreach ($folders as $f) {
        $parentPath .= '/' . $f;
      }

      // Try again. This will fail if:
      // - The parent path refers to a non-existent entity.
      try {
        $destinationId = FolderShare::getEntityIdForPath($parentPath);
      }
      catch (NotFoundException $e) {
error_log("Cannot find parent folder");
        throw new NotFoundHttpException($e->getMessage());
      }
    }
    catch (ValidationException $e) {
      // Otherwise the path is bad. We've already checked for this earlier,
      // so this shouldn't happen again.
      throw new BadRequestHttpException($e->getMessage());
    }

    //
    // Confirm destination legality
    // ----------------------------
    // Above, we found one of:
    // - A destination entity with $destinationId set and $destinationName
    //   left empty.
    //
    // - A parent destination entity with $destinationId and
    //   $destinationName set as the name for the new file.
    //
    // Load the entity, confirm it is a folder, and verify access.
    $destinationEntity = FolderShare::load($destinationId);
    if ($destinationEntity === NULL) {
      // This should not be possible since we already validated the ID.
      throw new NotFoundHttpException(t(
        "The destination folder could not be found."));
    }

    if ($destinationEntity->isFolderOrRootFolder() === FALSE) {
error_log("Destination isn't a folder");
      if (empty($destinationName) === TRUE) {
        // The destination path explicitly named this entity, but it is
        // a file. To upload to it we would have to overwrite it, which
        // is not allowed.
        throw new BadRequestHttpException(t(
          "A file with the same name already exists and cannot be overwritten.\nPlease select a different file name."));
      }

      // Otherwise the destination path named a non-existant entity and
      // we parsed it back to a parent that did exist, yet that parent
      // is not a folder.
      throw new BadRequestHttpException(t(
        "The destination for the uploaded file must be a folder.\nPlease check your destination path."));
    }

    // Verify the user has access. This will fail if:
    // - The user does not have update access to the destination entity.
    $access = $destinationEntity->access('delete', NULL, TRUE);
    if ($access->isAllowed() === FALSE) {
      $message = $access->getReason();
      if (empty($message) === TRUE) {
        $message = t("You are not authroized to upload files into the selected folder.");
      }
error_log("Access denied on parent folder");

      throw new AccessDeniedHttpException($message);
    }

    //
    // Add file to parent
    // ------------------
    // Allow automatic file renaming.
    //
    // This will fail if:
    // - There were any of several problems during the file upload.
    // - The file name is illegal.
    // - The folder is locked.
    //
    // While the method returns text error messages, it does not indicate
    // what type of error occurred. This makes it impossible for us to send
    // different HTTP codes on different errors.
    $results = $destinationEntity->addUploadFiles('file', TRUE);
    $entry = array_shift($results);
    if (is_string($entry) === TRUE) {
error_log("Problem adding uploaded files");
      throw new BadRequestHttpException($entry);
    }
  }

}
