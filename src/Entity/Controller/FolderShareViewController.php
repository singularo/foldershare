<?php

namespace Drupal\foldershare\Entity\Controller;

use Drupal\Core\Entity\Controller\EntityViewController;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\views\Views;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Utilities;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Form\ViewEnhancedUI;

/**
 * Presents a view of a FolderShare entity.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This class builds an entity view page that presents the fields,
 * pseudo-fields, list of child entities, and user interface for
 * FolderShare entities.
 *
 * The module's MODULE.module class defines pseudo-field hooks that forward
 * to this class to define pseudo-fields for:
 *
 * - A path of ancestor entities.
 * - A table of an entity's child content and its user interface.
 *
 * The child contents table is created using an embedded view.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShare
 * @see \Drupal\foldershare\Entity\Builder\FolderShareViewBuilder
 */
class FolderShareViewController extends EntityViewController {

  /*--------------------------------------------------------------------
   *
   * Constants
   *
   *--------------------------------------------------------------------*/

  /**
   * Indicate whether to include the enhanced UI on the page.
   *
   * When TRUE, this class adds hidden form fields for use by client-side
   * Javascript to build menus and other enhanced user interface components
   * that override a non-scripted base user interface included in the
   * embedded view.
   *
   * When FALSE, the Javascript library and hidden fields are not included
   * and the page reverts back to the non-scripted base user interface
   * included in the embedded view.
   *
   * This constant is normally TRUE, but can be set to FALSE to help
   * debug the user interface.
   */
  const ENABLE_ENHANCED_UI = TRUE;

  /*---------------------------------------------------------------------
   *
   * Pseudo-fields.
   *
   * Module hooks in MODULE.module define pseudo-fields by delegating to
   * these functions.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns an array of pseudo-fields.
   *
   * Pseudo-fields show information derived from an entity, rather
   * than information stored explicitly in an entity field. We define:
   *
   * - The path of folder ancestors.
   * - The table of an entity's child content.
   *
   * @return array
   *   A nested array of pseudo-fields with keys and values that specify
   *   the characteristics and treatment of pseudo-fields.
   */
  public static function getEntityExtraFieldInfo() {
    $display = [
      'folder_path'    => [
        'label'        => t('Path'),
        'description'  => t('Names and links for ancestor folders.'),
        'weight'       => 100,
        'visible'      => TRUE,
      ],
      'folder_table'   => [
        'label'        => t('Folder contents table'),
        'description'  => t('Table of child files and folders.'),
        'weight'       => 110,
        'visible'      => TRUE,
      ],
    ];

    // The returned array is indexed by the entity type, bundle,
    // and display/form configuration.
    $entityType = FolderShare::ENTITY_TYPE_ID;
    $bundleType = $entityType;

    $extras = [];
    $extras[$entityType] = [];
    $extras[$entityType][$bundleType] = [];
    $extras[$entityType][$bundleType]['display'] = $display;

    return $extras;
  }

  /**
   * Returns a renderable description of an entity and its pseudo-fields.
   *
   * Pseudo-fields show information derived from an entity, rather
   * than information stored explicitly in an entity field. We handle:
   *
   * - The ancestor path as a string.
   * - The table of an entity's child content.
   *
   * @param array $page
   *   The initial rendering array modified by this method and returned.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being presented.
   * @param \Drupal\Core\Entity\Entity\EntityViewDisplay $display
   *   The field_ui display to use.
   * @param string $viewMode
   *   (optional) The field_ui view mode to use.
   * @param string $langcode
   *   (optional) The language code to use.
   *
   * @return array
   *   The given page array modified to contain the renderable
   *   pseudo-fields for a folder presentation.
   */
  public static function getFolderShareView(
    array &$page,
    EntityInterface $entity,
    EntityViewDisplay $display,
    string $viewMode,
    string $langcode = '') {
    //
    // There are several view modes defined through common use in Drupal core:
    //
    // - 'full' = generate a full view.
    // - 'search_index' = generate a keywords-only view.
    // - 'search_result' = generate an abbreviated search result view.
    //
    // Each of the pseudo-fields handle this in their own way.
    if ($display->getComponent('folder_path') !== NULL) {
      self::addPathPseudoField($page, $entity, $display, $viewMode, $langcode);
    }

    if ($display->getComponent('folder_table') !== NULL) {
      self::addViewPseudoField(
        $page,
        $entity,
        $display,
        $viewMode,
        $langcode);
    }

    return $page;
  }

