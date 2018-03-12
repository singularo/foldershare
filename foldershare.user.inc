<?php

/**
 * @file
 * Implements user account hooks for the module.
 */

use Drupal\foldershare\Entity\FolderShare;

/**
 * Implements hook_user_cancel().
 *
 * This hook is called when the account is being canceled with one of
 * these well-known cancelation methods:
 *
 * - user_cancel_block = disable the account, but keep contents.
 * - user_cancel_block_unpublish = disable the account and unpublish contents.
 * - user_cancel_reassign = delete account but move content to anonymous
 *
 * For 'user_cancel_block', this hook does nothing.
 *
 * For 'user_cancel_block_unpublish', this hook clears the sharing grants
 * for all folders owned by the user.
 *
 * For 'user_cancel_reassign', this hook changes the ownership of all
 * of the user's folders to be anonymous.  The user is also removed from
 * shared access to any other content.
 *
 * Modules may add cancelation methods, but this hook ignores them.
 */
function foldershare_user_cancel($edit, $account, $method) {
  $uid = $account->id();

  switch ($method) {
    case 'user_cancel_block':
      // Do not delete anything or disable any current sharing.
      // The user account may be temporarily blocked pending
      // resolution of some dispute.
      break;

    case 'user_cancel_block_unpublish':
      // Do not delete anything, but unshare everything they own.
      FolderShare::unshareAll($uid);
      break;

    case 'user_cancel_reassign':
      // Do not delete anything, but set the owner to anonymous (UID=0)
      // and remove the user from accessing everyone else's content.
      FolderShare::changeAllOwnerIdByUser($uid, 0);
      FolderShare::unshareFromAll($uid);
      break;
  }
}

/**
 * Implements hook_user_delete().
 *
 * This hook is called when the account has been deleted with the
 * 'user_cancel_delete' cancelation method, which mandates that the
 * account be deleted along with all of its content. This hook
 * therefore gets all of the user's files and folders and deletes
 * them and any sharing grants for them.
 */
function foldershare_user_predelete($account) {
  $uid = $account->id();
  if ($uid < 0) {
    // Invalid UID.
    return;
  }

  // Delete all of the user's content.
  FolderShare::deleteAll($uid);

  // Remove the user from accessing everyone else's content.
  FolderShare::unshareFromAll($uid);
}
