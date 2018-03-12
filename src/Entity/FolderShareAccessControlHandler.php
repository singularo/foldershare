<?php

namespace Drupal\foldershare\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Provides access control for operations on files and folders.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * Access to a folder and its files is controlled by three mechanisms:
 *
 * - Permission-based access control.
 * - Root folder-based access control.
 * - Module settings to enable/disable sharing for the entire site.
 *
 * <b>Permission-based access control</b>
 * The module uses the standard Drupal role-based permissions mechanism and
 * these module-specific permissions:
 *
 * - "view foldershare" grants users the ability to view content.
 *
 * - "author foldershare" grants users the ablity to create, edit, and
 *   delete files and subfolders, but they cannot create or share root
 *   folders.
 *
 * - "share foldershare" grants users the ability to create root folders
 *   and share them with other users.
 *
 * - "administer foldershare" grants users the ability to view and edit
 *   anybody's content, change content ownership, execute administrative
 *   operations.
 *
 * Additionally, users designated as site administrators always have
 * full access to all content regardless of permissions.
 *
 * <b>Root folder-based access control</b>
 * Root folders have access control lists that designate specific users
 * that may view and author content. These access control lists act as a
 * boolean AND with permission access controls so that a user must have
 * both the site-wide generic view permission AND a root folder's view
 * access grant in order to view content.
 *
 * Content owners are always included in the access control lists of their
 * own content and therefore always have full view and author access.
 *
 * The generic "anonymous" user may be granted access as well. This
 * publishes content for public access.
 *
 * Access grants are always on the root folder of a folder tree, and they
 * apply to all content within that folder tree.
 *
 * <b>Module settings for access control</b>
 * The module includes site-wide settings that enable/disable all sharing,
 * or just sharing with the "anonymous" user. When sharing is disabled,
 * root folder-based access controls are shortcutted and always deny access.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShare
 */
class FolderShareAccessControlHandler extends EntityAccessControlHandler {

  /*---------------------------------------------------------------------
   *
   * Field view and edit access.
   *
   *---------------------------------------------------------------------*/

  /**
   * Fields that should never be viewed.
   *
   * These are internal fields that don't have a meaningful presentation
   * and should NEVER be shown on a page.
   *
   * Note that we *do not* exclude the module's internal parentid and
   * rootid fields. While these are not something users should normally
   * view directly, there is nothing wrong with doing so.
   */
  const FIELDS_VIEW_NEVER = [
    'langcode',
    'uuid',
    'grantdisableuids',
  ];

  /**
   * Fields that should never be edited directly.
   *
   * These are internal fields with values set programmatically. They
   * should NEVER be edited directly by a user. There are, however,
   * entity API calls that can set these while maintaining the integrity
   * of the data model.
   *
   * The 'id' and 'uuid' are assigned by Drupal and need to remain unchanged
   * for the life of the entity.
   *
   * The 'uid' is the owner of the content and should not be edited
   * directly.
   *
   * The 'parentid and 'rootid are internal fields used to connect folders
   * into a folder hierarchy. They are only changed when content is moved
   * from place to place in the hierarchy.
   *
   * The 'size' is computed automatically and should never be edited.
   *
   * The 'kind' field indicates whether the entity is a file or folder
   * and should never be edited.
   *
   * The 'file' field gives the entity ID of an underlying File object,
   * if any, and obviously should never be edited.
   *
   * The 'grantauthoruids', 'grantviewuids', and 'grantdisableuids' store
   * the user IDs of users granted specific access to view or author
   * content in a root folder. The disabled list is a courtesy list that
   * includes recently referenced users that currently do not have access.
   * Again,none of these should be edited.
   */
  const FIELDS_EDIT_NEVER = [
    'id',
    'uid',
    'uuid',
    'langcode',
    'created',
    'changed',
    'parentid',
    'rootid',
    'size',
    'kind',
    'mime',
    'file',
    'image',
    'media',
    'grantauthoruids',
    'grantviewuids',
  ];

