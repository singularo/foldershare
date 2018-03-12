<?php

namespace Drupal\foldershare\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

use Drupal\foldershare\Entity\FolderShare;

/**
 * Defines a queue worker to update folder sizes.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * During some operations on files and folders, a folder's size can be
 * set to NULL to flag that the size is no longer known. This worker
 * sweeps through the folder tree and updates missing sizes.  This may
 * require recursing through a tree and updating sizes for deeper
 * folders prior to updating the size for an upper folder.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\Foldershare
 *
 * @QueueWorker(
 *  id       = "foldershare_update_folder_sizes",
 *  label    = @Translation( "Update folder sizes" ),
 *  cron     = { "time" = 30 }
 * )
 */
class UpdateFolderSizes extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    //
    // Validate item array
    // -------------------
    // Silently ignore bad data.
    if ($data === NULL || is_array($data) === FALSE) {
      return;
    }

    $ids = [];
    foreach ($data as $d) {
      if (is_numeric($d) === TRUE) {
        $ids[] = $d;
      }
    }

    if (empty($ids) === TRUE) {
      return;
    }

    //
    // Process item
    // ------------
    // This function loops through the folder IDs and updates the
    // size on each one. This may involve recursing through a folder
    // tree and updating child folders first. If this job hits a
    // timeout, then it will be automatically requeued and tried again.
    // Any child folders that have already had their sizes updated
    // will have those sizes reused so that size calculations don't
    // have to be repeated. Eventually, then, even a long folder ID
    // list and deep folder tree will get fully processed so that this
    // queue entry can be finished.
    FolderShare::updateSizes($ids);
  }

}
