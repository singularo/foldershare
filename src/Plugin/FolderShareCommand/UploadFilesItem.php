<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\File;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Utilities;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;

/**
 * Defines a command plugin to upload files for a folder.
 *
 * The command uploads files and adds them to the parent folder.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_upload_files",
 *  label       = @Translation("Upload files"),
 *  title       = @Translation("Upload files to @operand"),
 *  menu        = @Translation("Upload files to @operand..."),
 *  tooltip     = @Translation("Upload files into a folder"),
 *  description = @Translation("Upload files into a folder."),
 *  category    = "new",
 *  weight      = 10,
 *  specialHandling = {
 *    "create",
 *    "upload",
 *  },
 *  parentConstraints = {
 *    "kinds"   = {
 *      "folder",
 *      "rootfolder",
 *    },
 *    "access"  = "update",
 *  },
 *  selectionConstraints = {
 *    "types"   = {
 *      "none",
 *    },
 *  },
 * )
 */
class UploadFilesItem extends FolderShareCommandBase {

  /*--------------------------------------------------------------------
   *
   * Execute.
   *
   * These functions execute the command using the current configuration.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function execute() {
    //
    // Get the parent. The command's annotation has required that there
    // be a parent folder, and the parent class has already checked this.
    $parent = $this->getParent();

    // Attach the uploaded files to the parent. PHP has already uploaded
    // the files. It remains to convert the files to File objects, then
    // wrap them with FolderShare entities and add them to the parent folder.
    $configuration = $this->getConfiguration();
    $uploadClass = $configuration['uploadClass'];
    $results = $parent->addUploadFiles($uploadClass);

    // Report success or errors. The returned array mixes File objects
    // with string objects containing error messages. The objects are
    // in the same order as the original list of uploaded files.
    //
    // Below, we sweep through the returned array and post
    // error messages, if any.
    $nUploaded = 0;
    $firstItem = NULL;
    foreach ($results as $entry) {
      if (is_string($entry) === TRUE) {
        // There were errors.
        $this->addExecuteMessage($entry, 'error');
      }
      else {
        if ($nUploaded === 0) {
          $firstItem = $entry;
        }

        $nUploaded++;
      }
    }

    // Report on results, if any.
    if ($nUploaded === 1) {
      // Single file. There may have been other files that had errors though.
      $name = $firstItem->getFilename();
      $this->addExecuteMessage(t(
        'The @kind "@name" has been uploaded.',
        [
          '@kind' => Utilities::mapKindToTerm('file'),
          '@name' => $name,
        ]),
        'status');
    }
    elseif ($nUploaded > 1) {
      // Multiple files. There may have been other files that had errors tho.
      $this->addExecuteMessage(t(
        '@number @kinds have been uploaded.',
        [
          '@number' => $nUploaded,
          '@kinds'  => Utilities::mapKindToTerms('file'),
        ]),
        'status');
    }
  }

  /*--------------------------------------------------------------------
   *
   * Configuration form.
   *
   * These functions build and respond to the command's configuration
   * form to collect additional command operands.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function hasConfigurationForm() {
    // A configuration form is only needed if there are no uploaded
    // files already available.  Check.
    $configuration = $this->getConfiguration();
    if (empty($configuration) === TRUE) {
      // No command configuration? Need a form.
      return TRUE;
    }

    if (empty($configuration['uploadClass']) === TRUE) {
      // No command upload class specified? Need a form.
      return TRUE;
    }

    $uploadClass = $configuration['uploadClass'];
    $pendingFiles = \Drupal::request()->files->get('files', []);
    if (isset($pendingFiles[$uploadClass]) === FALSE) {
      // No pending files? Need a form.
      return TRUE;
    }

    foreach ($pendingFiles[$uploadClass] as $fileInfo) {
      if ($fileInfo !== NULL) {
        // At least one good pending file. No form.
        return FALSE;
      }
    }

    // No good pending files. Need a form.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $formState) {

    // Build a form to prompt for files to upload.
    $uploadClass = Constants::MODULE . '-command-upload';

    $configuration = $this->getConfiguration();
    $configuration['uploadClass'] = $uploadClass;
    $this->setConfiguration($configuration);

    // Provide a file field. Browsers automatically add a button to
    // invoke a platform-specific file dialog to select files.
    $form[$uploadClass] = [
      '#type'        => 'file',
      '#multiple'    => TRUE,
      '#description' => t(
        'Select one or more @kinds to upload.',
        [
          '@kinds'  => Utilities::mapKindToTerms('file'),
        ]),
      '#process'   => [
        [
          get_class($this),
          'processFileField',
        ],
      ],
    ];

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
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $formState) {
    //
    // Execute the command.
    try {
      $this->execute();

      // Get its messages, if any, and transfer them to the page.
      foreach ($this->getExecuteMessages() as $type => $list) {
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
    }
    catch (\Exception $e) {
      $configuration = $this->getConfiguration();
      $uploadClass = $configuration['uploadClass'];
      $formState->setErrorByName($uploadClass, $e->getMessage());
      return;
    }
  }

}
