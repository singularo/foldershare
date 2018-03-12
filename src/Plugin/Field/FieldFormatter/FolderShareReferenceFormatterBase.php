<?php

namespace Drupal\foldershare\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityInterface;

use Drupal\foldershare\Entity\FolderShare;

/**
 * Provides a base class for formatting FolderShare entity references.
 *
 * Subclasses of this base class present field values that optionally
 * may be preceded with a MIME-type based icon, and linked to the entity.
 *
 * This class and FolderShareStringFormatterBase are very similar. Both
 * format a link by decorating it with a MIME type icon. The differences
 * are:
 *
 * - FolderShareStringFormatterBase formats a string value that exists
 *   on a FolderShare entity. The MIME type for the icon comes from that
 *   entity, and the link is to that entity.
 *
 * - FolderShareReferenceFormatterBase formats an entity reference value
 *   that may exist on any entity, but that points to a FolderShare
 *   entity. The MIME type of the icon, the entity name in the link text,
 *   and the link are all for that referenced entity.
 *
 * @see FolderShareStringFormatterBase
 */
class FolderShareReferenceFormatterBase extends EntityReferenceFormatterBase {

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
  public static function defaultSettings() {
    return [
      'showIcon'     => TRUE,
      'linkToEntity' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $fieldDef) {
    // The entity pointed to by the entity reference must be a
    // FolderShare entity.
    $settings = $fieldDef->getSettings();
    if (isset($settings['target_type']) === TRUE &&
        $settings['target_type'] === FolderShare::ENTITY_TYPE_ID) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $doIcon = $this->getSetting('showIcon') !== 0;
    $doLink = $this->getSetting('linkToEntity') !== 0;

    if ($doLink === TRUE) {
      $summary[] = t('Linked to referenced entity');
    }
    else {
      $summary[] = t('No link');
    }

    if ($doIcon === TRUE) {
      $summary[] = t('MIME-type icon');
    }
    else {
      $summary[] = t('No icon');
    }

    return $summary;
  }

  /*---------------------------------------------------------------------
   *
   * Settings form.
   *
   * These functions handle settings on the formatter for a particular use.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns a brief description of the formatter.
   *
   * @return string
   *   Returns a brief translated description of the formatter.
   */
  protected function getDescription() {
    return t('Show a link to the entity, including the name and MIME-type icon.');
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $formState) {
    //
    // Start with the parent form.
    $form = parent::settingsForm($form, $formState);

    // Get the entity type's label to use in the link option text.
    $entityType = \Drupal::entityManager()->getDefinition(
      FolderShare::ENTITY_TYPE_ID,
      FALSE);
    if ($entityType === NULL) {
      // Invalid entity type? Abort.
      return $form;
    }

    // Add a description.
    $form['description'] = [
      '#type'   => 'item',
      '#markup' => $this->getDescription(),
    ];

    // Add a checkbox to enable/disable linking the MIME type name or icon
    // to the underlying entity.
    $form['linkToEntity'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t(
        'Link to the @entity',
        [
          '@entity' => $entityType->getLabel(),
        ]),
      '#default_value' => $this->getSetting('linkToEntity'),
    ];

    // Add a checkbox to enable/disable replacing the MIME type's plain
    // text name with an appropriate icon.
    $form['showIcon'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Show a MIME type-based icon'),
      '#default_value' => $this->getSetting('showIcon'),
    ];

    return $form;
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
  public function viewElements(FieldItemListInterface $items, $langCode) {
    //
    // While the $items array has a list of items to format, the parent
    // class offers a method to adjust the list to reference the proper
    // language versions and to check access. The returned list is of
    // entities, not FieldItems. Entities are in the same order as items.
    $entities = $this->getEntitiesToView($items, $langCode);
    if (empty($entities) === TRUE) {
      return [];
    }

    // The $items array has a list of items to format. We need to return
    // an array with identical indexing and corresponding render elements
    // for those items.
    //
    // Get settings.
    $doIcon = $this->getSetting('showIcon') !== 0;
    $doLink = $this->getSetting('linkToEntity') !== 0;

    // Loop through items.
    $build = [];
    foreach ($entities as $delta => $entity) {
      $build[$delta] = $this->buildIconLink($entity, $langCode, $doIcon, $doLink);
    }

    return $build;
  }

  /**
   * Builds and returns a formatted field for icon-linked values.
   *
   * When given a field, language code, and booleans to enable/disable
   * MIME-type based icons and links, the function returns a render
   * element array to present that field's values.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A entity to return a value for.
   * @param string $langCode
   *   The target language.
   * @param bool $doIcon
   *   TRUE if an icon will be included in the formatting.
   * @param bool $doLink
   *   TRUE if a link will be included in the formatting.
   *
   * @return array
   *   The render element to present the field.
   */
  protected function buildIconLink(
    EntityInterface $entity,
    $langCode,
    $doIcon = FALSE,
    $doLink = FALSE) {

    //
    // Setup
    // -----
    // Get the entity's text, kind, MIME type, and URL.
    $text = $this->getIconLinkText($entity, $langCode, $doIcon, $doLink);
    $kind = $this->getIconLinkKind($entity, $langCode, $doIcon, $doLink);
    $mime = $this->getIconLinkMimeType($entity, $langCode, $doIcon, $doLink);
    $url = NULL;
    if ($doLink === TRUE) {
      $url = $this->getIconLinkURL($entity, $langCode, $doIcon, $doLink);
    }

    //
    // No icon
    // -------
    // If there is no icon to include, just return optionally linked text.
    if ($doIcon === FALSE) {
      if ($url === NULL) {
        // Present the text.
        // Raw markup needs $text to be HTML escaped.
        return [
          '#markup' => Html::escape($text),
        ];
      }

      // Present the text as a link.
      // No need to HTML escape $text - handled by 'link' type.
      return [
        '#type'      => 'link',
        '#title'     => $text,
        '#url'       => $url,
        '#cache'     => [
          'contexts' => ['url.site'],
        ],
      ];
    }

    //
    // Icon
    // ----
    // When there is an icon to include, use the Drupal Core File module's
    // conventions for classes so that standard theme CSS can add a
    // MIME-type based icon. For folders, introduce a new directory MIME
    // type.
    if ($kind === FolderShare::FOLDER_KIND) {
      $classes = [
        'file',
        'file--mime-folder-directory',
        'file--folder',
      ];
    }
    elseif ($kind === FolderShare::ROOT_FOLDER_KIND) {
      $classes = [
        'file',
        'file--mime-rootfolder-directory',
        'file--folder',
      ];
    }
    else {
      $classes = [
        'file',
        'file--mime-' . strtr(
          $mime,
          [
            '/'   => '-',
            '.'   => '-',
          ]),
        'file--' . file_icon_class($mime),
      ];
    }

    // If the text value is empty, and yet we are providing an icon,
    // then add another class and make the text non-empty so that the
    // icon shows up.
    if (empty($text) === TRUE) {
      $classes[] = 'foldershare-mimeicon';
      $text = ' ';
    }

    if ($url === NULL) {
      // Present the text with an icon.
      // Raw markup needs $text to be HTML escaped.
      return [
        '#type'       => 'html_tag',
        '#tag'        => 'span',
        '#value'      => Html::escape($text),
        '#attached'   => [
          'library'   => ['file/drupal.file'],
        ],
        '#attributes' => [
          'class'     => $classes,
        ],
      ];
    }

    // Present the text with an icon and link.
    // No need to HTML escape $text - handled by 'link' type.
    return [
      '#type'       => 'link',
      '#title'      => $text,
      '#url'        => $url,
      '#cache'      => [
        'contexts'  => ['url.site'],
      ],
      '#attached'   => [
        'library'   => ['file/drupal.file'],
      ],
      '#attributes' => [
        'class'     => $classes,
      ],
    ];
  }

  /**
   * Returns the text for the formatted field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A entity to return a value for.
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
    EntityInterface $entity,
    $langCode,
    bool $doIcon,
    bool $doLink) {
    //
    // Return the entity name.
    return $entity->getName();
  }

  /**
   * Returns the URL for the formatted field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A entity to return a value for.
   * @param string $langCode
   *   The target language.
   * @param bool $doIcon
   *   TRUE if an icon will be included in the formatting.
   * @param bool $doLink
   *   TRUE if a link will be included in the formatting.
   *
   * @return \Drupal\Core\Url
   *   The URL to the entity, or a NULL if no URL is available.
   */
  protected function getIconLinkUrl(
    EntityInterface $entity,
    $langCode,
    bool $doIcon,
    bool $doLink) {
    //
    // Use the referenced entity.
    if ($entity === NULL) {
      return NULL;
    }

    // Return a URL to the entity, or NULL.
    return $entity->toUrl();
  }

  /**
   * Returns the MIME type name for the formatted field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A entity to return a value for.
   * @param string $langCode
   *   The target language.
   * @param bool $doIcon
   *   TRUE if an icon will be included in the formatting.
   * @param bool $doLink
   *   TRUE if a link will be included in the formatting.
   *
   * @return string
   *   The MIME name for the entity.
   */
  protected function getIconLinkMimeType(
    EntityInterface $entity,
    $langCode,
    bool $doIcon,
    bool $doLink) {
    //
    // Use the referenced entity.
    if ($entity === NULL) {
      // Return a generic default.
      return 'application/octet-stream';
    }

    // Return the MIME type of the entity.
    return $entity->getMimeType();
  }

  /**
   * Returns the kind for the formatted field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A entity to return a value for.
   * @param string $langCode
   *   The target language.
   * @param bool $doIcon
   *   TRUE if an icon will be included in the formatting.
   * @param bool $doLink
   *   TRUE if a link will be included in the formatting.
   *
   * @return string
   *   The kind name for the entity.
   */
  protected function getIconLinkKind(
    EntityInterface $entity,
    $langCode,
    bool $doIcon,
    bool $doLink) {
    //
    // Use the referenced entity.
    if ($entity === NULL) {
      // Return a generic default.
      return FolderShare::FILE_KIND;
    }

    // Return the kind of the entity.
    return $entity->getKind();
  }

}
