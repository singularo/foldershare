<?php

namespace Drupal\foldershare;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\file\FileInterface;

/**
 * Manages a hierarchy of folders, subfolders, and files.
 *
 * Implementations of this class support operations on folders and files
 * within folders. Operations include create, delete, move, copy, rename,
 * and changes to specific fields, such as names, descriptions, dates,
 * and owners.
 *
 * Object instances represent top-level root folders, subfolders, and specific
 * entries in the folders, such as files.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\Folder
 */
interface FolderShareInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /*---------------------------------------------------------------------
   *
   * Kind field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Gets the item's kind.
   *
   * An entity may be a 'folder' or a 'file'. The entity kind is set when
   * the entity is created and may not be changed thereafter.
   *
   * @return string
   *   The name of the item kind. Either 'folder' or 'file'.
   */
  public function getKind();

  /**
   * Returns TRUE if this item is a file, and FALSE otherwise.
   *
   * @return bool
   *   Returns TRUE if this item is a file.
   */
  public function isFile();

  /**
   * Returns TRUE if this item is a folder, and FALSE otherwise.
   *
   * @return bool
   *   Returns TRUE if this item is a folder, but not a root folder.
   */
  public function isFolder();

  /**
   * Returns TRUE if this item is a folder or root folder, and FALSE otherwise.
   *
   * @return bool
   *   Returns TRUE if this item is a folder or root folder.
   */
  public function isFolderOrRootFolder();

  /**
   * Returns TRUE if this item is an image, and FALSE otherwise.
   *
   * @return bool
   *   Returns TRUE if this item is an image.
   */
  public function isImage();

  /**
   * Returns TRUE if this item is a media, and FALSE otherwise.
   *
   * @return bool
   *   Returns TRUE if this item is a media.
   */
  public function isMedia();

  /**
   * Returns TRUE if this folder is a root folder, and FALSE otherwise.
   *
   * Applications may check if this folder is a root folder by any
   * of the following, in fastest to slowest order:
   *
   * @code
   * $isRoot = ($this->getParentFolderId() == -1);
   * $isRoot = $this->isRootFolder();
   * $isRoot = ($this->id() == $this->getRootFolderId());
   * $isRoot = ($this == $this->getRootFolder());
   * @endcode
   *
   * @return bool
   *   Returns TRUE if this item is a root folder.
   *
   * @see ::getParentFolder()
   * @see ::getParentFolderId()
   * @see ::getRootFolder()
   * @see ::getRootFolderId()
   * @see ::isParentFolder()
   */
  public function isRootFolder();

  /*---------------------------------------------------------------------
   *
   * MIME type field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the item's MIME type.
   *
   * Folder MIME types use the custom 'foldershare/directory' type.
   * File MIME types are cached from the underlying File object.
   *
   * @return string
   *   Returns the MIME type.
   *
   * @see \Drupal\file\FileInterface::getMimeType()
   */
  public function getMimeType();

  /**
   * Sets the item's MIME type.
   *
   * The given MIME type is not validated. It is up to the caller to use
   * valid MIME types.  Folder MIME types should use the custom
   * 'foldershare/directory' type.  File MIME types should match that of
   * the underlying File object and be a standard MIME type.
   *
   * If the MIME type for a FolderShare file here, then the caller should
   * also change the underlying File entity's MIME type.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param string $mime
   *   The MIME type.
   *
   * @see \Drupal\file\FileInterface::setMimeType()
   */
  public function setMimeType(string $mime);

  /*---------------------------------------------------------------------
   *
   * File, Image, and Media fields.
   *
   *---------------------------------------------------------------------*/

  /**
   * Gets the item's file ID, if any.
   *
   * When an item is a file, this method returns the File entity ID for
   * the underlying file. For other kinds of items, this method returns -1.
   *
   * @return int
   *   Returns the entity ID of the underlying File entity for file items,
   *   or -1 if this item is not for a file.
   *
   * @see ::getFile()
   * @see ::getKind()
   * @see ::isFile()
   */
  public function getFileId();

  /**
   * Gets the item's File entity, if any.
   *
   * When an item is a file, this method returns the loaded File entity
   * for the underlying file. For other kinds of items, this method
   * returns a NULL.
   *
   * @return \Drupal\file\FileInterface
   *   Returns the loaded File entity for the underlying file for file items,
   *   or a NULL if this item is not for a file.
   *
   * @see ::getFileId()
   * @see ::getKind()
   * @see ::isFile()
   */
  public function getFile();

  /**
   * Gets the item's image ID, if any.
   *
   * When an item is an image, this method returns the File entity ID for
   * the underlying image file. For other kinds of items, this method
   * returns -1.
   *
   * @return int
   *   Returns the entity ID of the underlying File entity for image file items,
   *   or -1 if this item is not for an image file.
   *
   * @see ::getImage()
   * @see ::getKind()
   * @see ::isImage()
   */
  public function getImageId();

  /**
   * Gets the item's image File entity, if any.
   *
   * When an item is an image, this method returns the loaded File entity
   * for the underlying image file. For other kinds of items, this method
   * returns a NULL.
   *
   * @return \Drupal\file\FileInterface
   *   Returns the loaded File entity for the underlying image file for
   *   image file items, or a NULL if this item is not for a file.
   *
   * @see ::getImageId()
   * @see ::getKind()
   * @see ::isImage()
   */
  public function getImage();

  /**
   * Gets the item's media ID, if any.
   *
   * When an item is a media item, this method returns the Media entity ID for
   * the underlying media. For other kinds of items, this method returns -1.
   *
   * @return int
   *   Returns the entity ID of the underlying Media entity for media items,
   *   or -1 if this item is not for a media.
   *
   * @see ::getMedia()
   * @see ::getKind()
   * @see ::isMedia()
   */
  public function getMediaId();

  /**
   * Gets the item's Media entity, if any.
   *
   * When an item is a media item, this method returns the loaded Media entity
   * for the underlying media. For other kinds of items, this method
   * returns a NULL.
   *
   * @return \Drupal\media\MediaInterface
   *   Returns the loaded Media entity for the underlying media for media
   *   items, or a NULL if this item is not for a media item.
   *
   * @see ::getMediaId()
   * @see ::getKind()
   * @see ::isMedia()
   */
  public function getMedia();

  /*---------------------------------------------------------------------
   *
   * Name field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Gets the item's name.
   *
   * The item can be a folder, file, or other item contained within a folder.
   * For files and other items in a folder, this method may redirect to a
   * query of the underlying item (e.g. the name of the file).
   *
   * This is equivalent to Entity::label(), but faster since it does not
   * require entity type introspection to find the field containing
   * the name.
   *
   * @return string
   *   The name of the item. The name is never empty.
   *
   * @see \Drupal\Core\Entity\label()
   * @see ::setName()
   * @see ::rename()
   */
  public function getName();

  /**
   * Sets the item's name.
   *
   * The item can be a folder, file, or other item contained within a folder.
   * For files and other items in a folder, this method may redirect to a
   * query of the underlying item (e.g. the name of the file).
   *
   * The caller should already have insured that the name is legal
   * and unique within the context of a parent folder, if any.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param string $name
   *   The new name of the item. The name should be legal.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns this item.
   *
   * @see ::getName()
   * @see ::rename()
   */
  public function setName(string $name);

  /*---------------------------------------------------------------------
   *
   * Size field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Clears this folder's storage size to empty.
   *
   * If this item is not a folder, this method has no effect.
   *
   * When the size field is clear, a folder has no recorded size
   * until a background job (or some other code) sweeps through and
   * recurses to compute the sum of the file sizes in each folder.
   *
   * The caller must call save() for the change to take effect.
   *
   * @see ::getKind()
   * @see ::getSize()
   * @see ::setSize()
   * @see ::updateSize()
   * @see ::updateSizes()
   * @see ::enqueueUpdateSizes()
   */
  public function clearSize();

  /**
   * Gets the item's storage size, in bytes, or -1 if unknown.
   *
   * For files, this returns the size of the underlying file.
   *
   * For folders, this returns the size of the folder tree starting
   * at the folder and including all child files and subfolders.
   * When the size field is clear, the folder has no recorded size
   * until a background job (or some other code) sweeps through and
   * recurses to compute the sum of the file sizes in each folder.
   *
   * @return int
   *   The size in bytes, or -1 if the size is unknown.
   *
   * @see ::getKind()
   * @see ::clearSize()
   * @see ::setSize()
   * @see ::updateSize()
   * @see ::updateSizes()
   * @see ::enqueueUpdateSizes()
   * @see \Drupal\file\FileInterface::getSize()
   */
  public function getSize();

  /**
   * Sets the folder's storage size, in bytes, or a -1 to clear it.
   *
   * If this item is not a folder, this method has no effect.
   *
   * Positive values set the folder size, while all other values clear
   * the size and indicate that the size is not known.
   * When the size field is clear, the folder has no recorded size
   * until a background job (or some other code) sweeps through and
   * recurses to compute the sum of the file sizes in each folder.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param int $size
   *   The positive folder size in bytes, or any other value to
   *   clear the value and indicate the size is not known.
   *
   * @see ::clearSize()
   * @see ::getKind()
   * @see ::getSize()
   * @see ::updateSize()
   * @see ::updateSizes()
   * @see ::enqueueUpdateSizes()
   */
  public function setSize(int $size);

  /**
   * Updates the folder's storage size, in bytes, by summing children.
   *
   * If this item is not a folder, this method has no effect and just
   * returns the size.
   *
   * If the folder already has a size, the update skips the folder.
   * This avoids wasting time on folders whose sizes don't need an
   * update. To force a size update, call clearSize() first.
   *
   * If the folder has no child files or folders, the size is set to zero.
   *
   * After the size is updated, the folder is saved.
   *
   * @return int
   *   The updated size of the folder.
   *
   * @see ::clearSize()
   * @see ::getKind()
   * @see ::getSize()
   * @see ::setSize()
   * @see ::updateSizes()
   * @see ::enqueueUpdateSizes()
   * @see \Drupal\file\FileInterface::getSize()
   */
  public function updateSize();

  /*---------------------------------------------------------------------
   *
   * Created date/time field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Gets the creation timestamp.
   *
   * @return int
   *   The creation time stamp for this object.
   */
  public function getCreatedTime();

  /**
   * Sets the creation timestamp.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param int $timestamp
   *   The creation time stamp for this object.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns this folder.
   */
  public function setCreatedTime($timestamp);

  /*---------------------------------------------------------------------
   *
   * Owner field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Changes the ownership of this folder.
   *
   * The owner user ID of this item is changed to be the indicated
   * new user. The item is then saved.
   *
   * If this item is a folder, the ownership of the folder's children
   * is not affected.
   *
   * If this item is a file, the underlying file's ownership is also changed.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the prior and current owner.
   *
   * @param int $newUid
   *   The user ID of the new owner of the folder.
   *
   * @see ::changeAllOwnerId()
   * @see ::changeAllOwnerIdByUser()
   * @see \Drupal\user\EntityOwnerInterface::getOwner()
   * @see \Drupal\user\EntityOwnerInterface::getOwnerId()
   * @see ::isAllOwned()
   * @see \Drupal\user\EntityOwnerInterface::setOwner()
   * @see \Drupal\user\EntityOwnerInterface::setOwnerId()
   */
  public function changeOwnerId(int $newUid);

  /**
   * Changes the owner of this item, and all child folders and files.
   *
   * The owner user ID of this item, and all subfolders and files,
   * is changed to be the indicated new user. All of the folders and
   * files are saved.
   *
   * If one or more child files or folders cannot be modified, the
   * rest are modified and an exception is thrown.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the prior and current owners.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * Modifying the folder, and its children, requires an exclusive
   * edit lock during the operation. As children are changed, they too
   * are locked one at a time while they are modified.  If any lock
   * cannot be acquired, the rest of the operation tries to complete,
   * after which an exception is thrown.
   *
   * @param int $uid
   *   The owner user ID for the new owner of the folder tree.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Thrown if an access lock could not be acquired on this folder,
   *   or any of the child folders.
   *
   * @see ::changeOwnerId()
   * @see ::changeAllOwnerIdByUser()
   * @see \Drupal\user\EntityOwnerInterface::getOwner()
   * @see \Drupal\user\EntityOwnerInterface::getOwnerId()
   * @see ::isAllOwned()
   * @see \Drupal\user\EntityOwnerInterface::setOwner()
   * @see \Drupal\user\EntityOwnerInterface::setOwnerId()
   */
  public function changeAllOwnerId(int $uid);

  /**
   * Returns TRUE if the user owns every folder and file in the folder tree.
   *
   * If everything is owned by this user, TRUE is returned.  Otherwise
   * FALSE is returned.
   *
   * @param int $uid
   *   The user ID to check for ownership.
   *
   * @return bool
   *   Returns TRUE if the entire tree of files and folders is owned
   *   by the given user, and FALSE otherwise.
   *
   * @see \Drupal\user\EntityOwnerInterface::getOwnerId()
   */
  public function isAllOwned(int $uid);

  /*---------------------------------------------------------------------
   *
   * Grant fields.
   *
   *---------------------------------------------------------------------*/

  /**
   * Grants a user access to this root folder.
   *
   * If this item is not a root folder, no action is taken.
   *
   * Granting a user access adds the user's ID to the root folder's list
   * of users that have the specified access. Recognized access grants are:
   *
   * - 'view': can view folder fields and content.
   * - 'author': can create, update, and delete fields and content.
   * - 'disabled': can't view or author, but listed for future use.
   *
   * The owner of the root folder always has view and author access granted.
   * Adding the owner, or any user already granted access, has no affect.
   *
   * Adding a user to the 'disabled' grant list automatically removes
   * them from the 'view' and 'author' lists, if they are on them.
   *
   * Adding a user to the 'view' or 'author' grant lists automatically
   * removes them from the 'disabled' list, if they are on it.
   *
   * Access grants may only be added on root folders. Access grants on
   * non-root folders are silently ignored.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param int $uid
   *   The user ID of a user granted access.
   * @param string $access
   *   The access granted. One of 'author', 'view', or 'disabled'.
   *
   * @see ::clearAccessGrants()
   * @see ::deleteAccessGrant()
   * @see ::setAccessGrants()
   */
  public function addAccessGrant(int $uid, string $access);

  /**
   * Clears access grants for this root folder, or for a specific user.
   *
   * If this item is not a root folder, no action is taken.
   *
   * If no user ID is given, or if the ID is < 0, all access grants for
   * the root folder are cleared. If a user ID is given, only access grants
   * for the user are cleared from the root folder, leaving any other grants.
   *
   * The owner of the root folder always has view and author access granted.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param int $uid
   *   (optional) The user ID of a user granted access.
   *
   * @see ::addAccessGrant()
   * @see ::deleteAccessGrant()
   * @see ::setAccessGrants()
   */
  public function clearAccessGrants(int $uid = -1);

  /**
   * Deletes a user's access to this root folder.
   *
   * If this item is not a root folder, no action is taken.
   *
   * The owner of the root folder always has view and author access granted.
   * Attempting to delete the owner's access has no effect.
   *
   * Deleting a user that currently does not have access has no effect.
   *
   * Deleting a user that currently has view or author access does NOT
   * automatically move them to the disabled list. The caller that intends
   * that the user stay listed for the folder must then call
   * addAccessGrant() to put the user onto the disabled list.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param int $uid
   *   The user ID of a user granted access.
   * @param string $access
   *   The access grant to be removed. One of 'author', 'view', or 'disabled'.
   *
   * @see ::clearAccessGrants()
   * @see ::addAccessGrant()
   * @see ::setAccessGrants()
   */
  public function deleteAccessGrant(int $uid, string $access);

  /**
   * Returns user IDs for users granted author access to this root folder.
   *
   * The returned array has one entry for each user ID currently granted
   * author (create, update, delete) access to this root folder.
   *
   * The owner of the root folder is always included in this list.
   *
   * Only root folders have access grant user IDs. An empty array is
   * returned for non-root folders.
   *
   * @return int[]
   *   Returns an array of user IDs for users with author access to
   *   this root folder.
   *
   * @see ::getAccessGrantDisabledUserIds()
   * @see ::getAccessGrantViewUserIds()
   * @see ::getAccessGrants()
   * @see ::isAccessGranted()
   */
  public function getAccessGrantAuthorUserIds();

  /**
   * Returns user IDs for users granted view access to this root folder.
   *
   * The returned array has one entry for each user ID currently granted
   * view access to this root folder.
   *
   * The owner of the root folder is always included in this list.
   *
   * Only root folders have access grant user IDs. An empty array is
   * returned for non-root folders.
   *
   * @return int[]
   *   Returns an array of user IDs for users with view access to
   *   this root folder.
   *
   * @see ::getAccessGrantAuthorUserIds()
   * @see ::getAccessGrantDisabledUserIds()
   * @see ::getAccessGrants()
   * @see ::isAccessGranted()
   */
  public function getAccessGrantViewUserIds();

  /**
   * Returns user IDs for users with disabled access to this root folder.
   *
   * The returned array has one entry for each user ID currently listed on
   * the root folder's disabled access list. Such users have neither view or
   * author access, but they remain associated with the root folder and may be
   * listed by a user interface. Disabled users might have been temporarily
   * blocked from accessing the root folder, but the owner of the root
   * folder may restore their access later.
   *
   * The owner of the root folder is always included in this list.
   *
   * Only root folders have access grant user IDs. An empty array is
   * returned for non-root folders.
   *
   * @return int[]
   *   Returns an array of user IDs for users with view access to
   *   this root folder.
   *
   * @see ::getAccessGrantAuthorUserIds()
   * @see ::getAccessGrantViewUserIds()
   * @see ::getAccessGrants()
   * @see ::isAccessGranted()
   */
  public function getAccessGrantDisabledUserIds();

  /**
   * Returns all user IDs and access grants for this root folder.
   *
   * The returned array has user IDs as keys. For each user, the array
   * value is an array that contains either one or two string values:
   *
   * - ['disabled'] = user is disabled.
   * - ['view'] = user only has view access.
   * - ['author'] = user only has author access (which is odd).
   * - ['author', 'view'] = user has view and author access.
   *
   * The owner of the root folder is always included with both view and
   * author access.
   *
   * Any user ID listed as 'disabled' will not have 'view' or 'author'
   * access, and any user with 'view' or 'author' access, will not be
   * listed as 'disabled'.
   *
   * The returned array is in the same format as that used by
   * setAccessGrants().
   *
   * Only root folders have access grant user IDs. An empty array is
   * returned for non-root folders.
   *
   * @return array
   *   Returns an array with user IDs as keys and arrays as values. The
   *   array values contain strings indicating 'disabled', 'view', or
   *   'author' access.
   *
   * @see ::getAccessGrantAuthorUserIds()
   * @see ::getAccessGrantDisabledUserIds()
   * @see ::getAccessGrantViewUserIds()
   * @see ::isAccessGranted()
   * @see ::setAccessGrants()
   */
  public function getAccessGrants();

  /**
   * Returns TRUE if access is granted to the user on this root folder.
   *
   * The user ID is looked up in the root folder's access grants to see if
   * the user has access. Valid access strings are 'view', 'author', and
   * 'disabled'.  A user with 'view' access can view root folder fields and
   * contents.  A user with 'author' access can create, update, and delete
   * fields and contents.  A user with 'disabled' access has no view or
   * author access, but they remain associated with the root folder and
   * may have access in the future, if so granted.
   *
   * Only root folders have access grant user IDs.
   *
   * @param int $uid
   *   The user ID of a user granted access.
   * @param string $access
   *   The access to test. One of 'author', 'view', or 'disabled'.
   *
   * @return bool
   *   Returns TRUE if the access is granted, and FALSE otherwise.
   *
   * @see ::getAccessGrantAuthorUserIds()
   * @see ::getAccessGrantDisabledUserIds()
   * @see ::getAccessGrantViewUserIds()
   * @see ::getAccessGrants()
   * @see ::setAccessGrants()
   */
  public function isAccessGranted(int $uid, string $access);

  /**
   * Returns TRUE if no-one but the owner has access to this root folder.
   *
   * If the root folder's owner is the only user ID listed in the root
   * folder's 'view' and 'author' grants, then the folder tree is private
   * and this method returns TRUE. Otherwise it returns FALSE.
   *
   * @return bool
   *   Returns TRUE if the owner is the only user granted access, and
   *   FALSE otherwise.
   *
   * @see ::isAccessGranted()
   * @see ::isAccessPublic()
   * @see ::isAccessShared()
   */
  public function isAccessPrivate();

  /**
   * Returns TRUE if access is granted to anonymous on this root folder.
   *
   * If the root folder 'view' or 'author' grants include the anonymous
   * user (UID = 0), then the folder tree is public and this method
   * returns TRUE. Otherwise it returns FALSE.
   *
   * @return bool
   *   Returns TRUE if access is granted to the anonymous user, and
   *   FALSE otherwise.
   *
   * @see ::isAccessGranted()
   * @see ::isAccessPrivate()
   * @see ::isAccessShared()
   */
  public function isAccessPublic();

  /**
   * Returns TRUE if access is granted to a non-owner of this root folder.
   *
   * If the root folder grants 'view' or 'author' access to anyone other
   * than the folder's owner, this method returns TRUE. Otherwise it
   * returns FALSE.
   *
   * @return bool
   *   Returns TRUE if access is granted to anyone beyond the owner, and
   *   FALSE otherwise.
   *
   * @see ::isAccessGranted()
   * @see ::isAccessPrivate()
   * @see ::isAccessPublic()
   */
  public function isAccessShared();

  /**
   * Sets all user IDs and access grants for this root folder.
   *
   * The given array must have user IDs as keys. For each user, the array
   * value is an array that contains either one or two string values:
   *
   * - ['disabled'] = user is disabled.
   * - ['view'] = user only has view access.
   * - ['author'] = user only has author access (which is odd).
   * - ['author', 'view'] = user has view and author access.
   *
   * The given array is used to completely reset all access grants for
   * this root folder. Any prior grants are removed.
   *
   * The owner of the root folder is always included with both view and
   * author access, whether or not they are listed in the given array.
   *
   * Any user ID listed as 'disabled' will not have 'view' or 'author'
   * access, and any user with 'view' or 'author' access, will not be
   * listed as 'disabled'.
   *
   * The given array is in the same format as that returned by
   * getAccessGrants().
   *
   * Only root folders may have access grant user IDs. Setting grants on
   * non-root folders has no effect.
   *
   * The caller must call save() for the change to take effect.
   *
   * @return array
   *   Returns an array with user IDs as keys and arrays as values. The
   *   array values contain strings indicating 'disabled', 'view', or
   *   'author' access.
   *
   * @see ::getAccessGrantAuthorUserIds()
   * @see ::getAccessGrantDisabledUserIds()
   * @see ::getAccessGrantViewUserIds()
   * @see ::isAccessGranted()
   * @see ::setAccessGrants()
   */
  public function setAccessGrants(array $grants);

  /*---------------------------------------------------------------------
   *
   * Description field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Gets the item description.
   *
   * @return string
   *   The description for the item, or an empty string if
   *   there is no description.
   *
   * @see ::setDescription()
   */
  public function getDescription();

  /**
   * Sets the item description.
   *
   * The caller must call save() for the change to take effect.
   *
   * @param string $text
   *   The description for the item, or an empty string if
   *   there is no description.
   *
   * @see ::getDescription()
   */
  public function setDescription(string $text);

  /*---------------------------------------------------------------------
   *
   * Child items.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns a list of all IDs for entries in this folder.
   *
   * @return int[]
   *   An array of integer entity IDs for the FolderShare entity children
   *   of this folder, or an empty array if the folder has no children.
   *
   * @see ::getChildFileIds()
   * @see ::getChildFolderIds()
   * @see ::getChildNames()
   */
  public function getChildIds();

  /**
   * Returns a list of loaded entities for children in this folder.
   *
   * The returned children may be subfolders, files, etc.
   *
   * @return array
   *   A list of entities for children of this folder.
   *
   * @see ::getChildFiles()
   * @see ::getChildFolders()
   */
  public function getChildren();

  /**
   * Returns a list of file, image, and media IDs for children of this folder.
   *
   * The returned IDs are for FolderShare entities for files, images, or
   * media.
   *
   * @return int[]
   *   An array of integer entity IDs for the FolderShare entity children
   *   of this folder, or an empty array if the folder has no files.
   *
   * @see ::getChildIds()
   * @see ::getChildFiles()
   * @see ::getChildNames()
   * @see ::getFileId()
   */
  public function getChildFileIds();

  /**
   * Returns a list of entities for files, images, or media in this folder.
   *
   * The returned objects are FolderShare entities for files, images, or
   * media.
   *
   * @return array
   *   A list of entities for the file children of this folder.
   *
   * @see ::getChildren()
   * @see ::getChildFileIds()
   * @see ::getChildNames()
   * @see ::getFile()
   * @see ::getImage()
   * @see ::getMedia()
   */
  public function getChildFiles();

  /**
   * Returns a list of folder IDs for folders in this folder.
   *
   * @return array
   *   A list of integer folder IDs for the Folder entity
   *   children of this folder.
   *
   * @see ::getFolderChildFolderIds()
   * @see ::getChildIds()
   * @see ::getChildFolders()
   * @see ::getChildNames()
   */
  public function getChildFolderIds();

  /**
   * Returns a list of loaded Folder objects for folders in this folder.
   *
   * @return array
   *   Returns an associative array where keys are entity IDs and values
   *   are entities for the folder children of this folder.
   *
   * @see ::getChildFolderIds()
   * @see ::getChildNames()
   * @see ::getChildren()
   */
  public function getChildFolders();

  /**
   * Returns a list of file and folder names for children of this folder.
   *
   * @return array
   *   An array of file and folder names where keys are names
   *   and values are entity IDs.
   *
   * @see ::getChildFileIds()
   * @see ::getChildFiles()
   * @see ::getChildFolderIds()
   * @see ::getChildFolders()
   */
  public function getChildNames();

  /*---------------------------------------------------------------------
   *
   * Parent field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the parent folder, or NULL if there is no parent.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   The parent folder entity object, or NULL if this folder is a
   *   root folder and it has no parent.
   *
   * @see ::getParentFolderId()
   * @see ::getRootFolder()
   * @see ::getRootFolderId()
   * @see ::isRootFolder()
   */
  public function getParentFolder();

  /**
   * Returns the parent folder ID, or -1 if there is no parent.
   *
   * @return int
   *   The ID of the parent folder, or -1 if this folder is a
   *   root folder and it has no parent.
   *
   * @see ::getParentFolder()
   * @see ::getRootFolder()
   * @see ::getRootFolderId()
   * @see ::isRootFolder()
   */
  public function getParentFolderId();

  /*---------------------------------------------------------------------
   *
   * Ancestors and descendants.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns a list of ancestor folder IDs.
   *
   * The list is ordered starting with this folder's parent, then its
   * parent, etc. The last entry in the list is the root folder for
   * this folder tree.
   *
   * @return int[]
   *   An ordered list of folder entity IDs as array values, ordered
   *   with this folder's parent first, then its parent, etc.
   *
   * @see ::getAncestorFolders()
   * @see ::getAncestorFolderNames()
   * @see ::getDescendantFolderIds()
   * @see ::isAncestorOfFolderId()
   * @see ::isDescendantOfFolderId()
   */
  public function getAncestorFolderIds();

  /**
   * Returns a list of ancestor folder names.
   *
   * The list is ordered starting with this folder's parent, then its
   * parent, etc. The last entry in the list is the root folder for
   * this folder tree.
   *
   * @return string[]
   *   An ordered list of folder entity names as array values, ordered
   *   with this folder's parent first, then its parent, etc.
   *
   * @see ::getAncestorFolders()
   * @see ::getAncestorFolderIds()
   */
  public function getAncestorFolderNames();

  /**
   * Returns a list of ancestor folder.
   *
   * The list is ordered starting with this folder's parent, then its
   * parent, etc. The last entry in the list is the root folder for
   * this folder tree.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   An ordered list of folder entities array values, ordered
   *   with this folder's parent first, then its parent, etc.
   *
   * @see ::getAncestorFolderIds()
   * @see ::getAncestorFolderNames()
   * @see ::getDescendantFolderIds()
   * @see ::isAncestorOfFolderId()
   * @see ::isDescendantOfFolderId()
   */
  public function getAncestorFolders();

  /**
   * Returns TRUE if this folder is an ancestor of the given folder.
   *
   * If the given folder's ID is invalid, this method returns FALSE.
   *
   * @param int $folderId
   *   The folder entity ID of the folder to check to see if this
   *   folder is one of its ancestors.
   *
   * @return bool
   *   TRUE if this folder is an ancestor of the given folder,
   *   and FALSE otherwise.
   *
   * @see ::getAncestorFolderIds()
   * @see ::getDescendantFolderIds()
   * @see ::isDescendantOfFolderId()
   */
  public function isAncestorOfFolderId(int $folderId);

  /**
   * Returns a list of descendant folder IDs.
   *
   * List entries are ordered so that the children of a folder are
   * always later in the list than the parent.
   *
   * @return int[]
   *   An ordered list of folder entity IDs as array values.
   *
   * @see ::getAncestorFolderIds()
   * @see ::isAncestorOfFolderId()
   * @see ::isDescendantOfFolderId()
   */
  public function getDescendantFolderIds();

  /**
   * Returns TRUE if this item is a descendant of the given folder.
   *
   * If the given folder's ID is invalid, this method returns FALSE.
   *
   * @param int $folderId
   *   The folder entity ID of the folder to check to see if this
   *   folder is one of its descendants.
   *
   * @return bool
   *   TRUE if this folder is a descendant of the given folder,
   *   and FALSE otherwise.
   *
   * @see ::getAncestorFolderIds()
   * @see ::getDescendantFolderIds()
   * @see ::isAncestorOfFolderId()
   */
  public function isDescendantOfFolderId(int $folderId);

  /*---------------------------------------------------------------------
   *
   * Root folder field.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns this item's root folder.
   *
   * If this folder is a root folder, it returns itself.
   *
   * Applications may check if this folder is a root folder by any
   * of the following, in fastest to slowest order:
   *
   * @code
   * $isRoot = ($this->getParentFolderId() == -1);
   * $isRoot = $this->isRootFolder();
   * $isRoot = ($this->id() == $this->getRootFolderId());
   * $isRoot = ($this == $this->getRootFolder());
   * @endcode
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   The root folder entity object, or this folder if this folder
   *   is a root folder.
   *
   * @see ::getParentFolder()
   * @see ::getParentFolderId()
   * @see ::getRootFolderId()
   * @see ::isRootFolder()
   */
  public function getRootFolder();

  /**
   * Returns this item's root folder ID.
   *
   * IF this folder is a root folder, it returns its own ID.
   *
   * Applications may check if this folder is a root folder by any
   * of the following, in fastest to slowest order:
   *
   * @code
   * $isRoot = ($this->getParentFolderId() == -1);
   * $isRoot = $this->isRootFolder();
   * $isRoot = ($this->id() == $this->getRootFolderId());
   * $isRoot = ($this == $this->getRootFolder());
   * @endcode
   *
   * @return int
   *   The root folder entity ID, or this folder's ID if this folder
   *   is a root folder.
   *
   * @see ::getParentFolder()
   * @see ::getParentFolderId()
   * @see ::getRootFolder()
   * @see ::isRootFolder()
   */
  public function getRootFolderId();

  /*---------------------------------------------------------------------
   *
   * Paths.
   *
   *---------------------------------------------------------------------*/
  /**
   * Returns a string path to the entity.
   *
   * The names of the entity's ancestors are queried, then assembled
   * into a folder path string. The string starts with a '/', followed
   * by the entity's root folder name. This is followed by further
   * ancestor folder names, separated by slashes, and ends with the
   * entity's own name.
   *
   * @return string
   *   Returns a string path to the entity, composed of all ancestor names,
   *   starting and separated by '/', and ending with the entity's name.
   */
  public function getPath();

  /*---------------------------------------------------------------------
   *
   * Folder operations.
   *
   *---------------------------------------------------------------------*/

  /**
   * Creates a new folder with the given name as a child of this folder.
   *
   * If the name is empty, it is set to a default.
   *
   * The name is checked for uniqueness within the parent folder.
   * If needed, a sequence number is appended before the extension(s) to
   * make it unique (e.g. 'My folder 12').
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_field_values_init" hook to initialize fields.
   * - "hook_foldershare_create" after the entity has been created, but
   *   before it has been saved.
   * - "hook_foldershare_presave" before the entity is saved.
   * - "hook_foldershare_insert" after the entity has been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * The parent folder is locked for exclusive editing access by this
   * function for the duration of the operation.
   *
   * @param string $name
   *   (optional, default = '') The name for the new folder. If the name is
   *   empty, it is set to a default name.
   * @param bool $allowRename
   *   (optional, default = TRUE) When TRUE, the entity will be automatically
   *   renamed, if needed, to insure that it is unique within the folder.
   *   When FALSE, non-unique names cause an exception to be thrown.
   *
   * @return \Drupal\foldershare\Entity\FolderShareInterface
   *   Returns the new folder. The folder has been saved.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the name is already in use.
   */
  public function createFolder(string $name = '', bool $allowRename = TRUE);

  /**
   * Copies this folder tree into a new root folder.
   *
   * If this item is not a folder, an exception is thrown.
   *
   * If one or more child files or folders cannot be copied, the
   * rest are copied and an exception is thrown.
   *
   * The copied files and folders are owned by the current user.
   *
   * <B>Hooks:</B>
   * This method creates one or more new file and folder entities. Multiple
   * hooks are invoked as a side effect. See ::createFolder() and ::addFile().
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * The root list, this folder, and all subfolders are locked
   * for exclusive editing access by this function for the duration
   * of the copy.
   *
   * @param bool $adjustName
   *   (optional, default = TRUE) When TRUE and there is a name collision,
   *   the copied item's name will be automatically adjusted to avoid that
   *   collision by appending a unique number to the end. When FALSE and
   *   there is a name collision, an exception is thrown.
   * @param string $newName
   *   (optional, default = '' = no name change) When empty, the copy will
   *   have the same name as the original When a new name is not empty,
   *   the given name will be used as the name for the copy. The new name
   *   is still subject to automatic name adjustment if $adjustName is TRUE.
   *
   * @return \Drupal\foldershare\Entity\FolderShareInterface
   *   Returns the new root folder.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If this item is not a folder, if the new name is not legal, or if
   *   automatic name adjust is not allowed and there is a name collision.
   *
   * @see ::addFile()
   * @see ::copyToFolder()
   * @see ::createFolder()
   */
  public function copyToRoot(bool $adjustName = TRUE, string $newName = '');

  /**
   * Copies this item or folder tree into a destination folder.
   *
   * If one or more child files or folders cannot be copied, the
   * rest are copied and an exception is thrown.
   *
   * The copied files and folders are owned by the current user.
   *
   * <B>Hooks:</B>
   * This method creates one or more new file and folder entities. Multiple
   * hooks are invoked as a side effect. See ::createFolder() and ::addFile().
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder, the destination folder, and all subfolders are locked
   * for exclusive editing access by this function for the duration
   * of the copy.
   *
   * @param \Drupal\foldershare\FolderShareInterface $parent
   *   (optional, default = NULL = copy to the root list) The parent for the
   *   new copy of this folder. When NULL, the copy is added to the root list.
   * @param bool $adjustName
   *   (optional, default = TRUE) When TRUE and there is a name collision,
   *   the copied item's name will be automatically adjusted to avoid that
   *   collision by appending a unique number to the end. When FALSE and
   *   there is a name collision, an exception is thrown.
   * @param string $newName
   *   (optional, default = '' = no name change) When empty, the copy will
   *   have the same name as the original When a new name is not empty,
   *   the given name will be used as the name for the copy. The new name
   *   is still subject to automatic name adjustment if $adjustName is TRUE.
   *
   * @return \Drupal\foldershare\Entity\FolderShareInterface
   *   Returns the new folder.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the folder could not be copied because the intended parent
   *   is this folder or a child of this folder.
   *
   * @see ::addFile()
   * @see ::copyToRoot()
   * @see ::createFolder()
   */
  public function copyToFolder(
    FolderShareInterface $parent = NULL,
    bool $adjustName = TRUE,
    string $newName = '');

  /**
   * Duplicates this item or folder tree into this folder's parent.
   *
   * If this folder is a root folder, then the new copy of this folder
   * is also a root folder.
   *
   * If one or more child files or folders cannot be copied, the
   * rest are copied and an exception is thrown.
   *
   * The copied files and folders are owned by the current user.
   *
   * <B>Hooks:</B>
   * This method creates one or more new file and folder entities. Multiple
   * hooks are invoked as a side effect. See ::createFolder() and ::addFile().
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder, the parent folder, and all subfolders are locked
   * for exclusive editing access by this function for the duration
   * of the copy.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the folder could not be copied because the intended parent
   *   is this folder or a child of this folder.
   *
   * @see ::addFile()
   * @see ::copyToRoot()
   * @see ::copyToFolder()
   * @see ::createFolder()
   */
  public function duplicate();

  /**
   * Moves this folder tree to the root folder list.
   *
   * If this item is not a folder, an exception is thrown.
   *
   * The moved files and folders retain their original ownership.
   *
   * An exception is thrown if the root list contains a file or
   * folder with the same name as this folder, or the proposed new name.
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_presave" before the entity is saved.
   * - "hook_foldershare_update" after the entity has been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are not updated for the current user since moving
   * content does not change that count the amount of content.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * The root list, this folder and all subfolders are locked for
   * exclusive editing access by this function for the duration of
   * the move.  The root list is locked while the top-level
   * folder is moved.
   *
   * @param string $newName
   *   (optional, default = '' = don't rename) The proposed new name for
   *   entity once it is moved into the root folder list. If the name is
   *   empty, the folder is not renamed.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If this item is not a folder, or if this folder, or one or more
   *   of its child files or folders, cannot be modified.
   *
   * @see ::moveToFolder()
   */
  public function moveToRoot(string $newName = '');

  /**
   * Moves this folder to a new destination folder.
   *
   * The moved files and folders retain their original ownership.
   *
   * An exception is thrown if the destination contains a file or
   * folder with the same name as this folder, or the proposed new name,
   * or if the destination is a descendant of this folder.
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_presave" before the entity is saved.
   * - "hook_foldershare_update" after the entity has been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are not updated for the current user since moving
   * content does not change that count the amount of content.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder and all subfolders are locked for exclusive editing
   * access by this function for the duration of the move.  The
   * destination folder is locked while the top-level folder is moved.
   *
   * @param \Drupal\foldershare\FolderShareInterface $dstParent
   *   (optional, default = NULL = move to root) The destination parent
   *   for this folder.
   * @param string $newName
   *   (optional, default = '' = don't rename) The proposed new name for
   *   entity once it is moved into the root folder list. If the name is
   *   empty, the folder is not renamed.
   *
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the folder could not be copied because the intended parent
   *   is this folder or a child of this folder.
   *
   * @see ::moveToRoot()
   */
  public function moveToFolder(FolderShareInterface $dstParent = NULL, string $newName = '');

  /**
   * Renames this item to use the given name.
   *
   * If this item is a file, the underlying file's name is also changed.
   * File names are subject to file name extension restrictions, if any.
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_presave" before the entity is saved.
   * - "hook_foldershare_update" after the entity has been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are not updated for the current user since renaming
   * a folder does not change that count the amount of content.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder is locked for exclusive editing access by this
   * function for the duration of the modification.
   *
   * @param string $newName
   *   The new name for this folder.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock on the item, or its parent folder, could not
   *   be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the item could not be renamed because the name is
   *   invalid or already in use in the parent folder or root list.
   *
   * @see ::setName()
   */
  public function rename(string $newName);

  /*---------------------------------------------------------------------
   *
   * File operations.
   *
   *---------------------------------------------------------------------*/

  /**
   * Adds a file to this folder.
   *
   * An exception is thrown if the file is already in a folder.
   *
   * If $allowRename is FALSE, an exception is thrown if the file's
   * name is not unique within the folder. But if $allowRename is
   * TRUE and the name is not unique, the file's name is adjusted
   * to include a sequence number immediately before the first "."
   * in the name, or at the end of the name if there is no "."
   * (e.g. "myfile.png" becomes "myfile 1.png").
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_field_values_init" hook to initialize fields.
   * - "hook_foldershare_create" after the entity has been created, but
   *   before it has been saved.
   * - "hook_foldershare_presave" before the entity is saved.
   * - "hook_foldershare_insert" after the entity has been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder is locked for exclusive editing access by this
   * function for the duration of the modification.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to be added to this folder.
   * @param bool $allowRename
   *   (optional) When TRUE, the file's name should be automatically renamed to
   *   insure it is unique within the folder. When FALSE, non-unique
   *   file names cause an exception to be thrown.  Defaults to FALSE.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns the new FolderShare entity that wraps the given File entity.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock on the folder could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the file could not be added because the file is already
   *   in a folder, or if the file doesn't pass validation because
   *   the name is invalid or already in use (if $allowRename FALSE).
   *
   * @see ::addFiles()
   */
  public function addFile(FileInterface $file, bool $allowRename = FALSE);

  /**
   * Adds files to this folder.
   *
   * An exception is thrown if any file is already in a folder.
   *
   * If $allowRename is FALSE, an exception is thrown if the file's
   * name is not unique within the folder. But if $allowRename is
   * TRUE and the name is not unique, the file's name is adjusted
   * to include a sequence number immediately before the first "."
   * in the name, or at the end of the name if there is no "."
   * (e.g. "myfile.png" becomes "myfile 1.png").
   *
   * All files are checked and renamed before any files are added
   * to the folder. If any file is invalid, an exception is thrown
   * and no files are added.
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_field_values_init" hook to initialize fields.
   * - "hook_foldershare_create" after the entities have been created, but
   *   before they have been saved.
   * - "hook_foldershare_presave" before the entities are saved.
   * - "hook_foldershare_insert" after the entities have been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder is locked for exclusive editing access by this
   * function for the duration of the modification.
   *
   * @param \Drupal\file\FileInterface[] $files
   *   An array of files to be added to this folder.  NULL files
   *   are silently skipped.
   * @param bool $allowRename
   *   (optional) When TRUE, each file's name should be automatically renamed
   *   to insure it is unique within the folder. When FALSE, non-unique
   *   file names cause an exception to be thrown.  Defaults to FALSE.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an array of new FolderShare entities that wrap the given
   *   File entities.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock on the folder could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the file could not be added because the file is already
   *   in a folder, or if the file doesn't pass validation because
   *   the name is invalid or already in use (if $allowRename FALSE).
   *
   * @see ::addFile()
   */
  public function addFiles(array $files, bool $allowRename = FALSE);

  /**
   * Adds uploaded files for the named form field into this folder.
   *
   * When a file is uploaded via an HTTP form post from a browser, PHP
   * automatically saves the data into "upload" files saved in a
   * PHP-managed temporary directory. This method sweeps those uploaded
   * files, pulls out the ones associated with the named form field,
   * and adds them to this folder with their original names.
   *
   * If there are no uploaded files, this method returns immediately.
   *
   * Files may be automatically renamed, if needed, to insure they have
   * unique names within the folder.
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_field_values_init" hook to initialize fields.
   * - "hook_foldershare_create" after the entities have been created, but
   *   before they have been saved.
   * - "hook_foldershare_presave" before the entities are saved.
   * - "hook_foldershare_insert" after the entities have been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder is locked for exclusive editing access by this
   * function for the duration of the modification.
   *
   * @param string $formFieldName
   *   The name of the form field with associated uploaded files pending.
   * @param bool $allowRename
   *   (optional, default = TRUE) When TRUE, if a file's name collides with the
   *   name of an existing entity, the name is modified by adding a number on
   *   the end so that it doesn't collide. When FALSE, a file name collision
   *   throws an exception.
   *
   * @return array
   *   The returned array contains one entry per uploaded file.
   *   An entry may be a File object uploaded into the current folder,
   *   or a string containing an error message about that file and
   *   indicating that it could not be uploaded and added to the folder.
   *   An empty array is returned if there were no files to upload
   *   and add to the folder.
   *
   * @todo The error messages returned here should be removed in favor of
   * error codes and arguments so that the caller can know which file
   * had which error and do something about it or report their own
   * error message. Returning text messages here is not flexible.
   *
   * @see ::addFile()
   * @see ::addFiles()
   */
  public function addUploadFiles(
    string $formFieldName,
    bool $allowRename = TRUE);

  /**
   * Adds PHP input as file into this folder.
   *
   * When a file is uploaded via an HTTP post handled by a web services
   * "REST" resource, the file's data is available via the PHP input
   * stream. This method reads that stream, creates a file, and adds
   * that file to this folder with the given name.
   *
   * <B>Hooks:</B>
   * Via the parent Entity class, this method calls several hooks:
   * - "hook_foldershare_field_values_init" hook to initialize fields.
   * - "hook_foldershare_create" after the entity has been created, but
   *   before it has been saved.
   * - "hook_foldershare_presave" before the entity is saved.
   * - "hook_foldershare_insert" after the entity has been saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking are updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder is locked for exclusive editing access by this
   * function for the duration of the modification.
   *
   * @param string $filename
   *   The name for the new file.
   * @param bool $allowRename
   *   (optional, default = TRUE) When TRUE, if $filename collides with the
   *   name of an existing entity, the name is modified by adding a number on
   *   the end so that it doesn't collide. When FALSE, a file name collision
   *   throws an exception.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns the newly added FolderShare entity wrapping the file.
   *
   * @see ::addFile()
   * @see ::addFiles()
   * @see ::addUploadedFiles()
   */
  public function addInputFile(string $filename, bool $allowRename = TRUE);

  /*---------------------------------------------------------------------
   *
   * Archive operations.
   *
   *---------------------------------------------------------------------*/

  /**
   * Creates a new ZIP archive file in this folder of this folder's children.
   *
   * A new ZIP archive is created in this folder and all of the children,
   * and their children, recursively, are added to the archive.
   *
   * This item must be a folder or root folder, and all of the given
   * children must in fact be children of this folder. NULL children are
   * silently ignored.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * Each child file or folder and all of their subfolders and files are
   * locked for exclusive editing access by this function while they are
   * being added to a new archive. This folder is locked while the new
   * archive is added.
   *
   * @param \Drupal\foldershare\FoldershareInterface[] $children
   *   An array of children of this folder that are to be included in a
   *   new archive added as a new child of this folder.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns the FolderShare entity for the new archive.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Thrown if this item is not a folder or root folder, or if any of
   *   the children in the array are not children of this folder.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Thrown if an access lock on this folder could not be acquired.
   *   This exception is never thrown if $lock is FALSE.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   Thrown if the archive file could not be created, such as if the
   *   temporary directory does not exist or is not writable, if a temporary
   *   file for the archive could not be created, or if any of the file
   *   children of this item could not be read and saved into the archive.
   */
  public function archiveToZip(array $children);

  /**
   * Extracts all contents of this archive and adds them to the parent folder.
   *
   * This item must be a ZIP file. All of the contents of the ZIP file are
   * extracted and added as new files and folders into this file's parent
   * folder.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This file and the parent folder are locked as items are added. If new
   * subfolders are created, those are locked too while items are added
   * to them.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Thrown if an access lock on this folder could not be acquired.
   *   This exception is never thrown if $lock is FALSE.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Thrown if the archive is not a valid archive or it has become
   *   corrpted.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   Thrown if the archive file could not be accessed or there was a
   *   problem creating any of the new files and folders from the archive.
   */
  public function unarchiveFromZip();

}