  /**
   * Adds an ancestor path of folder links to the page array.
   *
   * Several well-defined view modes are recognized:
   *
   * - 'full' = generate a full view
   * - 'search_index' = generate a keywords-only view
   * - 'search_result' = generate an abbreviated search result view
   *
   * For the 'search_index' and 'search_result' view modes, this function
   * returns immediately without adding anything to the build. The folder
   * path has no place in a search index or in search results.
   *
   * For the 'full' view mode, and all other view modes, this function
   * returns the folder path.
   *
   * Folder names on a path are always separated by '/', per the convention
   * for web URLs, and for Linux and macOS directory paths.  There
   * is no configuration setting to change this path presentation
   * to use a '\' per Windows convention.
   *
   * If the folder parameter is NULL, a simplified path with a single
   * link to the root folder list is added to the build.
   *
   * @param array $page
   *   A render array into which we insert our element.
   * @param \Drupal\foldershare\FolderShareInterface $item
   *   (optional) The item for which we generate a path render element.
   * @param \Drupal\Core\Entity\Entity\EntityViewDisplay $display
   *   The field_ui display to use.
   * @param string $viewMode
   *   (optional) The field_ui view mode to use.
   * @param string $langcode
   *   (optional) The language code to use.
   */
  private static function addPathPseudoField(
    array &$page,
    FolderShareInterface $item = NULL,
    EntityViewDisplay $display = NULL,
    string $viewMode = 'full',
    string $langcode = '') {
    //
    // For search view modes, do nothing. The folder path has no place
    // in a search index or in search results.
    if ($viewMode === 'search_index' || $viewMode === 'search_result') {
      return;
    }

    // Get the weight for this component.
    $component = $display->getComponent('folder_path');
    if (isset($component['weight']) === TRUE) {
      $weight = $component['weight'];
    }
    else {
      $weight = 0;
    }

    $name = Constants::MODULE . '-folder-path';
    $page[$name] = [
      '#name'   => $name,
      '#type'   => 'item',
      '#prefix' => '<div class="' . $name . '">',
      '#markup' => Utilities::createFolderPath($item, ' / ', FALSE),
      '#suffix' => '</div>',
      '#weight' => $weight,
    ];
  }

  /**
   * Adds to the page a table showing child content.
   *
   * Several well-defined view modes are recognized:
   *
   * - 'full' = generate a full view
   * - 'search_index' = generate a keywords-only view
   * - 'search_result' = generate an abbreviated search result view
   *
   * For the 'search_index' and 'search_result' view modes, this function
   * returns immediately without adding anything to the build. The contents
   * view has no place in a search index or in search results.
   *
   * For the 'full' view mode, and all other view modes, this function
   * returns the contents view.
   *
   * @param array $page
   *   A render array into which we insert our element.
   * @param \Drupal\foldershare\FolderShareInterface $item
   *   (optional) The item for which we generate a contents render element.
   * @param \Drupal\Core\Entity\Entity\EntityViewDisplay $display
   *   The field_ui display to use.
   * @param string $viewMode
   *   (optional) The field_ui view mode to use.
   * @param string $langcode
   *   (optional) The language code to use.
   */
  private static function addViewPseudoField(
    array &$page,
    FolderShareInterface $item,
    EntityViewDisplay $display = NULL,
    string $viewMode = 'full',
    string $langcode = '') {

    //
    // Setup
    // -----
    // For search view modes, do nothing. The contents view has
    // no place in a search index or in search results.
    if ($viewMode === 'search_index' || $viewMode === 'search_result') {
      return;
    }

    // Get the weight for this component.
    $component = $display->getComponent('folder_table');
    $weight = 0;
    if (isset($component['weight']) === TRUE) {
      $weight = $component['weight'];
    }

    //
    // View setup
    // ----------
    // Find the embedded view and display, confirming that both exist and
    // that the user has access. Generate errors if something is wrong.
    $error        = FALSE;
    $view         = NULL;
    $viewName     = Constants::VIEW_LISTS;
    $displayName  = 'folder_contents';
    $name         = Constants::MODULE . '-folder-table';
    $disableClass = Constants::MODULE . '-nonfolder-table';

    if (($view = Views::getView($viewName)) === NULL) {
      // Unknown view!
      \Drupal::logger(Constants::MODULE)->error(t(
        'Misconfigured web site. The "@viewName" view is missing. Please check the views module configuration and, if needed, restore the view using the "@moduleName" module settings page.',
        [
          '@viewName'   => $viewName,
          '@moduleName' => Constants::MODULE,
        ]));
      $error = TRUE;
    }
    elseif ($view->setDisplay($displayName) === FALSE) {
      // Unknown display!
      \Drupal::logger(Constants::MODULE)->error(t(
        'Misconfigured web site. The "@displayName" display for view "@viewName" is missing. Please check the views module configuration and, if needed, restore the view using the "@moduleName" module settings page.',
        [
          '@viewName'    => $viewName,
          '@displayName' => $displayName,
          '@moduleName'  => Constants::MODULE,
        ]));
      $error = TRUE;
    }
    elseif ($view->access($displayName) === FALSE) {
      // Access denied to view display.
      $error = TRUE;
    }

    if ($error === TRUE) {
      $page[$name] = [
        '#attributes' => [
          'class'   => [
            Constants::MODULE . '-error',
          ],
        ],

        // Do not cache this page. If any of the above conditions change,
        // the page needs to be regenerated.
        '#cache' => [
          'max-age' => 0,
        ],

        '#weight'   => $weight,

        'error'     => [
          '#type'   => 'item',
          '#name'   => $name,
          '#markup' => t(
            'The web site has encountered a problem with this page. Please report this to the web master.'),
        ],
      ];
      return;
    }

    // If the item is NOT a folder, mark the view as disabled.
    if ($item->isFolderOrRootFolder() === FALSE) {
      $viewPrefix = '<div class="' . $disableClass . '">';
      $viewSuffix = '</div';
    }
    else {
      $viewPrefix = $viewSuffix = '';
    }

    //
    // Non-enhanced user interface
    // ---------------------------
    // When the enhanced UI is disabled, revert to the base user interface
    // included with the embedded view. Just add the view to the page, and
    // no Javascript-based enhanced UI.
    if (self::ENABLE_ENHANCED_UI === FALSE) {
      $page[$name] = [
        // Do not cache this page. If anybody adds or removes a folder or
        // changes sharing, the view will change and the page needs to
        // be regenerated.
        '#cache' => [
          'max-age'     => 0,
        ],

        '#weight'       => $weight,

        // Add the view with the base UI.
        'view'          => [
          '#type'       => 'view',
          '#embed'      => TRUE,
          '#name'       => $viewName,
          '#display_id' => $displayName,
          '#arguments'  => [$item->id()],
          '#prefix'     => $viewPrefix,
          '#suffix'     => $viewSuffix,
        ],
      ];
      return;
    }

    //
    // Enhanced user interface
    // -----------------------
    // When the enhanced UI is enabled, attach Javascript and the enhanced UI
    // form before the view. The form adds hidden fields used by the
    // Javascript.
    $page[$name] = [
      '#attached'     => [
        'drupalSettings' => [
          Constants::MODULE . '-view-page' => [
            'viewName'        => $viewName,
            'displayName'     => $displayName,
            'viewAjaxEnabled' => $view->ajaxEnabled(),
          ],
        ],
      ],
      '#weight'       => $weight,
      '#prefix'       => $viewPrefix,
      '#suffix'       => $viewSuffix,

      // Do not cache this page. If anybody adds or removes a folder or
      // changes sharing, the view will change and the page needs to
      // be regenerated.
      '#cache'        => [
        'max-age'     => 0,
      ],

      // Add the enhanced UI.
      'form'          => \Drupal::formBuilder()->getForm(
        ViewEnhancedUI::class,
        $item->id()),

      // Add the view with the base UI overridden by the enhanced UI.
      'view'          => [
        '#type'       => 'view',
        '#embed'      => TRUE,
        '#name'       => $viewName,
        '#display_id' => $displayName,
        '#arguments'  => [$item->id()],
      ],
    ];
  }

