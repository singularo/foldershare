<?php

namespace Drupal\foldershare\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;

use Drupal\views\Views;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Form\ViewEnhancedUI;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Creates a page showing a user interface and a list of folders.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This controller's methods create a page body with an "enhanced user
 * interface" form and an embedded view that shows a list of root folders.
 *
 * The root folders listed depend upon the page method called, and the
 * underlying embedded view:
 *
 * - allPage(): show all root folders, regardless of ownership.
 *
 * - personalPage(): show all root folders owned by the current user.
 *
 * - publicPage(): show all root folders owned by or shared with anonymous.
 *
 * - sharedPage(): show all root folders shared with the current user.
 *
 * The view used is also influenced by the current module settings to
 * enable sharing, and enable sharing with anonymous.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Form\ViewEnhancedUI
 * @see \Drupal\foldershare\Settings
 */
class ListPage extends ControllerBase {

  /*--------------------------------------------------------------------
   *
   * Constants.
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

  /**
   * The view for all listings.
   *
   * @var string
   */
  const VIEW_NAME = 'foldershare_lists';

  /**
   * The display name listing all content.
   *
   * Used for the 'All' list.
   *
   * @var string
   */
  const DISPLAY_ALL = 'all_top_folders';

  /**
   * The display name listing content owned by a user.
   *
   * Used for the 'Personal' list.
   *
   * @var string
   */
  const DISPLAY_USER_OWNED = 'user_owned_top_folders';

  /**
   * The display name listing content owned by or shared with a user.
   *
   * Unused.
   *
   * @var string
   */
  const DISPLAY_USER_OWNED_OR_SHARED = 'user_owned_or_shared_top_folders';

  /**
   * The display name listing content shared with the user that they don't own.
   *
   * Used for the 'Shared' list if sharing is enabled.
   *
   * @var string
   */
  const DISPLAY_USER_SHARED = 'user_shared_with_top_folders';

  /**
   * The display name listing content owned by anonymous.
   *
   * Used for the 'Public' list if sharing with anonymous is disabled.
   *
   * @var string
   */
  const DISPLAY_ANONYMOUS_OWNED = 'anonymous_owned_top_folders';

  /**
   * The display name listing content owned by or shared with anonymous.
   *
   * Used for the 'Public' list if sharing with anonymous is enabled.
   *
   * @var string
   */
  const DISPLAY_ANONYMOUS_OWNED_OR_SHARED = 'anonymous_owned_or_shared_top_folders';

  /*--------------------------------------------------------------------
   *
   * Construction
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a new page.
   */
  public function __construct() {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static();
  }

  /*--------------------------------------------------------------------
   *
   * Utilities.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns TRUE if the current user has admin permission.
   *
   * @return bool
   *   Returns TRUE if the current user is a site or content administrator.
   */
  private function isAdmin() {
    // If the user is not an administrator, deny access to the page.
    $entityType = \Drupal::entityTypeManager()->getDefinition(
      FolderShare::ENTITY_TYPE_ID);

    $perm = $entityType->getAdminPermission();
    if (empty($perm) === TRUE) {
      $perm = Constants::ADMINISTER_PERMISSION;
    }

    $account = \Drupal::currentUser();
    return AccessResult::allowedIfHasPermission($account, $perm)->isAllowed();
  }

  /**
   * Returns TRUE if the current user is anonymous (unauthenticated).
   *
   * @return bool
   *   Returns TRUE if the current user is unauthenticated.
   */
  private function isAnonymous() {
    return \Drupal::currentUser()->isAnonymous();
  }

  /*--------------------------------------------------------------------
   *
   * Pages.
   *
   *--------------------------------------------------------------------*/

  /**
   * Builds and returns a page listing all content.
   *
   * The view associated with this page lists all root folders owned by
   * anybody, regardless of share settings.
   *
   * This page is only available to site or content administrators.
   * An access denied exception is thrown for all other users.
   *
   * The page includes an enhanced user interface above an embeded view.
   *
   * @return array
   *   A renderable array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws an exception if the user is not an administrator.
   */
  public function allPage() {
    if ($this->isAdmin() === FALSE) {
      // Not an admin. Access denied.
      throw new AccessDeniedHttpException();
    }

    return $this->buildPage(self::VIEW_NAME, self::DISPLAY_ALL);
  }

  /**
   * Builds and returns a page listing content owned by or shared with the user.
   *
   * If the current user is anonymous, this method forwards to publicPage().
   *
   * The view associated with this page lists all root folders owned by
   * the current user.
   *
   * The page includes an enhanced user interface above an embeded view.
   *
   * @return array
   *   A renderable array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws an exception if the the view display does not allow access.
   *
   * @see ::publicPage()
   * @see ::ownedPage()
   */
  public function personalPage() {
    if ($this->isAnonymous() === TRUE) {
      // Anonymous user.
      return $this->publicPage();
    }

    return $this->buildPage(self::VIEW_NAME, self::DISPLAY_USER_OWNED);
  }

