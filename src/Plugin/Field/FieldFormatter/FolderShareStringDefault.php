<?php

namespace Drupal\foldershare\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;

use Drupal\foldershare\Plugin\Field\FieldFormatter\FolderShareStringFormatterBase;

/**
 * Formats a FolderShare entity string value with a MIME type icon and link.
 *
 * For views in particular, FolderShare entities may be listed by name.
 * This formatter formats those names to optionally include (1) a MIME-type
 * based icon (folder, file, etc.) and a link to the entity.
 *
 * This field formatter is constrained to FolderShare entities and their
 * 'name' fields.
 *
 * @FieldFormatter(
 *   id    = "foldershare_string_default",
 *   label = @Translation("FolderShare entity link"),
 *   field_types = {
 *     "string"
 *   }
 * )
 */
class FolderShareStringDefault extends FolderShareStringFormatterBase {

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
    // The entity containing the field to be formatted must be
    // a FolderShare entity, and the field must be the 'name' field.
    return parent::isApplicable($fieldDef) &&
      $fieldDef->getName() === 'name';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDescription() {
    return t('Show a link to the entity, including the name and MIME-type icon.');
  }

}