  /*---------------------------------------------------------------------
   *
   * Page.
   *
   * These functions build the entity view.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the title of the indicated entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $foldershare
   *   The entity for which the title is needed.  NOTE: This function is
   *   the target of a route with an entity ID argument. The name of the
   *   function argument here *must be* named after the entity
   *   type: 'foldershare'.
   *
   * @return string
   *   The page title.
   */
  public function title(EntityInterface $foldershare) {
    // While folders are translatable, folder names are explicit text
    // that should not be translated. Return the folder name as plain text.
    //
    // The name does not need to be HTML escaped here because the caller
    // handles that.
    return $foldershare->getName();
  }

  /**
   * Builds and returns a renderable array describing an entity.
   *
   * Each of the fields shown by the selected view mode are added
   * to the renderable array in the proper order.  Pseudo-fields
   * for the contents table and folder path are added as appropriate.
   *
   * @param \Drupal\Core\Entity\EntityInterface $foldershare
   *   The entity being shown.  NOTE: This function is the target of
   *   a route with an entity ID argument. The name of the function
   *   argument here *must be* named after the entity type: 'foldershare'.
   * @param string $viewMode
   *   (optional) The name of the view mode. Defaults to 'full'.
   *
   * @return array
   *   A Drupal renderable array.
   */
  public function view(EntityInterface $foldershare, $viewMode = 'full') {
    //
    // The parent class sets a few well-known keys:
    // - #entity_type: the entity's type (e.g. "foldershare")
    // - #ENTITY_TYPE: the entity, where ENTITY_TYPE is "foldershare"
    //
    // The parent class invokes the view builder, and both of them
    // set up pre-render callbacks:
    // - #pre_render: callbacks executed at render time
    //
    // The EntityViewController adds a callback to buildTitle() to
    // set the page title.
    //
    // The EntityViewBuilder adds a callback to build() to build
    // the fields for the page.
    //
    // Otherwise, the returned page array has nothing in it.  All
    // of the real work of adding fields is deferred until render
    // time when the builder's build() callback is called.
    $page = parent::view($foldershare, $viewMode);

    //
    // Add the theme and attach our library.
    $page['#theme'] = Constants::THEME_FOLDER;
    if (self::ENABLE_ENHANCED_UI === FALSE) {
      $page['#attached'] = [
        'library' => [
          Constants::LIBRARY_MODULE,
        ],
      ];
    }
    else {
      $page['#attached'] = [
        'library' => [
          Constants::LIBRARY_MODULE,
          Constants::LIBRARY_ENHANCEDUI,
        ],
      ];
    }

    return $page;
  }

}