  /**
   * Builds and returns a page listing content owned by/shared with anonymous.
   *
   * When the module's sharing settings are enabled AND sharing with anonymous
   * is enabled, the view associated with this page lists all root folders
   * owned by or shared with anonymous.
   *
   * When the module's sharing settings are disabled for the site or for
   * sharing with anonymous, the view associated with this page lists all
   * root folders owned by anonymous. Root folders marked as shared with
   * anonymous are not listed.
   *
   * The page includes an enhanced user interface above an embeded view.
   *
   * @return array
   *   A renderable array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws an exception if the the view display does not allow access.
   */
  public function publicPage() {
    // Authenticated user or anonymous.
    if (Settings::getSharingAllowed() === TRUE &&
        Settings::getSharingAllowedWithAnonymous() === TRUE) {
      // Sharing enabled. Show content owned by or shared with user.
      $displayName = self::DISPLAY_ANONYMOUS_OWNED_OR_SHARED;
    }
    else {
      // Sharing disabled. Show content owned by user.
      $displayName = self::DISPLAY_ANONYMOUS_OWNED;
    }

    return $this->buildPage(self::VIEW_NAME, $displayName);
  }

  /**
   * Builds and returns a page listing content shared with the user.
   *
   * If the current user is anonymous, this method forwards to publicPage().
   *
   * When the module's sharing settings are enabled for the site, the view
   * associated with this page lists all root folders shared with the
   * current user, but not owned by them.
   *
   * When the module's sharing settings are disabled for the site, an
   * error message is shown.
   *
   * The page includes an enhanced user interface above an embeded view.
   *
   * @return array
   *   A renderable array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws an exception if the the view display does not allow access.
   */
  public function sharedPage() {
    if ($this->isAnonymous() === TRUE) {
      // Anonymous user.
      return $this->publicPage();
    }

    // Sharing allowed?
    if (Settings::getSharingAllowed() === FALSE) {
      return [
        '#type' => 'html_tag',
        '#tag'  => 'p',
        '#value' => t('File and folder sharing has been disabled by the site administrator.'),
      ];
    }

    return $this->buildPage(self::VIEW_NAME, self::DISPLAY_USER_SHARED);
  }

  /**
   * Builds and returns a renderable array describing a view page.
   *
   * Arguments name the view and display to use. If the view or display
   * do not exist, a 'misconfigured web site' error message is logged and
   * the user is given a generic error message. If the display does not
   * allow access, an access denied exception is thrown.
   *
   * Otherwise, a page is generated that includes a user interface above
   * an embed of the named view and display.
   *
   * @param string $viewName
   *   The name of the view to embed in the page.
   * @param string $displayName
   *   The name of the view display to embed in the page.
   *
   * @return array
   *   A renderable array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws an exception if the named display's access controls
   *   do not allow access.
   */
  private function buildPage(string $viewName, string $displayName) {

    // View setup
    // ----------
    // Find the embedded view and display, confirming that both exist and
    // that the user has access. Generate errors if something is wrong.
    $error = FALSE;
    $errorMessage = t('The web site has encountered a problem with this page. Please report this to the web master.');
    $view = NULL;

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
      // User does not have access. Access denied.
      throw new AccessDeniedHttpException();
    }

    if ($error === TRUE) {
      return [
        '#attached' => [
          'library' => [
            Constants::LIBRARY_MODULE,
          ],
        ],
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

        // Return an error message.
        'error'     => [
          '#type'   => 'item',
          '#markup' => $errorMessage,
        ],
      ];
    }

    //
    // Non-enhanced user interface
    // ---------------------------
    // When the enhanced UI is disabled, revert to the base user interface
    // included with the embedded view. Just add the view to the page, and
    // no Javascript-based enhanced UI.
    if (self::ENABLE_ENHANCED_UI === FALSE) {
      return [
        '#theme'        => Constants::THEME_VIEW,
        '#attached'     => [
          'library'     => [
            Constants::LIBRARY_MODULE,
          ],
        ],
        '#attributes'   => [
          'class'       => [
            Constants::MODULE . '-view-page',
          ],
        ],

        // Do not cache this page. If anybody adds or removes a folder or
        // changes sharing, the view will change and the page needs to
        // be regenerated.
        '#cache' => [
          'max-age' => 0,
        ],

        // Show the view alone. It includes a base UI at the top.
        'view'          => [
          '#type'       => 'view',
          '#embed'      => TRUE,
          '#name'       => $viewName,
          '#display_id' => $displayName,
        ],
      ];
    }

    //
    // Enhanced user interface
    // -----------------------
    // When the enhanced UI is enabled, attach Javascript and the enhanced UI
    // form before the view. The form adds hidden fields used by the
    // Javascript.
    return [
      '#theme'        => Constants::THEME_VIEW,
      '#attached'     => [
        'library'     => [
          Constants::LIBRARY_MODULE,
          Constants::LIBRARY_ENHANCEDUI,
        ],
        'drupalSettings' => [
          Constants::MODULE . '-view-page' => [
            'viewName'        => $viewName,
            'displayName'     => $displayName,
            'viewAjaxEnabled' => $view->ajaxEnabled(),
          ],
        ],
      ],
      '#attributes'   => [
        'class'       => [
          Constants::MODULE . '-view-page',
        ],
      ],

      // Do not cache this page. If anybody adds or removes a folder or
      // changes sharing, the view will change and the page needs to
      // be regenerated.
      '#cache' => [
        'max-age' => 0,
      ],

      // Include the enhanced UI.
      'form'          => $this->formBuilder()->getForm(
        ViewEnhancedUI::class,
        (-1)),

      // Include the view with the base UI that will be overridden by
      // the enhanced UI.
      'view'          => [
        '#type'       => 'view',
        '#embed'      => TRUE,
        '#name'       => $viewName,
        '#display_id' => $displayName,
      ],
    ];
  }

}
