<?php

/**
 * @file
 * Implements theme handling for the module.
 */

use Drupal\Core\Render\Element;

/**
 * Implements hook_theme().
 *
 * This module defines templates:
 *
 * - 'foldershare' is used to show an entity and its contents.
 */
function foldershare_theme() {
  return [
    'foldershare' => [
      'render element' => 'elements',
    ],

    'foldershare_view' => [
      'render element' => 'elements',
    ],

    'foldershare_image_link' => [
      'variables' => [
        'title'      => NULL,
        'alt'        => NULL,
        'uri'        => NULL,
        'url'        => NULL,
        'width'      => NULL,
        'height'     => NULL,
        'style_name' => NULL,
        'attributes' => [],
      ],
    ],
  ];
}

/**
 * Prepares variables for the 'foldershare' template used to show a folder.
 *
 * The incoming variables are rendered from content created by
 * the folder's view controller and view builder, and derived
 * from a FolderShare entity.
 *
 * @param array $variables
 *   An input array, extended by this function, that contains a list
 *   of named variables and their values.
 */
function template_preprocess_foldershare(array &$variables) {
  //
  // Rendering adds items into the 'elements' variable array.
  //
  // Get folder or file
  // ------------------
  // Get the folder or file being processed.
  $item = $variables['elements']['#foldershare'];

  //
  // Expose
  // ------
  // Pull out selected values for optional access by a theme.
  $variables['view_mode'] = $variables['elements']['#view_mode'];
  $variables['item']      = $item;
  $variables['url']       = $item->url('canonical',
    ['language' => $item->language()]);

  //
  // Flags
  // -----
  // Add flags for state.
  $variables['page'] = ($variables['view_mode'] === 'full');

  //
  // Consolidated content
  // --------------------
  // The default template adds all the content at once.
  $variables['content'] = [];
  foreach (Element::children($variables['elements']) as $key) {
    $variables['content'][$key] = $variables['elements'][$key];
  }

  // Add ARIA role.
  $variables['attributes']['role'] = 'directory';
}

/**
 * Prepares variables for the 'foldershare_view' template used to show a view.
 *
 * The incoming variables are rendered from content created by
 * the folder's view controller and view builder, and derived
 * from a FolderShare entity.
 *
 * @param array $variables
 *   An input array, extended by this function, that contains a list
 *   of named variables and their values.
 */
function template_preprocess_foldershare_view(array &$variables) {
  //
  // Rendering adds items into the 'elements' variable array.
  //
  //
  // Consolidated content
  // --------------------
  // The default template adds all the content at once.
  $variables['content'] = [];
  foreach (Element::children($variables['elements']) as $key) {
    $variables['content'][$key] = $variables['elements'][$key];
  }

  // Add ARIA role.
  $variables['attributes']['role'] = 'directory';
}
