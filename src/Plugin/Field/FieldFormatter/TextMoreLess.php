<?php

namespace Drupal\foldershare\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formats a text field to support more/less links to open/close the field.
 *
 * For FolderShare entity descriptions, and other potentially large text
 * fields, this field formatter adds a more/less link that, when clicked
 * upon, expands/shrinks the field. Expansion grows the field to its full
 * size, while shrinking reduces it to a height chosen by the site
 * administrator when configuring this field formatter.
 *
 * The more/less links may be styled and shown as text, as buttons, or
 * whatever the site requires.
 *
 * @FieldFormatter(
 *   id = "text_moreless",
 *   label = @Translation("Text with more/less buttons"),
 *   field_types = {
 *     "text",
 *     "text_long",
 *     "text_with_summary"
 *   }
 * )
 */
class TextMoreLess extends FormatterBase {

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
      'cropHeight' => '8em',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $fieldDef) {
    // Allow the formatter on any text field.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $cropHeight = $this->getSetting('cropHeight');

    if (empty($cropHeight) === TRUE) {
      $summary[] = t('No crop & disabled more/less buttons');
    }
    else {
      $summary[] = t(
        'Heights above @height cropped & more/less buttons shown',
        [
          '@height' => $cropHeight,
        ]);
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
    return t('Show cropped text with more/less buttons to show more or less.');
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $formState) {
    //
    // Start with the parent form.
    $form = parent::settingsForm($form, $formState);

    // Add a description.
    $form['description'] = [
      '#type'   => 'item',
      '#markup' => $this->getDescription(),
    ];

    // Add a text field to enter the minimum height.
    $form['cropHeight'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Minimum height of text'),
      '#default_value' => $this->getSetting('cropHeight'),
      '#description'   => $this->t("Select a minimum height for the field. If the field's content is larger, it will be cropped to this height and More/Less buttons shown. Heights may be in pixels, ems, etc. An empty height disables cropping."),
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
    // The $items array has a list of items to format. We need to return
    // an array with identical indexing and corresponding render elements
    // for those items.
    if (empty($items) === TRUE) {
      return [];
    }

    // Get settings.
    $cropHeight = $this->getSetting('cropHeight');

    // Loop through items.
    $build = [];
    if (empty($cropHeight) === TRUE) {
      // There is no cropping, so show the text as-is.
      foreach ($items as $delta => $item) {
        $build[$delta] = [
          '#type'     => 'processed_text',
          '#text'     => $item->value,
          '#format'   => $item->format,
          '#langcode' => $item->getLangcode(),
        ];
      }

      return $build;
    }

    // There is cropping requested. Nest the text and add more/less buttons
    // and a behavior script.
    foreach ($items as $delta => $item) {
      $build[$delta] = [
        '#type'     => 'container',
        '#attributes' => [
          'class'     => ['foldershare-text-moreless'],
        ],
        '#attached'   => [
          'library'   => ['foldershare/foldershare.module.text.moreless'],
        ],
        'text'          => [
          '#type'       => 'container',
          '#attributes' => [
            'class'     => ['foldershare-text'],
            'data-foldershare-text-crop-height' => $cropHeight,
          ],
          'processedtext' => [
            '#type'     => 'processed_text',
            '#text'     => $item->value,
            '#format'   => $item->format,
            '#langcode' => $item->getLangcode(),
          ],
        ],
        'more'        => [
          '#type'     => 'html_tag',
          '#tag'      => 'div',
          '#value'    => '<span>' . $this->t('More...') . '</span>',
          '#attributes' => [
            'class'     => ['foldershare-text-more'],
          ],
        ],
        'less'        => [
          '#type'     => 'html_tag',
          '#tag'      => 'div',
          '#value'    => '<span>' . $this->t('Less...') . '</span>',
          '#attributes' => [
            'class'     => ['foldershare-text-less'],
          ],
        ],
      ];
    }

    return $build;
  }

}
