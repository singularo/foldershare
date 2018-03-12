<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Core\Url;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;

/**
 * Defines a command plugin to download files or folders.
 *
 * The command downloads a single selected entity.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 * - 'selectionIds': selected entities to download.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_download",
 *  label       = @Translation("Download"),
 *  title       = @Translation("Download"),
 *  menu        = @Translation("Download @operand..."),
 *  tooltip     = @Translation("Download files and folders"),
 *  description = @Translation("Download files and folders, and all of their content."),
 *  category    = "export",
 *  weight      = 10,
 *  parentConstraints = {
 *    "kinds"   = {
 *      "none",
 *      "folder",
 *      "rootfolder",
 *    },
 *    "access"  = "view",
 *  },
 *  selectionConstraints = {
 *    "types"   = {
 *      "one",
 *      "many",
 *    },
 *    "kinds"   = {
 *      "file",
 *      "image",
 *      "folder",
 *      "rootfolder",
 *    },
 *    "access"  = "view",
 *  },
 * )
 */
class DownloadItem extends FolderShareCommandBase {

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
    // Do nothing.
  }

  /*---------------------------------------------------------------------
   *
   * Redirects.
   *
   * These functions direct that the command must redict the user to
   * a stand-alone page.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function hasRedirect() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRedirect() {
    // Get the IDs of entities to download.
    $ids = $this->getSelectionIds();
    if (empty($ids) === TRUE) {
      $ids = [$this->getParentId()];
    }

    // Redirect to download.
    $idlist = implode(',',$ids);
    $url = Url::fromRoute(
      Constants::ROUTE_DOWNLOAD,
      [
        'encoded' => $idlist,
      ]);
    return $url;
  }

}
