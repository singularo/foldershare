<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Core\Form\FormStateInterface;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Utilities;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;

/**
 * Defines a command plugin to copy files and folders.
 *
 * The command copies all selected files and folders to a chosen
 * destination folder or the root folder list.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 * - 'selectionIds': selected entities to duplicate.
 * - 'destinationId': the destination folder, if any.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_copy",
 *  label       = @Translation("Copy"),
 *  title       = @Translation("Copy @operand"),
 *  menu        = @Translation("Copy @operand..."),
 *  tooltip     = @Translation("Copy files and folders"),
 *  description = @Translation("Copy files and folders to another folder."),
 *  category    = "copy",
 *  weight      = 10,
 *  specialHandling = {
 *    "create",
 *  },
 *  parentConstraints = {
 *    "kinds"   = {
 *      "none",
 *      "folder",
 *      "rootfolder",
 *    },
 *    "access"  = "none",
 *  },
 *  destinationConstraints = {
 *    "kinds"   = {
 *      "none",
 *      "folder",
 *      "rootfolder",
 *    },
 *    "access"  = "update",
 *  },
 *  selectionConstraints = {
 *    "types"   = {
 *      "one",
 *      "many",
 *    },
 *    "kinds"   = {
 *      "any",
 *    },
 *    "access"  = "view",
 *  },
 * )
 */
class CopyItem extends FolderShareCommandBase {

  /*--------------------------------------------------------------------
   *
   * Configuration.
   *
   * These functions initialize a default configuration for the command,
   * and validate parameters particular to this command.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function validateParameters() {
    if ($this->parametersValidated === TRUE) {
      // Already validated.
      return;
    }

    // Get the new destination ID from the configuration and check
    // if it is legal.
    $id = $this->getDestinationId();
    if ($id !== (-1)) {
      $entity = FolderShare::load($id);
      if ($entity === NULL) {
        throw new ValidationException(t('Unknown destination folder.'));
      }
    }

    $this->parametersValidated = TRUE;
  }

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
    // Loop through the selection, which is grouped by kind. Go through
    // all of the kinds and copy each item of each kind.
    $nItems          = 0;
    $firstItem       = NULL;
    $destination     = $this->getDestination();
    $destinationName = '';

    if ($destination === NULL) {
      // Copy selection to root.
      $selection = $this->getSelection();
      foreach ($selection as $items) {
        foreach ($items as $item) {
          $item->copyToRoot();

          // Keep track of the number of items and the first item.
          ++$nItems;
          if ($firstItem === NULL) {
            $firstItem = $item;
          }
        }
      }

      $destinationName = t('the top folder list');
      $destinationQuoted = FALSE;
    }
    else {
      // Copy selection to folder.
      $selection = $this->getSelection();
      foreach ($selection as $items) {
        foreach ($items as $item) {
          $item->copyToFolder($destination);

          // Keep track of the number of items and the first item.
          ++$nItems;
          if ($firstItem === NULL) {
            $firstItem = $item;
          }
        }
      }

      $destinationName = $destination->getName();
      $destinationQuoted = TRUE;
    }

    // Return a status message. Be specific if practical.
    if ($nItems === 1) {
      // One item. Refer to it by name.
      $this->addExecuteMessage(t(
        ($destinationQuoted === TRUE ?
          'The @kind "@name" has been copied to "@destination".' :
          'The @kind "@name" has been copied to @destination.'),
        [
          '@kind'        => Utilities::mapKindToTerm($firstItem->getKind()),
          '@name'        => $firstItem->getName(),
          '@destination' => $destinationName,
        ]),
        'status');
    }
    elseif (count($selection) === 1) {
      // One kind of items. Refer to them by kind.
      $this->addExecuteMessage(t(
        ($destinationQuoted === TRUE ?
          '@number @kinds have been copied to "@destination".' :
          '@number @kinds have been copied to @destination.'),
        [
          '@number'      => $nItems,
          '@kinds'       => Utilities::mapKindToTerms($firstItem->getKind()),
          '@destination' => $destinationName,
        ]),
        'status');
    }
    else {
      // Multiple kinds of items.
      $this->addExecuteMessage(t(
        ($destinationQuoted === TRUE ?
          '@number items have been copied to "@destination".' :
          '@number items have been copied to @destination.'),
        [
          '@number'      => $nItems,
          '@destination' => $destinationName,
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
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmitButtonName() {
    return 'Copy';
  }

  /**
   * Return description text based upon the current selection.
   *
   * @return string
   *   Returns the description text.
   */
  private function getDescription() {
    // Set the description based upon what was selected.
    $selection = $this->getSelection();
    $nKinds = 0;
    $nItems = 0;
    $firstKind = '';
    $firstItem = NULL;
    $includesFolders = FALSE;

    foreach ($selection as $kind => $entities) {
      $nEntities = count($entities);
      if ($nEntities !== 0) {
        $nItems += $nEntities;
        $nKinds++;
        if ($firstKind === '') {
          $firstKind = $kind;
        }

        if ($firstItem === NULL) {
          $firstItem = $entities[0];
        }

        if ($kind === FolderShare::FOLDER_KIND ||
            $kind === FolderShare::ROOT_FOLDER_KIND) {
          $includesFolders = TRUE;
        }
      }
    }

    if ($nItems === 0) {
      $firstItem = $this->getParent();
      $firstKind = $firstItem->getKind();
      $nKinds = 1;
      $nItems = 1;
      $includesFolders = TRUE;
    }

    if ($nKinds === 1) {
      if ($nItems === 1) {
        // One kind, one item.
        $operandName = Utilities::mapKindToTerm($firstKind, MB_CASE_LOWER);

        if ($firstKind === FolderShare::FOLDER_KIND ||
            $firstKind === FolderShare::ROOT_FOLDER_KIND) {
          $description = t(
            'Copy this @operand, including all of its contents, into the selected destination.',
            [
              '@operand' => $operandName,
            ]);
        }
        else {
          $description = t(
            'Copy this @operand.',
            [
              '@operand' => $operandName,
            ]);
        }
      }
      else {
        // One kind, multiple items.
        $operandName = Utilities::mapKindToTerms($firstKind, MB_CASE_LOWER);
        if ($firstKind === FolderShare::FOLDER_KIND ||
            $firstKind === FolderShare::ROOT_FOLDER_KIND) {
          $description = t(
            'Copy these @operand, including all of their contents, into the selected destination.',
            [
              '@operand' => $operandName,
            ]);
        }
        else {
          $description = t(
            'Copy the @operand.',
            [
              '@operand' => $operandName,
            ]);
        }
      }
    }
    else {
      // Multiple kinds, which can only occur with multiple items too.
      if ($includesFolders === TRUE) {
        $description = t(
          'Copy these @operand, including the contents of all folders, into the selected destination.',
          [
            '@operand' => t('items'),
          ]);
      }
      else {
        $description = t(
          'Copy these @operand into the selected destination.',
          [
            '@operand' => t('items'),
          ]);
      }
    }

    return $description;
  }

