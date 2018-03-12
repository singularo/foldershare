<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Core\Form\FormStateInterface;

use Drupal\foldershare\Utilities;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;

/**
 * Defines a command plugin to delete files or folders.
 *
 * The command deletes all selected entities. Deletion recurses and
 * deletes all folder content as well.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 * - 'selectionIds': selected entities to delete.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_delete",
 *  label       = @Translation("Delete"),
 *  title       = @Translation("Delete @operand"),
 *  menu        = @Translation("Delete @operand..."),
 *  tooltip     = @Translation("Delete files and folders"),
 *  description = @Translation("Delete files and folders, and all of their content."),
 *  category    = "delete",
 *  weight      = 10,
 *  parentConstraints = {
 *    "kinds"   = {
 *      "none",
 *      "any",
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
 *    "access"  = "delete",
 *  },
 * )
 */
class DeleteItem extends FolderShareCommandBase {

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
    // all of the kinds and delete each item of each kind.
    $nItems = 0;
    $firstItem = NULL;
    $selection = $this->getSelection();
    foreach ($selection as $items) {
      foreach ($items as $item) {
        $item->delete();

        // Keep track of the number of items and the first item.
        ++$nItems;
        if ($firstItem === NULL) {
          $firstItem = $item;
        }
      }
    }

    // Return a status message. Be specific if practical.
    if ($nItems === 1) {
      // One item. Refer to it by name.
      $this->addExecuteMessage(t(
        'The @kind "@name" has been deleted.',
        [
          '@kind' => Utilities::mapKindToTerm($firstItem->getKind()),
          '@name' => $firstItem->getName(),
        ]),
        'status');
    }
    elseif (count($selection) === 1) {
      // One kind of items. Refer to them by kind.
      $this->addExecuteMessage(t(
        '@number @kinds have been deleted.',
        [
          '@number' => $nItems,
          '@kinds'  => Utilities::mapKindToTerms($firstItem->getKind()),
        ]),
        'status');
    }
    else {
      // Multiple kinds of items.
      $this->addExecuteMessage(t(
        '@number items have been deleted.',
        [
          '@number' => $nItems,
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
    return 'Delete';
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
            'Delete this @operand, including all of its contents? This cannot be undone.',
            [
              '@operand' => $operandName,
            ]);
        }
        else {
          $description = t(
            'Delete this @operand? This cannot be undone.',
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
            'Delete these @operand, including all of their contents? This cannot be undone.',
            [
              '@operand' => $operandName,
            ]);
        }
        else {
          $description = t(
            'Delete the @operand? This cannot be undone.',
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
          'Delete these @operand, including the contents of all folders? This cannot be undone.',
          [
            '@operand' => t('items'),
          ]);
      }
      else {
        $description = t(
          'Delete these @operand? This cannot be undone.',
          [
            '@operand' => t('items'),
          ]);
      }
    }

    return $description;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $formState) {

    // Build a form to prompt for confirmation.
    $def = $this->getPluginDefinition();
    $selection = $this->getSelection();

    $form['#attributes']['class'][] = 'confirmation';
    $form['#theme'] = 'confirm_form';
    $form['#title'] = Utilities::createTitle($def['title'] . '?', $selection);
    $form['description'] = [
      '#type'  => 'html_tag',
      '#tag'   => 'p',
      '#value' => $this->getDescription(),
    ];

    $form['actions'] = [
      '#type'          => 'actions',
      'submit'         => [
        '#type'        => 'submit',
        '#value'       => t($this->getSubmitButtonName()),
        '#button_type' => 'primary',
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
