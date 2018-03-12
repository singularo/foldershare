<?php

namespace Drupal\foldershare\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterBase;

use Drupal\foldershare\Entity\FolderShare;

/**
 * Defines a formatter plugin to format size fields in bytes.
 *
 * FolderShare entities contain an integer 'size' field that records the
 * storage space required (in bytes) for a file or for a folder and all of
 * its child files. This field formatter presents this size, summarized
 * with suffixes for bytes, KB, MB, GB, and so forth.
 *
 * @internal
 * This field formatter is modeled after the "FileSize" formatter in
 * the Drupal core "File" module, which does the same thing but is
 * constrained to "File" entities and their "filesize" fields.
 * @endinternal
 *
 * @ingroup foldershare
 *
 * @FieldFormatter(
 *   id    = "foldershare_storage_size",
 *   label = @Translation("FolderShare storage size"),
 *   field_types = {
 *     "integer"
 *   }
 * )
 */
class FolderShareStorageSize extends FormatterBase {

  /*---------------------------------------------------------------------
   *
   * Configuration.
   *
   * These functions set up formatter features and default settings.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $fieldDef) {
    // The field must be for a FolderShare entity and its 'size' field.
    // Without these restrictions, the formatter could show up on the
    // UI menu for any integer field, which is not appropriate.
    return $fieldDef->getTargetEntityTypeId() === FolderShare::ENTITY_TYPE_ID &&
      $fieldDef->getName() === 'size';
  }

  /*---------------------------------------------------------------------
   *
   * View.
   *
   * These functions present a field's value.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // Loop through all items and generate corresponding markup.
    // This uses the standard Drupal core 'format_size' function to convert an
    // integer value to a string with an appropriate byte amount suffix.
    $elements = [];
    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#markup' => format_size($item->value, $langcode),
      ];
    }

    return $elements;
  }

}