  /*---------------------------------------------------------------------
   *
   * Construct.
   *
   *---------------------------------------------------------------------*/

  /**
   * Constructs and initializes an access control handler object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   The entity type definition.
   */
  public function __construct(EntityTypeInterface $entityType) {
    parent::__construct($entityType);

    // File and folder names (labels) may be viewed by users granted
    // permission using the generic view operation.
    $this->viewLabelOperation = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(EntityTypeInterface $entityType) {
    return new static($entityType);
  }

  /*---------------------------------------------------------------------
   *
   * Check access.
   *
   * Implements EntityAccessControlHandler and overrides selected methods.
   *
   * These are the primary entrance points for this class.
   *
   *---------------------------------------------------------------------*/

  /**
   * Checks if a user has permission for an operation on a file or folder.
   *
   * The following operations are supported:
   *
   * - 'chown'. Change ownership of the file or folder and all of its contents.
   *
   * - 'delete'.  Delete the file or folder, and all of its subfolders
   *   and files.
   *
   * - 'share'.  Change access grants to share/unshare a root folder, and
   *   its folder tree, for view or author access by other users.
   *
   * - 'update'.  Edit the file's or folder's fields.
   *
   * - 'view'.  View or copy the file's or folder's fields.
   *
   * <B>Hooks:</B>
   * Via the parent class, this method invokes the "hook_foldershare_access"
   * hook after checking permissions and access grants. This hook is not
   * invoked for administrators, which always have access.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity for which access checking is required.
   * @param string $operation
   *   The name of the operation being considered.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access.  Defaults to the
   *   current user.
   * @param bool $returnAsObject
   *   (optional) When TRUE, an access object is returned. When FALSE
   *   (default), a boolean is returned.
   *
   * @return bool|\Drupal\Core\Access\AccessResultInterface
   *   The result of the access control check.
   *
   * @see ::checkAccess()
   */
  public function access(
    EntityInterface $entity,
    $operation,
    AccountInterface $account = NULL,
    $returnAsObject = FALSE) {

    // This method, and those it calls, implement four steps for access
    // control:
    //
    // 1. If the user is a site or content administrator, they are granted
    //    immediate access for all content and all operations.
    //
    // 2. If the user does not have the specific module permission required
    //    for an operation (e.g. view, author, share), they are denied
    //    access.
    //
    // 3. If the user does not own the content and sharing is disabled for
    //    the module, they are denied access.
    //
    // 4. If the user does not own the content and they are not granted
    //    specific access by the content's root folder, they are denied
    //    access.
    //
    // Otherwise the user is granted access.
    //
    // This method handles step (1) only. It then forwards to the parent
    // class, which forwards to our checkAccess() method below which
    // handles steps (2), (3), and (4).
    $account = $this->prepareUser($account);

    //
    // Check module settings on sharing
    // --------------------------------
    // When the module has disabled sharing, two things happen:
    // - Users cannot use the 'share' operation to edit folder share settings.
    // - Users cannot access content they don't own, even if that content
    //   grants them access.
    //
    // This affects administrators too.
    //
    // So if the operation is to change share settings, and sharing has
    // been disabled, then access denied.
    if ($operation === 'share' && Settings::getSharingAllowed() === FALSE) {
      return $returnAsObject === TRUE ? AccessResult::forbidden() : FALSE;
    }

    //
    // Allow site & content administrators everything
    // ----------------------------------------------
    // Drupal insures that users marked as site administrators always have
    // all permissions. The check below, then, will always return TRUE for
    // site admins.
    //
    // Content administrators have this module's ADMINISTER_PERMISSION,
    // which grants them access to all content for all operations.
    $perm = $this->entityType->getAdminPermission();
    if (empty($perm) === TRUE) {
      $perm = Constants::ADMINISTER_PERMISSION;
    }

    $access = AccessResult::allowedIfHasPermission($account, $perm);
    if ($access->isAllowed() === TRUE) {
      // The user is either a site admin or they have the module's
      // content admin permission. Grant full access.
      $access->cachePerPermissions();
      return $returnAsObject === TRUE ? $access : $access->isAllowed();
    }

    //
    // Check entity-based ACLs
    // -----------------------
    // At this point, the user is NOT a site administrator and they do not
    // have module content admin permission. Access to the content is
    // determined now by a mix of permissions, module settings, and content
    // access controls.
    //
    // Forward to the parent class, which implements cache checks and
    // module hooks to override access controls. Afterwards the parent
    // calls our checkAccess() method to handle permissions, root foolder
    // access control lists, and module settings that govern access.
    $access = parent::access($entity, $operation, $account, TRUE);
    $access->cachePerPermissions();
    return $returnAsObject === TRUE ? $access : $access->isAllowed();
  }

  /**
   * Checks if a given user has permission to create a new entity.
   *
   * This method handles access checks for create operations.
   * Access is granted for creating root folders if:
   *
   * - The user has the administer permission OR
   * - The user has the share permission.
   *
   * Access is granted for creating files and subfolders if:
   *
   * - The user has the administer permission OR
   * - The user has the author permission AND
   * - The user has update access to the parent folder, if any.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access.  Defaults to
   *   the current user.
   * @param array $context
   *   An array of key-value pairs to pass additional context to
   *   this method, when needed.  Context must contain a single
   *   entry 'parentId' with a value of -1 for creating a root folder,
   *   or a file/folder ID for creating a child file/folder.
   * @param string|null $bundle
   *   (optional) An optional bundle of the entity, for entities that support
   *   bundles, and NULL otherwise.  Ignored by this method since
   *   file/folder entities do not support bundles.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The result of the access control check.
   *
   * @see ::checkAccess()
   */
  protected function checkCreateAccess(
    AccountInterface $account = NULL,
    array $context = [],
    $bundle = NULL) {

    //
    // Validate
    // --------
    // The context provides the essential 'parentId' value that indicates
    // if creation is to create a new root folder or a subfolder, which
    // have separate logic for access.
    //
    // If the context was not provided, we have no 'parentId' and cannot
    // determine what is or is not allowed.
    if ($context === NULL || isset($context['parentId']) === FALSE) {
      // Missing context. Access denied.
      return AccessResult::forbidden();
    }

    $account      = $this->prepareUser($account);
    $parentId     = $context['parentId'];
    $parentFolder = NULL;

    if ($parentId !== (-1)) {
      $parentFolder = FolderShare::load($parentId);
      if ($parentFolder === NULL) {
        // Invalid parent ID. Access denied.
        return AccessResult::forbidden();
      }
    }

    //
    // Allow site & content administrators everything
    // ----------------------------------------------
    // Drupal insures that users marked as site administrators always have
    // all permissions. The check below, then, will always return TRUE for
    // site admins.
    //
    // Content administrators have this module's ADMINISTER_PERMISSION,
    // which grants them access to everything.
    $perm = $this->entityType->getAdminPermission();
    if (empty($perm) === TRUE) {
      $perm = Constants::ADMINISTER_PERMISSION;
    }

    $access = AccessResult::allowedIfHasPermission($account, $perm);
    if ($access->isAllowed() === TRUE) {
      // The user is either a site admin or they have the module's
      // content admin permission. Access granted.
      return $access;
    }

    //
    // Allow share & author users based on context
    // -------------------------------------------
    // At this point, the user is NOT a site administrator and they do not
    // have module content admin permission. Create access is now determined
    // by module permissions.
    //
    // If there is no parent folder, the operation is to create a root
    // folder. This is allowed if the user has SHARE_PERMISSION.
    //
    // If there is a parent folder, the operation is to create a subfolder
    // or file. This is allowed if the user has AUTHOR_PERMISSION AND
    // the parent folder's root folder specifically allows the user access.
    $perm = '';
    if ($parentId === (-1)) {
      // Create root folder. Require SHARE_PERMISSION.
      $perm = Constants::SHARE_PERMISSION;
      $access = AccessResult::allowedIfHasPermission($account, $perm);
      if ($access->isAllowed() === FALSE) {
        // Access denied.
        return AccessResult::forbidden();
      }

      return AccessResult::allowed();
    }
    else {
      // Create file or folder. Require AUTHOR_PERMISSION and access to
      // the folder tree.
      $perm = Constants::AUTHOR_PERMISSION;
      $access = AccessResult::allowedIfHasPermission($account, $perm);
      if ($access->isAllowed() === FALSE) {
        // Access denied.
        return AccessResult::forbidden();
      }

      // Check access on the parent folder (and its root folder).
      return $this->checkAccess($parentFolder, 'update', $account);
    }
  }

  /**
   * Checks if a user has permission to view or edit a field.
   *
   * If the entity allows access (based on permissions and root folder
   * access grants), then most fields may be viewed and some may be
   * edited. Some internal fields may be viewed by administrators,
   * but not edited directly.
   *
   * The table below indicates which fields are restricted.
   * The fields themselves are defined in the FolderShare class.
   *
   * | Field            | Allow for view | Allow for edit |
   * | ---------------- | -------------- | -------------- |
   * | id               | yes            | no             |
   * | uuid             | yes            | no             |
   * | uid              | yes            | no             |
   * | langcode         | no             | yes            |
   * | created          | yes            | no             |
   * | changed          | yes            | no             |
   * | size             | yes            | no             |
   * | name             | yes            | yes            |
   * | description      | yes            | yes            |
   * | parentid         | yes            | no             |
   * | rootid           | yes            | no             |
   * | kind             | yes            | no             |
   * | file             | yes            | no             |
   * | grantauthoruids  | yes            | no             |
   * | grantviewuids    | yes            | no             |
   * | grantdisableduids| no             | no             |
   *
   * Any additional fields created by third-parties or by the field_ui
   * module can be viewed and edited.
   *
   * @param string $operation
   *   The name of the operation being considered, which is always
   *   'view' or 'edit' (defined by Drupal core).
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field being checked.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access.  Defaults to
   *   the current user.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   (optional) The list of field values for which to check access.
   *   Ignored by this method.
   *
   * @return\Drupal\Core\Access\AccessResultInterface
   *   The result of the access control check.
   */
  protected function checkFieldAccess(
    $operation,
    FieldDefinitionInterface $field,
    AccountInterface $account = NULL,
    FieldItemListInterface $items = NULL) {

    // This method is called for every field of every row when showing
    // lists of files and folders. It is therefore important that it run as
    // quickly as possible for view operations.
    $fieldName = $field->getName();
    switch ($operation) {
      case 'view':
        // Any field in FIELDS_VIEW_NEVER is NEVER viewable by any type
        // of user, including admins.
        if (in_array($fieldName, self::FIELDS_VIEW_NEVER, TRUE) === TRUE) {
          // Access denied.
          return AccessResult::forbidden();
        }

        // Access granted.
        return AccessResult::allowed();

      case 'edit':
        // Any field in FIELDS_EDIT_NEVER is NEVER editable directly by
        // any type of user, including admins.
        if (in_array($fieldName, self::FIELDS_EDIT_NEVER, TRUE) === TRUE) {
          // Access denied.
          return AccessResult::forbidden();
        }

        // Access granted.
        return AccessResult::allowed();

      default:
        // Unknown operation. Access denied.
        return AccessResult::forbidden();
    }
  }

  /**
   * Checks if a user can do an operation on a file or folder.
   *
   * This is the principal access control method for this class. It
   * checks permissions, module settings, and per-root folder access
   * control lists to decide if access is granted or denied.
   *
   * The following operations are supported:
   *
   * - 'delete'.  Delete the file or folder, and all its subfolders and files.
   *
   * - 'share'.  Change access grants to share/unshare
   *   this root folder for view or author access by other users.
   *
   * - 'update'.  Edit the file's or folder's fields.
   *
   * - 'view'.  View and copy the file's or folder's fields.
   *
   * Each of these operations require an appropriate mix of module
   * permissions, file/folder ownership, and/or access grants:
   *
   * - Delete requires that the user have module author permission AND
   *   either own the file/folder OR have been granted author access
   *   on the root folder.
   *
   * - Share requires that the user have module share permission AND
   *   own the file/folder's root folder, where access grants are stored.
   *
   * - Update requires that the user have module author permission AND
   *   either own the file/folder OR have been granted author access
   *   on the root folder.
   *
   * - View requires that the user have module view permission AND
   *   either own the file/folder OR have been granted view access
   *   on the root folder.
   *
   * - View tree requires that the user have module view permission AND
   *   either own the entire folder tree OR have been granted view access
   *   on the root folder.
   *
   * Note that update and delete only check the file/folder for access and
   * NOT all subfolders. This means that a user that is allowed to write to
   * a file/folder, can delete that file/folder and all of its contents,
   * even if they do not have write permission on all of that content.
   * Ownership of a file/folder is always an override of the per-file/folder
   * access controls.
   *
   * @param \Drupal\Core\Entity\EntityInterface $item
   *   The entity for which access checking is required.
   * @param string $operation
   *   The name of the operation being considered.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access.  Defaults to
   *   the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The result of the access control check.
   */
  protected function checkAccess(
    EntityInterface $item,
    $operation,
    AccountInterface $account = NULL) {

    //
    // Validate
    // --------
    // If the given entity is missing or invalid, we cannot check access.
    if ($item === NULL) {
      // No entity. Access denied.
      return AccessResult::forbidden();
    }

    $entityType = $item->getEntityTypeId();
    if ($entityType !== FolderShare::ENTITY_TYPE_ID) {
      // Invalid entity. Access denied.
      return AccessResult::forbidden();
    }

    // Get account info.
    $account = $this->prepareUser($account);
    $userId = (int) $account->id();
    $ownerId = (int) $item->getOwnerId();

    // Get module settings.
    $sharingEnabled = Settings::getSharingAllowed();
    $sharingWithAnonymousEnabled = Settings::getSharingAllowedWithAnonymous();

    //
    // Check access
    // ------------
    // For each known operation, check permissions, module settings,
    // and per-root folder access control grants.
    switch ($operation) {
      case 'view':
        // View content.
        //
        // Allowed if:
        // - User has view permission.
        // - And one of:
        //   - User owns the content.
        //   - User granted access AND module's sharing enabled.
        $perm = Constants::VIEW_PERMISSION;
        $access = AccessResult::allowedIfHasPermission($account, $perm);

        if ($access->isAllowed() === TRUE) {
          // User has permission.
          if ($ownerId !== $userId) {
            // User is not the owner.
            if ($sharingEnabled === FALSE) {
              // Module has sharing disabled. Access denied.
              $access = AccessResult::forbidden();
            }
            elseif ($account->isAnonymous() === TRUE &&
                $sharingWithAnonymousEnabled === FALSE) {
              // Anonymous access to shared content disabled. Access denied.
              $access = AccessResult::forbidden();
            }
            else {
              $rootFolder = $item->getRootFolder();
              if ($rootFolder->isAccessGranted($userId, 'view') === FALSE) {
                // Folder doesn't allow access. Access denied.
                $access = AccessResult::forbidden();
              }
            }
          }
        }
        return $access->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($item);

      case 'update':
        // Change content.
        //
        // Allowed if:
        // - User has author permission.
        // - And one of:
        //   - User owns the content.
        //   - User granted access AND module's sharing enabled.
        $perm = Constants::AUTHOR_PERMISSION;
        $access = AccessResult::allowedIfHasPermission($account, $perm);

        if ($access->isAllowed() === TRUE) {
          // User has permission.
          if ($ownerId !== $userId) {
            // User is not the owner.
            if ($sharingEnabled === FALSE) {
              // Module has sharing disabled. Access denied.
              $access = AccessResult::forbidden();
            }
            elseif ($account->isAnonymous() === TRUE &&
                $sharingWithAnonymousEnabled === FALSE) {
              // Anonymous access to shared content disabled. Access denied.
              $access = AccessResult::forbidden();
            }
            else {
              $rootFolder = $item->getRootFolder();
              if ($rootFolder->isAccessGranted($userId, 'author') === FALSE) {
                // Folder doesn't allow access. Access denied.
                $access = AccessResult::forbidden();
              }
            }
          }
        }
        return $access->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($item);

      case 'delete':
        // Delete content.
        //
        // Allowed if:
        // - User has author permission.
        // - And one of:
        //   - User owns the content.
        //   - User granted access AND module's sharing enabled.
        if ($item->isNew() === TRUE) {
          // Content isn't fully created yet. Access denied.
          return AccessResult::forbidden()->addCacheableDependency($item);
        }

        $perm = Constants::AUTHOR_PERMISSION;
        $access = AccessResult::allowedIfHasPermission($account, $perm);

        if ($access->isAllowed() === TRUE) {
          // User has permission.
          if ($ownerId !== $userId) {
            // User is not the owner.
            if ($sharingEnabled === FALSE) {
              // Module has sharing disabled. Access denied.
              $access = AccessResult::forbidden();
            }
            elseif ($account->isAnonymous() === TRUE &&
                $sharingWithAnonymousEnabled === FALSE) {
              // Anonymous access to shared content disabled. Access denied.
              $access = AccessResult::forbidden();
            }
            else {
              $rootFolder = $item->getRootFolder();
              if ($rootFolder->isAccessGranted($userId, 'author') === FALSE) {
                // Folder doesn't allow access. Access denied.
                $access = AccessResult::forbidden();
              }
            }
          }
        }
        return $access->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($item);

      case 'share':
        // Share content.
        //
        // Allowed if:
        // - User has author permission.
        // - User owns the root folder.
        // - Module's sharing enabled.
        $perm = Constants::SHARE_PERMISSION;
        $access = AccessResult::allowedIfHasPermission($account, $perm);

        if ($access->isAllowed() === TRUE) {
          // User has permission.
          $rootFolder = $item->getRootFolder();
          $rootOwnerId = (int) $rootFolder->getOwnerId();

          if ($rootOwnerId !== $userId) {
            // User is not the owner. Access denied.
            $access = AccessResult::forbidden();
          }
          elseif ($sharingEnabled === FALSE) {
            // Module has sharing disabled. Access denied.
            $access = AccessResult::forbidden();
          }
        }
        return $access->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($item);

      case 'chown':
        // Only site or module content administrators may change ownership
        // on content. The access() method, which calls this method, has
        // already checked for admins and granted access to them. So if
        // we are here, the user is not an admin and access denied.
        return AccessResult::forbidden();

      default:
        // Unknown operation. Access denied.
        return AccessResult::forbidden();
    }
  }

  /*---------------------------------------------------------------------
   *
   * Access utilities.
   *
   * These are utility functions that check if a user "may" have access,
   * based solely upon permissions and module settings.
   *
   *---------------------------------------------------------------------*/

  /**
   * Checks if a user has permission for an operation.
   *
   * The following operations are supported:
   *
   * - 'chown'
   * - 'delete'
   * - 'share'
   * - 'update'
   * - 'view'
   *
   * Unlike access(), this function only checks permissions, and not
   * entity-based access controls. It therefore returns the *potential*
   * for access, but not an absolute yes/no on access because that
   * would require checking entity access controls too for a specific
   * file or folder.
   *
   * @param string $operation
   *   The name of the operation being considered.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access.  Defaults to
   *   the current user.
   *
   * @return bool
   *   The result of the access control check.
   */
  public static function mayAccess(
    string $operation,
    AccountInterface $account = NULL) {

    //
    // Validate
    // --------
    // Get the account to check.
    if ($account === NULL) {
      $account = \Drupal::currentUser();
    }

    // Get the entity type.
    $entityType = \Drupal::entityTypeManager()->getDefinition(
      FolderShare::ENTITY_TYPE_ID);

    //
    // Allow site & content administrators everything
    // ----------------------------------------------
    // Drupal insures that users marked as site administrators always have
    // all permissions. The check below, then, will always return TRUE for
    // site admins.
    //
    // Content administrators have this module's ADMINISTER_PERMISSION,
    // which grants them access to everything.
    $perm = $entityType->getAdminPermission();
    if (empty($perm) === TRUE) {
      $perm = Constants::ADMINISTER_PERMISSION;
    }

    $access = AccessResult::allowedIfHasPermission($account, $perm);
    if ($access->isAllowed() === TRUE) {
      // The user is either a site admin or they have the module's
      // content admin permission. Access granted.
      if ($operation === 'share') {
        // Sharing may be disabled site-wide. This affects admins too.
        return Settings::getSharingAllowed();
      }

      return TRUE;
    }

    //
    // Check permissions
    // -----------------
    // For each known operation, check permissions only.
    switch ($operation) {
      case 'view':
        // View content.
        $perm = Constants::VIEW_PERMISSION;
        break;

      case 'update':
      case 'delete':
        // Change or delete content.
        $perm = Constants::AUTHOR_PERMISSION;
        break;

      case 'share':
        // Alter sharing of content.
        $perm = Constants::SHARE_PERMISSION;
        return Settings::getSharingAllowed() === TRUE &&
          AccessResult::allowedIfHasPermission($account, $perm)->isAllowed();

      case 'create':
        // Authors may create files and subfolders, but the share permission
        // is required to create root folders. Unfortunately, we do not
        // know which case the caller intends. Just check author.
        $perm = Constants::AUTHOR_PERMISSION;
        break;

      case 'chown':
        // Only administrators can change ownership, which was already
        // checked above. Access denied.
        return FALSE;

      default:
        // Unrecognized operation. Access denied.
        return FALSE;
    }

    return AccessResult::allowedIfHasPermission($account, $perm)->isAllowed();
  }

  /**
   * Returns a list of operations and their permissions.
   *
   * While the class's access methods may be called repeatedly to
   * check for permission on each operation, this function consolidates
   * those calls and checks permissions all at once for all known operations.
   * The returned associative array has operation names as keys, and
   * TRUE/FALSE values that indicate if the operation is allowed.
   *
   * Operations include:
   * - 'chown'.
   * - 'create'.
   * - 'createroot'.
   * - 'delete'.
   * - 'share'.
   * - 'update'.
   * - 'view'.
   *
   * The special 'createroot' operation is included here to indicate permission
   * to create root folders. In practice, this is really the 'create' operation
   * with no parent folder context.
   *
   * The $entity argument names an entity for which access is checked.
   * If this is NULL, access checks are for operations on the root folder
   * list for which there is no parent entity.
   *
   * @param \Drupal\foldershare\Entity\FolderShare $entity
   *   (optional) The entity for which access grants are checked.
   *   If this is NULL, access is solely based upon role permissions.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access.  Defaults to
   *   the current user.
   *
   * @return boolean[]
   *   The returned associative array has operation names as keys, and
   *   TRUE/FALSE values that indicate if the user has permission for
   *   the associated operation.
   */
  public static function getAccessSummary(
    FolderShare $entity = NULL,
    AccountInterface $account = NULL) {

    //
    // Validate
    // --------
    // Get the account to check.
    if ($account === NULL) {
      $account = \Drupal::currentUser();
    }

    // Get the entity type.
    $entityType = \Drupal::entityTypeManager()->getDefinition(
      FolderShare::ENTITY_TYPE_ID);

    //
    // Allow site & content administrators everything
    // ----------------------------------------------
    // Drupal insures that users marked as site administrators always have
    // all permissions. The check below, then, will always return TRUE for
    // site admins.
    //
    // Content administrators have this module's ADMINISTER_PERMISSION,
    // which grants them access to everything.
    $perm = $entityType->getAdminPermission();
    if (empty($perm) === TRUE) {
      $perm = Constants::ADMINISTER_PERMISSION;
    }

    $access = AccessResult::allowedIfHasPermission($account, $perm);
    if ($access->isAllowed() === TRUE) {
      // The user is either a site admin or they have the module's
      // content admin permission. Access granted.
      $canShare = Settings::getSharingAllowed();

      return [
        'chown'      => TRUE,
        'create'     => TRUE,
        'createroot' => TRUE,
        'delete'     => TRUE,
        'share'      => $canShare,
        'update'     => TRUE,
        'view'       => TRUE,
      ];
    }

    // Users with proper permissions can create root folders.
    $canCreateRoot = Settings::getSharingAllowed() === TRUE &&
      AccessResult::allowedIfHasPermission(
        $account,
        Constants::SHARE_PERMISSION)->isAllowed();

    //
    // For no entity, check view and author permissions
    // ------------------------------------------------
    // When no entity is provided, access checking is for operations on
    // the root folder list. 'create' permission is the same as 'createroot'.
    if ($entity === NULL) {
      $canView = AccessResult::allowedIfHasPermission(
        $account,
        Constants::VIEW_PERMISSION)->isAllowed();

      $canAuthor = AccessResult::allowedIfHasPermission(
        $account,
        Constants::AUTHOR_PERMISSION)->isAllowed();

      $canShare = Settings::getSharingAllowed() === TRUE &&
        AccessResult::allowedIfHasPermission(
          $account,
          Constants::SHARE_PERMISSION)->isAllowed();

      return [
        // Non-administrators cannot change content ownership.
        'chown'      => FALSE,

        // Create requires create root permission.
        'create'     => $canCreateRoot,
        'createroot' => $canCreateRoot,

        // Other operations depend upon the user's permissions.
        'delete'     => $canAuthor,
        'share'      => $canShare,
        'update'     => $canAuthor,
        'view'       => $canView,
      ];
    }

    //
    // For an entity, check view and author grants
    // -------------------------------------------
    // When an entity is provided, access depends upon the user's
    // permissions, module settings, and specific access grants on the
    // entity's root folder.
    //
    // Access checks are complicated enough that it is better to call
    // our access() method repeatedly than to try and shortcut that
    // logic with custom code here. Additionally, the parent class's
    // access() method handles caching and module hooks that need to
    // be invoked.
    $entityTypeManager = \Drupal::service('entity_type.manager');
    $handler = $entityTypeManager->getAccessControlHandler(
      $entity->getEntityTypeId());

    $canCreate = $handler->createAccess(
      NULL,
      $account,
      ['parentId' => $entity->id()],
      FALSE);

    return [
      // Non-administrators cannot change content ownership.
      'chown'      => FALSE,

      // Other operations depend upon the user's permissions.
      'createroot' => $canCreateRoot,
      'create'     => $canCreate,
      'delete'     => $handler->access($entity, 'delete', $account, FALSE),
      'share'      => $handler->access($entity, 'share', $account, FALSE),
      'update'     => $handler->access($entity, 'update', $account, FALSE),
      'view'       => $handler->access($entity, 'view', $account, FALSE),
    ];
  }

}
