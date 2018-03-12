<?php

namespace Drupal\foldershare\Plugin\Field\FieldFormatter;

use Drupal\foldershare\Plugin\Field\FieldFormatter\FolderShareReferenceFormatterBase;

/**
 * Formats a FolderShare entity reference with a MIME type icon and link.
 *
 * The formatter is applicable to any entity reference that points to
 * a FolderShare entity.
 *
 * @FieldFormatter(
 *   id    = "foldershare_reference_default",
 *   label = @Translation("FolderShare entity link"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class FolderShareReferenceDefault extends FolderShareReferenceFormatterBase {

}
