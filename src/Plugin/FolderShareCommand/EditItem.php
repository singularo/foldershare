<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;

/**
 * Defines a command plugin to edit an entity.
 *
 * The command is a dummy that does not use a configuration and does not
 * execute to change the entity. Instead, this command is used solely to
 * create an entry in command menus and support a redirect to a stand-alone
 * edit form from which the user can alter fields on the entity.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 * - 'selectionIds': selected entities to edit.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_edit",
 *  label       = @Translation("Edit"),
 *  title       = @Translation("Edit @operand"),
 *  menu        = @Translation("Edit @operand..."),
 *  tooltip     = @Translation("Edit a file or folder"),
 *  description = @Translation("Edit the fields of a file or folder."),
 *  category    = "edit",
 *  weight      = 10,
 *  parentConstraints = {
 *    "kinds"   = {
 *      "none",
 *      "any",
 *    },
 *    "access"  = "none",
 *  },
 *  selectionConstraints = {
 *    "types"   = {
 *      "parent",
 *      "one",
 *    },
 *    "kinds"   = {
 *      "any",
 *    },
 *    "access"  = "update",
 *  },
 * )
 */
class EditItem extends FolderShareCommandBase {

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
    //
    // Get the selected item. If none, use the parent.
    $ids = $this->getSelectionIds();
    if (empty($ids) === TRUE) {
      $id = $this->getParentId();
    }
    else {
      $id = reset($ids);
    }

    return Url::fromRoute(
      'entity.foldershare.edit',
      [
        'foldershare' => $id,
      ]);
  }

}
