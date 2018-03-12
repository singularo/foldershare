<?php

namespace Drupal\foldershare\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

use Drupal\foldershare\Plugin\Field\FieldFormatter\FolderShareStringFormatterBase;

/**
 * Formats a FolderShare MIME type with a MIME type icon and link.
 *
 * This field formatter presents a MIME type by optinally replacing the
 * text with an icon (added via CSS). The text or icon may be linked
 * to the entity.
 *
 * This field formatter is constrained to FolderShare entities and their
 * 'mime' fields.
 *
 * @FieldFormatter(
 *   id    = "foldershare_string_mimeicon",
 *   label = @Translation("FolderShare MIME-type icon"),
 *   field_types = {
 *     "string"
 *   }
 * )
 */
class FolderShareStringMIMEIcon extends FolderShareStringFormatterBase {

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
    // a FolderShare entity, and the field must be the 'mime' field.
    return parent::isApplicable($fieldDef) &&
      $fieldDef->getName() === 'mime';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDescription() {
    return t('Show a link to the entity, including the MIME-type icon.');
  }

  /*---------------------------------------------------------------------
   *
   * View.
   *
   * These functions present a field's value.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the text for the formatted field.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   An item to return a value for.
   * @param string $langCode
   *   The target language.
   * @param bool $doIcon
   *   TRUE if an icon will be included in the formatting.
   * @param bool $doLink
   *   TRUE if a link will be included in the formatting.
   *
   * @return string
   *   The text value to be formatted.
   */
  protected function getIconLinkText(
    FieldItemInterface $item,
    $langCode,
    bool $doIcon,
    bool $doLink) {
    //
    // When there is an icon being shown, omit the value.
    if ($doIcon === TRUE) {
      return '';
    }

    // Return the field value, whatever it is.
    return $item->value;
  }

}
