<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\foldershare\Utilities;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;

/**
 * Defines a command plugin to unarchive (uncompress) files and folders.
 *
 * The command extracts all contents of a ZIP archive and adds them to
 * the current folder.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 * - 'selectionIds': selected entities to Archive.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_unarchive",
 *  label       = @Translation("Uncompress"),
 *  title       = @Translation("Uncompress @operand"),
 *  menu        = @Translation("Uncompress @operand"),
 *  tooltip     = @Translation("Uncompress archive file"),
 *  description = @Translation("Uncompress an archive file and add its contents to this folder."),
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
 *    },
 *    "kinds"   = {
 *      "file",
 *    },
 *    "access"  = "view",
 *  },
 * )
 */
class UnarchiveItem extends FolderShareCommandBase {

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
    $ids    = $this->getSelectionIds();
    $id     = reset($ids);
    $entity = FolderShare::load($id);

    // Unarchive.
    $entity->unarchiveFromZip();

    // Return a status message. Be specific if practical.
    $this->addExecuteMessage(t(
      'The @kind "@name" has been uncompressed.',
      [
        '@kind' => Utilities::mapKindToTerm($entity->getKind()),
        '@name' => $entity->getName(),
      ]),
      'status');
  }

}
