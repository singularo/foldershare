<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\foldershare\Utilities;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;

/**
 * Defines a command plugin to archive (compress) files and folders.
 *
 * The command creates a new ZIP archive containing all of the selected
 * files and folders and adds the archive to current folder.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 * - 'selectionIds': selected entities to Archive.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_archive",
 *  label       = @Translation("Compress"),
 *  title       = @Translation("Compress @operand"),
 *  menu        = @Translation("Compress @operand"),
 *  tooltip     = @Translation("Compress files and folders"),
 *  description = @Translation("Compress files and folders, and all of their contents."),
 *  category    = "archive",
 *  weight      = 20,
 *  specialHandling = {
 *    "create",
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
class ArchiveItem extends FolderShareCommandBase {

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
    // Consolidate the selection into a single entity list.
    $nItems    = 0;
    $firstItem = NULL;
    $children  = [];
    $selection = $this->getSelection();

    foreach ($selection as $items) {
      $n = count($items);
      if ($n > 0) {
        $children = array_merge($children, $items);
        $nItems += $n;
        if ($firstItem === NULL) {
          $firstItem = reset($items);
        }
      }
    }

    // Create an archive.
    $parent = $this->getParent();
    $archive = $parent->archiveToZip($children);

    // Return a status message. Be specific if practical.
    if ($nItems === 1) {
      // One item. Refer to it by name.
      $this->addExecuteMessage(t(
        'The @kind "@name" has been compressed and saved into the new "@archive" file.',
        [
          '@kind'    => Utilities::mapKindToTerm($firstItem->getKind()),
          '@name'    => $firstItem->getName(),
          '@archive' => $archive->getName(),
        ]),
        'status');
    }
    elseif (count($selection) === 1) {
      // One kind of items. Refer to them by kind.
      $this->addExecuteMessage(t(
        '@number @kinds have been compressed and saved into the new "@archive" file.',
        [
          '@number'  => $nItems,
          '@kinds'   => Utilities::mapKindToTerms($firstItem->getKind()),
          '@archive' => $archive->getName(),
        ]),
        'status');
    }
    else {
      // Multiple kinds of items.
      $this->addExecuteMessage(t(
        '@number items have been compressed and saved into the new "@archive" file.',
        [
          '@number'  => $nItems,
          '@archive' => $archive->getName(),
        ]),
        'status');
    }
  }

}