  /**
   * Returns a folder list for the form.
   *
   * If the given $destination is NULL, the returned folder list includes
   * all folders in the user's root folder list. If the destination is
   * not NULL, the returned folder list includes all folders in that
   * destination folder.
   *
   * @param \Drupal\foldershare\FolderShareInterface $destination
   *   (optional, default NULL) The destination folder for which to create
   *   a list of child folders.
   *
   * @return array
   *   Returns an associative array where array keys are entity IDs and
   *   array values are entity names.
   */
  private function getFolderList(FolderShareInterface $destination = NULL) {
    // Get a list of all folders in the destination. The returned array
    // uses entity IDs as keys and entities as values.
    if ($destination !== NULL) {
      $folders = $destination->getChildFolders();
    }
    else {
      $folders = FolderShare::getAllRootFolders(
        \Drupal::currentUser()->id());
    }

    // Sweep through the current selection. None of the selection can
    // appear in the list. If they did, it would allow copying the
    // selection into itself.
    $selectionIds = $this->getSelectionIds();
    foreach ($selectionIds as $id) {
      if (isset($folders[$id]) === TRUE) {
        unset($folders[$id]);
      }
    }

    // Create a selection list.
    $options = [];
    foreach ($folders as $folder) {
      $options[$folder->id()] = $folder->getName();
    }

    asort($options, SORT_NATURAL);

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $formState) {
    //
    // Build a form to prompt for the destination.
    //
    // Set the description based upon what was selected.
    $form['description'] = [
      '#type'   => 'html_tag',
      '#tag'    => 'p',
      '#value'  => $this->getDescription(),
      '#weight' => (-100),
    ];

    $selectClass = Constants::MODULE . '-destination';
    $openClass   = Constants::MODULE . '-open-folder';

    // Get the currently-selected destination ID. There may be a value in
    // the form state if we are rebuilding the form after selecting a
    // folder in a previous build.
    if (empty($formState) === FALSE) {
      // There is form state. But is there a destination value?
      $destinationId = $formState->getValue($selectClass);
      if (empty($destinationId) === TRUE) {
        // No there isn't a previous destination value. Revert to the
        // parent of the current selection. This could be (-1) if there
        // is no parent (i.e. we're showing the root folder list).
        $destinationId = $this->getParentId();
      }
    }
    else {
      // There is no form state. Start off with the parent of the current
      // selection. This could be (-1) if there is no parent (i.e. we're
      // showing the root folder list).
      $destinationId = $this->getParentId();
    }

    // Load the destination, if there is any.
    if ($destinationId !== (-1)) {
      $destination = FolderShare::load($destinationId);
    }
    else {
      $destination = NULL;
    }

    // Create the form.
    $folderList = $this->getFolderList($destination);
    if ($destinationId !== (-1)) {
      // Showing a folder's contents. Include the option to go
      // up a level.
      $path = Utilities::createFolderPath($destination, '/', TRUE, FALSE) . '/';
      $form[$selectClass] = [
        '#type'          => 'select',
        '#title'         => $path,
        '#multiple'      => FALSE,
        '#required'      => FALSE,
        '#default_value' => $destinationId,
        '#empty_option'  => t('.. (parent folder)'),
        '#empty_value'   => (-1),
        '#options'       => $folderList,
        '#size'          => 10,
        '#name'          => $selectClass,
        '#attributes'    => [
          'class'        => [$selectClass],
        ],
      ];
      $openDisabled = FALSE;
    }
    else {
      // Showing a root folder list. Don't include the option to
      // go up a level since we're already at the top.
      $path = Utilities::createFolderPath(NULL, '/', TRUE, FALSE) . '/';
      $form[$selectClass] = [
        '#type'          => 'select',
        '#title'         => $path,
        '#multiple'      => FALSE,
        '#required'      => FALSE,
        '#default_value' => $destinationId,
        '#options'       => $folderList,
        '#size'          => 10,
        '#name'          => $selectClass,
        '#attributes'    => [
          'class'        => [$selectClass],
        ],
      ];
      $openDisabled = empty($folderList) === TRUE;
    }

    $form[$openClass] = [
      '#type'       => 'button',
      '#value'      => t('Open'),
      '#name'       => $openClass,
      '#disabled'   => $openDisabled,
      '#attributes' => [
        'class'     => [$openClass],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $formState) {

    $selectClass = Constants::MODULE . '-destination';
    $openClass   = Constants::MODULE . '-open-folder';

    //
    // Respond to 'open' button
    // ------------------------
    // If the 'open' button has been pressed, check the destination folder
    // list, get the currently selected item, make that the current destination,
    // and rebuild the form using it.
    $userInput = $formState->getUserInput();
    if (isset($userInput[$openClass]) === TRUE) {
      // 'Open' button was pressed. Get the newly chosen folder, which
      // could be (-1) to indicate a parent folder.
      $oldDestId = $this->configuration['destinationId'];
      $newDestId = $formState->getValue($selectClass);

      if ($newDestId === (-1) && $oldDestId !== (-1)) {
        // The selected destination is ".." to up one level to the parent
        // of the old destination, which is not already at the root folder
        // level. So load the old destination and get its parent folder ID.
        $destination = FolderShare::load($oldDestId);
        $newDestId = $destination->getParentFolderId();
      }

      // Use the new destination folder ID as the current ID in the
      // configuration and form state.
      $this->configuration['destinationId'] = $newDestId;
      $formState->setValue($selectClass, $newDestId);

      // Clear the button and rebuild the form.
      unset($userInput[$openClass]);
      $formState->setUserInput($userInput);
      $formState->setRebuild(TRUE);
      return;
    }

    //
    // Respond to submitting the form
    // ------------------------------
    // Otherwise the 'open' button wasn't pressed and the form has been
    // submitted. Determine the selected destination:
    //
    // - If there is nothing selected in the folder list, use the current
    //   folder as the destination.
    //
    // - Otherwise when there is something selected in the folder list,
    //   use that folder as the destination.
    $newDestId = $formState->getValue($selectClass);
    if (empty($newDestId) === FALSE) {
      $this->configuration['destinationId'] = $newDestId;
    }

    try {
      $this->validateParameters();
    }
    catch (\Exception $e) {
      $formState->setErrorByName($selectClass, $e->getMessage());
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $formState) {
    //
    // Run the command.
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
      drupal_set_message($e->getMessage(), 'error');
      return;
    }
  }

}
