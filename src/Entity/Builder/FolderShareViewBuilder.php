<?php

namespace Drupal\foldershare\Entity\Builder;

use Drupal\Component\Utility\Html;

use Drupal\Core\Entity\EntityViewBuilder;

/**
 * Defines a builder for views of a FolderShare entity.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This class helps to build an entity view. It supports special behavior
 * based upon the view mode:
 *
 * - 'search_index': the build is simplified to omit fields that index
 *   poorly, such as numbers, dates, views, and user interface components.
 *
 * - 'search_result': the build is simplified to omit fields that do not
 *   convey much "information scent", such as numeric IDs, views, and
 *   user interface components.
 *
 * - 'full' and other view modes: the build creates a human-readable view
 *   of the entity, including all configured fields and pseudo-fields.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShare
 * @see \Drupal\foldershare\Entity\Controller\FolderShareViewController
 */
class FolderShareViewBuilder extends EntityViewBuilder {

  /*---------------------------------------------------------------------
   *
   * Build.
   *
   * These functions build the indicated components of an entity view.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function buildComponents(
    array &$page,
    array $entities,
    array $displays,
    $viewMode) {
    //
    // The parent class does most of the work to build a renderable array
    // with information about fields. This is primarily done as
    // a render-time callback that calls build() and then buildComponents().
    //
    // Insure there is something to build for.
    if (empty($entities) === TRUE) {
      return;
    }

    //
    // Adjust for search
    // -----------------
    // Search operations have two special view modes:
    // - 'search_index' = generate keywords for indexing.
    // - 'search_result' = generate content for a search result page.
    //
    // These require special handling to reduce the presentation to only
    // that appropriate for these operations.
    switch ($viewMode) {
      case 'search_index':
        // Generate keywords for a search index.
        //
        // Pseudo-fields (e.g. folder path and contents) automatically
        // skip generating content for the search view modes. We don't
        // need to handle that here.
        //
        // Loop through all of the displays and hide anything that doesn't
        // make sense when generating search index keywords.
        foreach ($displays as $display) {
          foreach ($display->getComponents() as $name => $options) {
            // Hide the field label, if it has one.
            if (isset($options['label']) === TRUE) {
              $options['label'] = 'hidden';
              $display->setComponent($name, $options);
            }

            // Hide well-known fields containing numeric IDs (e.g. id),
            // numeric values (e.g. size), dates (e.g. created), and
            // internal keywords (e.g. kind).
            switch ($name) {
              case 'id':
              case 'uid':
              case 'uuid':
              case 'parentid':
              case 'rootid':
              case 'langcode':
              case 'created':
              case 'changed':
              case 'size':
              case 'file':
              case 'kind':
              case 'grantauthoruids':
              case 'grantviewuids':
              case 'grantdisableduids':
                // Remove the field from display and continue on to
                // the next field.
                $display->removeComponent($name);
                continue 2;
            }

            // Hide pseudo-fields, which have an unset 'type'. Pseudo-fields
            // are used for a folder path and the folder contents table.
            if (empty($options['type']) === TRUE) {
              $display->removeComponent($name);
              continue;
            }

            // Hide fields that would be rendered as something that
            // doesn't contribute well to a search index, such as colors,
            // dates, numbers, and widgets.
            switch ($options['type']) {
              case '':
              case 'actions':
              case 'ajax':
              case 'button':
              case 'checkbox':
              case 'checkboxes':
              case 'color':
              case 'contextual_links':
              case 'contextual_links_placeholder':
              case 'date':
              case 'datelist':
              case 'datetime':
              case 'dropbutton':
              case 'entity_autocomplete':
              case 'field_ui_table':
              case 'hidden':
              case 'image_button':
              case 'inline_template':
              case 'language_configuration':
              case 'machine_name':
              case 'more_link':
              case 'number':
              case 'operations':
              case 'pager':
              case 'password':
              case 'password_confirm':
              case 'radio':
              case 'radios':
              case 'range':
              case 'responsive_image':
              case 'search':
              case 'select':
              case 'submit':
              case 'system_compact_link':
              case 'tel':
              case 'token':
              case 'toolbar':
              case 'toolbar_item':
              case 'weight':
              case 'view':
                $display->removeComponent($name);
                break;
            }
          }
        }
        break;

      case 'search_result':
        // Generate abbreviated output for a search result page.
        //
        // Pseudo-fields (e.g. folder path and contents) automatically
        // skip generating content for the search view modes. We don't
        // need to handle that here.
        //
        // Loop through all of the displays and hide anything that doesn't
        // make sense generating search result snippets.
        foreach ($displays as $display) {
          foreach ($display->getComponents() as $name => $options) {
            // Hide well-known fields containing numeric IDs (e.g. id),
            // numeric values (e.g. size), and internal keywords (e.g. kind).
            //
            // This is similar to, but less restrictive than the list
            // above for search indexes. For instance, this list allows
            // dates.
            switch ($name) {
              case 'id':
              case 'uid':
              case 'uuid':
              case 'parentid':
              case 'rootid':
              case 'langcode':
              case 'size':
              case 'file':
              case 'kind':
              case 'grantauthoruids':
              case 'grantviewuids':
              case 'grantdisableduids':
                // Remove the field from display and continue on to
                // the next field.
                $display->removeComponent($name);
                continue 2;
            }

            // Hide pseudo-fields, which have an unset 'type'. Pseudo-fields
            // are used for a folder path and the folder contents table.
            if (empty($options['type']) === TRUE) {
              $display->removeComponent($name);
              continue;
            }

            // Hide fields that would be rendered as something that
            // doesn't contribute well to a search result list, such as colors,
            // dates, numbers, and widgets.
            //
            // This list is similar to, but less restrictive than the list
            // above for search indexes. For instance, this list allows
            // dates and telephone numbers.
            switch ($options['type']) {
              case 'actions':
              case 'ajax':
              case 'button':
              case 'checkbox':
              case 'checkboxes':
              case 'color':
              case 'contextual_links':
              case 'contextual_links_placeholder':
              case 'dropbutton':
              case 'entity_autocomplete':
              case 'field_ui_table':
              case 'hidden':
              case 'image_button':
              case 'inline_template':
              case 'language_configuration':
              case 'machine_name':
              case 'more_link':
              case 'number':
              case 'operations':
              case 'pager':
              case 'password':
              case 'password_confirm':
              case 'radio':
              case 'radios':
              case 'range':
              case 'responsive_image':
              case 'search':
              case 'select':
              case 'submit':
              case 'system_compact_link':
              case 'token':
              case 'toolbar':
              case 'toolbar_item':
              case 'weight':
              case 'view':
                $display->removeComponent($name);
                break;
            }
          }
        }
        break;
    }

    //
    // Build defaults
    // --------------
    // Let the parent class build everything normally.
    parent::buildComponents($page, $entities, $displays, $viewMode);

    //
    // Additions
    // ---------
    // Add the langcode field.
    foreach ($entities as $id => $entity) {
      $display = $displays[$entity->bundle()];
      if ($display->getComponent('langcode') !== NULL) {
        $page[$id]['langcode'] = [
          '#type'   => 'item',
          '#title'  => t('Language'),
          '#markup' => Html::escape($entity->language()->getName()),
          '#prefix' => '<div id="field-language-display">',
          '#suffix' => '</div>',
        ];
      }
    }
  }

}
