<?php

namespace Drupal\foldershare\Entity\Builder;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\LinkGeneratorTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

use Drupal\foldershare\Constants;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Builds a page breadcrumb showing the ancestors of the given entity.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This class builds a breadcrumb that includes a chain of links to
 * each of the ancestors of the given entity. The chain starts with a link
 * to the site's home page. This is followed by a link to the canonical
 * root folder list page. The remaining links lead to ancestors of the
 * entity. Per Drupal convention, the entity itself is not included on
 * the end of the breadcrumb.
 *
 * <b>Service:</b>
 * A service for this breadcrumb builder should be registered in
 * MODULE.services.yml.
 *
 * <b>Parameters:</b>
 * The service for this breadcrumb builder must pass a FolderShare entity.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShare
 */
class FolderShareBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;
  use LinkGeneratorTrait;

  /*---------------------------------------------------------------------
   *
   * Fields.
   *
   * These fields cache values from construction and dependency injection.
   *
   *---------------------------------------------------------------------*/

  /**
   * The entity storage manager, set at construction time.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityStorage;

  /**
   * The current user account, set at construction time.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /*---------------------------------------------------------------------
   *
   * Construct.
   *
   * These functions create an instance of the breadcrumb builder using
   * dependency injection.
   *
   *---------------------------------------------------------------------*/

  /**
   * Constructs the bread crumb builder.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entityManager
   *   The entity manager service for the FolderShare entity type.
   * @param \Drupal\Core\Access\AccessManagerInterface $accessManager
   *   The access manager service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   */
  public function __construct(
    EntityManagerInterface $entityManager,
    AccessManagerInterface $accessManager,
    AccountInterface $account) {

    $this->entityStorage = $entityManager->getStorage(FolderShare::ENTITY_TYPE_ID);
    $this->account       = $account;
  }

  /*---------------------------------------------------------------------
   *
   * Build.
   *
   * These functions create the breadcrumb.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $routeMatch) {
    // Return TRUE and indicate we can build the breadcrumb only if
    // the route includes a FolderShare entity.
    $entity = $routeMatch->getParameter(FolderShare::ENTITY_TYPE_ID);
    return ($entity instanceof FolderShareInterface === TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $routeMatch) {

    //
    // Cache control
    // -------------
    // Breadcrumbs vary per user because viewing permissions
    // vary per user role and per folder.
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheableDependency($this->account);

    //
    // Home page
    // ---------
    // The first link goes to the site's home page.
    $links   = [];
    $links[] = Link::createFromRoute($this->t('Home'), '<front>');

    //
    // Root folder list
    // ----------------
    // The second link goes to the personal root folder list.
    $p = \Drupal::service('router.route_provider');
    $title = $p->getRouteByName(Constants::ROUTE_PERSONAL_ROOT_LIST)->getDefault('_title');
    $links[] = Link::createFromRoute(
      $title,
      Constants::ROUTE_PERSONAL_ROOT_LIST);

    // If there is no FolderShare entity parameter, then stop here. There is
    // no ancestor path that can be added.
    $item = $routeMatch->getParameter(FolderShare::ENTITY_TYPE_ID);
    if ($item === NULL) {
      $breadcrumb->setLinks($links);
      return $breadcrumb;
    }

    //
    // FolderShare entities
    // --------------------
    // When VIEWing a file or folder, the breadcrumb ends with the
    // parent folder.
    //
    // When EDITing a file or folder, the breadcrumb ends with the action
    // after the item name.
    //
    // Distinguishing between these is a little awkward. VIEW routes
    // do not have a fixed title because the title comes
    // via a callback to get the item's title.  For EDIT routes,
    // there is a fixed title.
    //
    // Get IDs of ancestors of this item.  The list does not
    // include this item.  The first entry in the list is this
    // item's parent.
    $ancestorIds = $item->getAncestorFolderIds();

    // Reverse the ID list so that the first one is the root.
    $folderIds = array_reverse($ancestorIds);

    // Loop through the IDs, starting at the root. For each one,
    // add a link to the item's page.
    $ancestors = $this->entityStorage->loadMultiple($folderIds);
    $routeName = Constants::ROUTE_FOLDERSHARE;
    $routeParam = Constants::ROUTE_FOLDERSHARE_ID;

    foreach ($ancestors as $id => $ancestor) {
      // Breadcrumb cacheing also depends on this ancestor.
      $breadcrumb->addCacheableDependency($ancestor);

      // The BreadcrumbBuilderInterface that this class is implementing,
      // and the build() method in particular, is required to return a
      // Breadcrumb object. And that object is strictly a list of Link
      // objects.
      //
      // This is a problem here because an ancestor might not provide
      // 'view' access to this account. If it does not, we'd like to
      // return straight text instead of a link, since clicking on
      // the link would get an error anyway.
      //
      // Unfortunately, there is no way to do this. We must return
      // a Link, regardless of viewing permissions.
      //
      // No need to HTML escape the folder name here. This is done
      // automatically by Link.
      $links[] = Link::createFromRoute(
        $ancestor->label(),
        $routeName,
        [$routeParam => $id]);
    }

    // If the route has a title, add the item's name.
    //
    // No need to HTML escape the folder name. This is done
    // automatically by Link.
    $route = $routeMatch->getRouteObject();
    if ($route !== NULL) {
      $title = $route->getDefault('_title');
      if (empty($title) === FALSE) {
        $links[] = Link::createFromRoute(
          $item->getName(),
          $routeName,
          [$routeParam => $item->id()]);
      }
    }

    // Breadcrumbs vary per item.
    $breadcrumb->addCacheableDependency($item);

    $breadcrumb->setLinks($links);
    return $breadcrumb;
  }

}
