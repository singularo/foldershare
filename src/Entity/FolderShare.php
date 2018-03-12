<?php

namespace Drupal\foldershare\Entity;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;

use Drupal\file\FileInterface;
use Drupal\file\Entity\File;
use Drupal\media\MediaInterface;
use Drupal\media\Entity\Media;

use Drupal\user\UserInterface;
use Drupal\user\Entity\User;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\FileUtilities;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Entity\FolderShareUsage;
use Drupal\foldershare\Entity\Exception\LockException;
use Drupal\foldershare\Entity\Exception\ValidationException;
use Drupal\foldershare\Entity\Exception\SystemException;
use Drupal\foldershare\Entity\Exception\NotFoundException;

/**
 * Manages a hierarchy of folders, subfolders, and files.
 *
 * FolderShare objects represent entries in a virtual file system that
 * includes folders and items in those folders.
 *
 * Every folder or file item has a set of common fields, including:
 * - Entity ID.
 * - UUID.
 * - Name.
 * - Size.
 * - Creation and changed dates.
 * - Owner.
 * - Description.
 * - Kind.
 * - MIME type.
 * - Language code.
 * - Parent folder, if any.
 * - Root folder, if any.
 *
 * The 'kind' field has one of these values:
 * - folder.
 * - rootfolder.
 * - file.
 * - image.
 * - media.
 *
 * When 'kind' is 'folder', there are no additionial fields with meaning.
 *
 * When 'kind' is 'rootfolder', the root folder (top level folder) has
 * additional fields that provide access controls for the folder tree
 * rooted at that root folder:
 * - Access grants for viewing.
 * - Access grants for authoring.
 * - Access grants for users known, but currently without viewing or authoring.
 *
 * When 'kind' is 'file', the entity has an additional field containing the
 * entity ID of a File object:
 * - File entity ID.
 *
 * When 'kind' is 'image', the entity has an additional field containing the
 * entity ID of a File object containing an image, plus attributes of that
 * image filled in by the Image module:
 * - Image entity ID and attributes.
 *
 * When 'kind' is 'media', the entity has an additional field containing the
 * entity ID of a Media object describing an arbitrary media item, plus
 * attributes of that item:
 * - Media entity ID and attributes.
 *
 * File and Image fields refer to local files managed by Drupal core and
 * the File module. The FolderShare entity handles creating, deleting,
 * and altering File entities and the local files they refer to.
 *
 * Media fields refer to media entities that may point to local files or
 * remote files, such as images or videos on a social media site. The
 * FolderShare entity handles creating, deleting, and altering Media
 * entities, but it has no control over any external media these entities
 * refer to.
 *
 * Operations on FolderShare entities include creating, deleting, moving,
 * copying, duplicating, and renaming objects. Fields on these objects may
 * be adjusted individually and, in some cases, in recursive operations
 * (such as to change ownership of an entire folder tree).
 *
 * Root folders manage access control for their entire folder tree. Access
 * checks on anything in that tree redirect up to the root folder. Being able
 * to do this quickly is why every item includes a root folder reference up
 * to the root folder high above it.
 *
 * @ingroup foldershare
 *
 * @ContentEntityType(
 *   id             = "foldershare",
 *   label          = @Translation("FolderShare folder or file"),
 *   label_singular = @Translation("FolderShare folder or file"),
 *   label_plural   = @Translation("FolderShare folders or files"),
 *   label_count    = @PluralTranslation(
 *     singular = "@count FolderShare folder or file",
 *     plural   = "@count FolderShare folders or files"
 *  ),
 *   handlers = {
 *     "access"       = "Drupal\foldershare\Entity\FolderShareAccessControlHandler",
 *     "view_builder" = "Drupal\foldershare\Entity\Builder\FolderShareViewBuilder",
 *     "views_data"   = "Drupal\foldershare\Entity\FolderShareViewsData",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\foldershare\Form\EditFolderShare",
 *       "edit"    = "Drupal\foldershare\Form\EditFolderShare",
 *     },
 *   },
 *   list_cache_contexts     = { "user" },
 *   admin_permission        = "administer foldershare",
 *   permission_granularity  = "entity_type",
 *   translatable            = FALSE,
 *   base_table              = "foldershare",
 *   fieldable               = TRUE,
 *   common_reference_target = TRUE,
 *   field_ui_base_route     = "entity.foldershare.settings",
 *   entity_keys = {
 *     "id"       = "id",
 *     "uuid"     = "uuid",
 *     "uid"      = "uid",
 *     "label"    = "name",
 *     "langcode" = "langcode",
 *   },
 *   links = {
 *     "canonical" = "/foldershare/{foldershare}",
 *   }
 * )
 */
class FolderShare extends ContentEntityBase implements FolderShareInterface {
  use EntityChangedTrait;

  /*---------------------------------------------------------------------
   *
   * Entity type id.
   *
   *---------------------------------------------------------------------*/

  /**
   * The entity type id for the FolderShare entity.
   *
   * This is 'foldershare' and it must match the entity type declaration
   * in this class's comment block.
   *
   * @var string
   */
  const ENTITY_TYPE_ID = 'foldershare';

  /*---------------------------------------------------------------------
   *
   * Item kinds.
   *
   *---------------------------------------------------------------------*/

  /**
   * The kind for a subfolder within a parent folder or root folder.
   *
   * @var string
   */
  const FOLDER_KIND = 'folder';

  /**
   * The kind for a root folder that defines the top of a folder tree.
   *
   * @var string
   */
  const ROOT_FOLDER_KIND = 'rootfolder';

  /**
   * The kind for a file within a parent folder or root folder.
   *
   * The file is a managed File entity referenced by the FolderShare entity
   * via a 'file' field type.
   *
   * @var string
   */
  const FILE_KIND = 'file';

  /**
   * The kind for an image within a parent folder or root folder.
   *
   * The file is a managed File entity referenced by the FolderShare entity
   * via a 'image' field type.
   *
   * @var string
   */
  const IMAGE_KIND = 'image';

  /**
   * The kind for a media holder within a parent folder or root folder.
   *
   * The media is a Media entity referenced by the FolderShare entity
   * via an 'entity_reference' field type.
   *
   * @var string
   */
  const MEDIA_KIND = 'media';

  /*---------------------------------------------------------------------
   *
   * Item MIME types.
   *
   *---------------------------------------------------------------------*/

  /**
   * The custom MIME type for folders.
   *
   * There is no official Internet standard for a MIME type for folders,
   * so we are forced to create one. This is primarily used for selecting
   * an icon in the user interface, and this module's styling provides
   * that icon.
   *
   * @var string
   */
  const FOLDER_MIME = 'folder/directory';

  /**
   * The custom MIME type for root folders.
   *
   * See notes above for FOLDER_MIME.
   *
   * @var string
   */
  const ROOT_FOLDER_MIME = 'rootfolder/directory';

  /*---------------------------------------------------------------------
   *
   * Path domain names.
   *
   *---------------------------------------------------------------------*/

  /**
   * The private scheme containing the user's own files.
   *
   * The scheme is used in virtual file and folder paths of the form:
   * - SCHEME://UID/PATH
   *
   * The private scheme refers to content owned by the current user.
   *
   * @var string
   */
  const PRIVATE_SCHEME = "private";

  /**
   * The public scheme containing the site's publically accessible files.
   *
   * The scheme is used in virtual file and folder paths of the form:
   * - SCHEME://UID/PATH
   *
   * The public scheme refers to content owned by another user and shared
   * with the anonymous user, which makes it publically accessible.
   *
   * @var string
   */
  const PUBLIC_SCHEME = "public";

  /**
   * The shared scheme containing files shared with the user.
   *
   * The scheme is used in virtual file and folder paths of the form:
   * - SCHEME://UID/PATH
   *
   * The shared scheme refers to content owned by other users that has
   * been specifically shared with the current user.
   *
   * @var string
   */
  const SHARED_SCHEME = "shared";

  /*---------------------------------------------------------------------
   *
   * Database tables.
   *
   *---------------------------------------------------------------------*/

  /**
   * The base table for 'foldershare' entities.
   *
   * This is 'foldershare' and it must match the base table declaration in
   * this class's comment block.
   *
   * @var string
   */
  const BASE_TABLE = 'foldershare';

  /*---------------------------------------------------------------------
   *
   * Default names.
   *
   *---------------------------------------------------------------------*/

  /**
   * The default prefix for a new folder or root folder.
   *
   * This will be translated to the user's language before use.
   *
   * @var string
   */
  const NEW_PREFIX = 'New ';

  /**
   * The default name for a new ZIP archive.
   *
   * @var string
   */
  const NEW_ZIP_ARCHIVE = 'Archive.zip';

  /*---------------------------------------------------------------------
   *
   * Default archive info.
   *
   *---------------------------------------------------------------------*/

  /**
   * The comment added to ZIP archives created by this module.
   *
   * The comment is internal to the ZIP file and rarely seen by users.
   *
   * @var string
   */
  const NEW_ARCHIVE_COMMENT = 'Created by FolderShare.';

  /*---------------------------------------------------------------------
   *
   * Archive flags.
   *
   *---------------------------------------------------------------------*/

  /**
   * Indicate how archives with multiple top-level items are unarchived.
   *
   * A ZIP archive may contain an arbitrary hierarchy of files and folders.
   * If there is a single top-level item, it is reasonable to extract and
   * add it to the current folder. But if there are multiple top-level items,
   * there are two common behaviors:
   * - Extract all top-level items into the current folder.
   * - Create a subfolder (named after the archive) in the current folder
   *   and extract everything there.
   *
   * When this flag is FALSE, the first case is done. When TRUE, the second
   * case. The second case is similar to that in macOS.
   *
   * @var bool
   */
  const UNARCHIVE_MULTIPLE_TO_SUBFOLDER = TRUE;

  /*---------------------------------------------------------------------
   *
   * Lock names.
   *
   * Folders may contain files and folders in an arbitrary hierarchy.
   * Because of this, changing a folder entity can impact a large
   * number of child files and folder entities. And those children
   * might be accessed in parallel while we are editing a parent folder.
   *
   * To prevent (or reduce) collisions made by parallel actions, we
   * need to lock/unlock around sensitive operations, such as deleting,
   * copying, or moving a folder tree.
   *
   *---------------------------------------------------------------------*/

  /**
   * A flag indicating if content locking is enabled.
   *
   * When TRUE, operations use locks to control parallel edit access
   * to folders.  When FALSE, locking is skipped - which should only
   * be done during debugging. In production use, locking must be
   * enabled or parallel write access to the same folder tree could
   * result in a corrupted file system.
   *
   * @var bool
   */
  const EDIT_CONTENT_LOCK_ENABLED = FALSE;

  /**
   * The base name of the process lock for editing folder content.
   *
   * Drupal locks may have arbitrary names. We use 'folder_edit_ID'.
   *
   * The folder content edit lock uses the folder entity ID.
   * This lock must be acquired before adding or removing any
   * folder content, including child files and folders.
   *
   * When deleting, copying, or moving a folder tree, this lock
   * is acquired for each folder in the tree as that folder is
   * being edited.  Parent folder locks are maintained while child
   * folder's are being deleted, copied, or moved.
   *
   * @var string
   */
  const EDIT_CONTENT_LOCK_NAME = 'foldershare_edit_';

  /**
   * The duration of locks (in seconds).
   *
   * All Drupal locks time out so that a crash of a Drupal process
   * that has a lock cannot leave content locked forever.  The lock
   * always times out.
   *
   * @var float
   */
  const EDIT_CONTENT_LOCK_DURATION = 30.0;

  /*---------------------------------------------------------------------
   *
   * Exception text.
   *
   * Thrown exceptions have user-readable messages that are fairly
   * generic. Messages have no length limit and are likely to be
   * presented on a web page or in a dialog that wraps text.
   *
   *---------------------------------------------------------------------*/

  // Invalid file names.
  const EXCEPTION_NAME_INVALID =
    'The name cannot be used. It must be between 1 and 255 characters long and it cannot include ":", "/", or "\" characters.';

  const EXCEPTION_NAME_IN_USE =
    'The name is already in use by another file or folder. Please select a different name.';

  const EXCEPTION_NAME_EXT_NOT_ALLOWED =
    'The file name uses a file name extension that is not allowed by this site.';

  // Programmer errors:  NULL arguments.
  const EXCEPTION_FILE_NULL =
    'Programmer error!  A file argument passed to a function is NULL. Please report this to the module developers.';

  const EXCEPTION_FOLDER_NULL =
    'Programmer error!  A folder argument passed to a function is NULL. Please report this to the module developers.';

  const EXCEPTION_ARRAY_EMPTY =
    'Programmer error! An array argument passed to a function is empty. Please report this to the module developers.';

  const EXCEPTION_ARRAY_ITEM_NULL =
    'Programmer error! An array of objects contains a NULL item. Please report this to the module developers.';

  // Programmer errors:  Bad ID arguments.
  const EXCEPTION_FOLDER_ID_INVALID =
    'Programmer error!  A folder ID argument passed to a function is invalid. Please report this to the module developers.';


  // Programmer errors:  Corrupted data or misuse.
  const EXCEPTION_ITEM_NOT_IN_FOLDER =
    'Programmer error!  An item is not in the folder being accessed.';

  const EXCEPTION_FILE_ALREADY_IN_FOLDER =
    'Programmer error!  The file is already in a folder.';

  const EXCEPTION_ITEM_NOT_FOLDER =
    'Programmer error!  The operation is only valid on a folder.';

  const EXCEPTION_ITEM_NOT_FILE =
    'Programmer error!  The operation is only valid on a file.';

  // System errors.
  const EXCEPTION_SYSTEM_DIRECTORY_CANNOT_CREATE =
    'An error occurred when trying to create a new directory for storing files on the server. Please report this to the web site administrator.';

  const EXCEPTION_SYSTEM_FILE_CANNOT_CREATE =
    'An error occurred when trying to create a new file on the server. Please report this to the web site administrator.';

  const EXCEPTION_SYSTEM_FILE_CANNOT_MOVE =
    'An error occurred when trying to move a file on the server. Please report this to the web site administrator.';

  const EXCEPTION_SYSTEM_ARCHIVE_MOVE =
    'A system error occurred while trying to add the archive into this folder.';

  const EXCEPTION_SYSTEM_ARCHIVE_CANNOT_CREATE =
    'A system error occurred when trying to create a new file for the archive.';

  const EXCEPTION_SYSTEM_ARCHIVE_CANNOT_OPEN =
    'A system error occurred when trying to open an archive.';

  const EXCEPTION_SYSTEM_ARCHIVE_CANNOT_READ =
    'A system error occurred when trying to read an archive.';

  const EXCEPTION_SYSTEM_ARCHIVE_CANNOT_ADD =
    'A system error occurred when trying to add a new file for the archive.';

  // Locking collision.
  const EXCEPTION_ITEM_IN_USE =
    'The item is in use by someone else and cannot be used at this time.';

  const EXCEPTION_FOLDER_TREE_IN_USE =
    'The folder, or one or more of its subfolders, is in use and cannot be accessed at this time.';

  const EXCEPTION_DST_FOLDER_IN_USE =
    'The destination folder is in use and cannot be updated at this time.';

  const EXCEPTION_ROOT_LIST_IN_USE =
    'The top-level folder list is in use and cannot be updated at this time.';


  // Delete.
  const EXCEPTION_SOME_NOT_DELETED =
    'One or more files could not be deleted at this time.';

  // Copy.
  const EXCEPTION_INCOMPLETE_COPY =
    'The newly created copy folder is in use and the copy cannot be completed at this time.';

  const EXCEPTION_INVALID_COPY =
    'The folder cannot be copied into itself or any of its subfolders.';

  const EXCEPTION_COPY_NAME_IN_USE =
    'The folder cannot be copied because there is already an item with the same name in the destination folder.';

  const EXCEPTION_INVALID_NONFOLDER_ROOT_COPY =
    'Only folders may be copied to become top-level folders.';

  const EXCEPTION_SYSTEM_FILE_COPY =
    'A system error occurred while trying to make a copy of a file.';

  // Move.
  const EXCEPTION_INVALID_MOVE =
    'The folder cannot be moved into itself or any of its subfolders.';

  const EXCEPTION_MOVE_NAME_IN_USE =
    'The folder cannot be moved because there is already an item with the same name in the destination folder.';

  const EXCEPTION_ROOT_CORRUPTED =
    'The file system appears to be corrupted. Please contact the site administrator.';

  // Upload.
  const EXCEPTION_SYSTEM_FILE_UPLOAD =
    'A system error occurred while trying to process an uploaded a file.';

  const EXCEPTION_SYSTEM_NO_INPUT_STREAM =
    'A system error occurred where the input stream for an uploaded file could not be opened.';

  const EXCEPTION_SYSTEM_CANNOT_CREATE_TEMP_FILE =
    'A system error occurred on an attempt to create a temporary file.';

  const EXCEPTION_SYSTEM_CANNOT_READ_INPUT_STREAM =
    'A system error occurred on an attempt to read an input stream.';

  const EXCEPTION_SYSTEM_CANNOT_WRITE_FILE =
    'A system error occurred on an attempt to write to a file.';

  // Archive.
  const EXCEPTION_ARCHIVE_NOTHING_SUITABLE =
    'There were no suitable files to add to the compression archive.';

  const EXCEPTION_ARCHIVE_CONTAINS_NAME_EXT_NOT_ALLOWED =
    'The compressed archive file contains a file that uses a file name extensions that is not allowed by this site. The archive cannot be uncompressed.';

  const EXCEPTION_ARCHIVE_REQUIRES_ZIP_EXT_NOT_ALLOWED =
    'Compressing files must create a file with a ".zip" file name extension that is not allowed by this site. The compression cannot be done.';

  /*---------------------------------------------------------------------
   *
   * Entity definition.
   *
   *---------------------------------------------------------------------*/

  /**
   * Defines the fields used by instances of this class.
   *
   * The following fields are defined, along with their intended
   * public or private access:
   *
   * | Field            | Allow for view | Allow for edit |
   * | ---------------- | -------------- | -------------- |
   * | id               | any user       | no             |
   * | uuid             | no             | no             |
   * | uid              | any user       | no             |
   * | langcode         | no             | no             |
   * | created          | any user       | no             |
   * | changed          | any user       | no             |
   * | size             | any user       | no             |
   * | kind             | any user       | no             |
   * | mime             | any user       | no             |
   * | name             | any user       | any user       |
   * | description      | any user       | any user       |
   * | parentid         | any user       | no             |
   * | rootid           | any user       | no             |
   * | file             | any user       | no             |
   * | image            | any user       | no             |
   * | media            | any user       | no             |
   * | grantauthoruids  | any user       | no             |
   * | grantviewuids    | any user       | no             |
   * | grantdisableduids| no             | no             |
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   The entity type for which we are returning base field
   *   definitions.
   *
   * @return array
   *   An array of field definitions where keys are field names and
   *   values are BaseFieldDefinition objects.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entityType) {
    //
    // Base class fields
    // -----------------
    // The parent ContentEntityBase class supports several standard
    // entity fields:
    //
    // - id: the entity ID
    // - uuid: the entity unique ID
    // - langcode: the content language
    // - revision: the revision ID
    // - bundle: the entity bundle
    //
    // The parent class ONLY defines these fields if they exist in
    // THIS class's comment block declaring class fields.  Of the
    // above fields, we only define these for this class:
    //
    // - id
    // - uuid
    // - langcode
    //
    // By invoking the parent class, we don't have to define these
    // ourselves below.
    //
    // Node adds a few fields we don't need, including promote, sticky,
    // revision_timestamp, revision_uid, and revision_tralsnation_affected.
    $fields = parent::baseFieldDefinitions($entityType);

    // But we do need to adjust display options a bit (see below).
    $weight = 0;

    //
    // Entity id.
    // - Integer.
    // - Primary database index.
    // - Read-only (set at creation time)
    // - 'view' shows ID (though usually converted to entity name)
    // - 'form' unsupported since read only.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // for all users.  The field is read only.
    //
    // Note:  This field was already defined by the parent class.
    // We just need to adjust it a little.
    // - Already has label ("ID").
    // - Already marked read only.
    // - Already marked unsigned.
    $fields[$entityType->getKey('id')]
      ->setDescription(t('The ID of the item.'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'hidden',
          'type'   => 'number_integer',
          'weight' => $weight,
        ]);
    $weight += 10;

    //
    // Unique id (UUID).
    // - Integer.
    // - Read-only (set at creation time)
    // - 'view' unsupported.
    // - 'form' unsupported.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // for all users. The field is read only.
    //
    // Note:  This field was already defined by the parent class.
    // We just need to adjust it a little to have a better description
    // and a way to view it.
    // - Already has label ("UUID").
    // - Already marked read only.
    $fields[$entityType->getKey('uuid')]
      ->setDescription(t('The UUID of the item.'))
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);
    $weight += 10;

    //
    // Language code.
    // - 'view' hidden.
    // - 'form' selects language.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // for all users.
    //
    // Note: This field is required by the ContentEntityBase base class,
    // which may use it to guide trnaslations.  We do not need to
    // implement anything further.
    //
    // Note:  This field was already defined by the parent class.
    // We just need to adjust it a little.
    // - Already has label ("Language").
    // - Already has view (hidden).
    // - Already has form (language select).
    $f = $fields[$entityType->getKey('langcode')];
    $f->setDescription(t('The original language for the item.'));
    $fa = $f->getDisplayOptions('form');
    if ($fa === NULL) {
      $fa = ['type' => 'language_select'];
    }

    $fa['weight'] = $weight;
    $f->setDisplayOptions('form', $fa);

    //
    // Common fields
    // -------------
    // Like Drupal nodes, folders have an owner, creation date,
    // changed date, and name (node calls it a title).  We use
    // definitions that are very similar to those for node fields.
    //
    // Name.
    // - String.
    // - Required, no default.
    // - 'view' shows the name.
    // - 'form' unsupported.
    //
    // Note that the 'name' field is specifically excluded from the
    // folder's default edit form. Names are handled separately via a
    // 'Rename' command that does not use the default edit form.
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The name of the item.'))
      ->setRequired(TRUE)
      ->setSettings([
        'default_value'   => 'Item',
        'max_length'      => Constants::MAX_NAME_LENGTH,
        'text_processing' => FALSE,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'hidden',
          'type'   => 'string',
          'weight' => $weight,
        ])
      ->setDisplayConfigurable('form', FALSE);
    $weight += 10;

    //
    // User (owner) id.
    // - Entity reference to user object.
    // - 'view' shows the user's name.
    // - 'form' unsupported.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // except for admins.
    //
    // Note that even for admins, the 'uid' field is specifically
    // excluded from the item's default edit form. User IDs are
    // handled separately via a 'Chown' (Change owner) command that
    // does not use the default edit form (and must recurse through
    // a folder tree).
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setDescription(t("The user ID of the item's owner."))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDefaultValueCallback(
        'Drupal\foldershare\Entity\FolderShare::getCurrentUserId')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'author',
          'weight' => $weight,
        ])
      ->setDisplayConfigurable('form', FALSE);
    $weight += 10;

    //
    // Creation date.
    // - Time stamp.
    // - 'view' shows date/time.
    // - 'form' unsupported.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // except for admins.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The date and time when the item was created.'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'timestamp',
          'weight' => $weight,
        ])
      ->setDisplayConfigurable('form', FALSE);
    $weight += 10;

    //
    // Changed (modified) date.
    // - Time stamp.
    // - 'view' shows date/time.
    // - 'form' unsupported.
    //
    // Field editing blocked by FolderShareAccessControlHandler.
    //
    // Note: This field is required by the EntityChangedTrait, which
    // implements the EntityChangedInterface.  Because we use the trait,
    // we do not need to implement anything further.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Modified'))
      ->setDescription(t('The date and time when the item was most recently modified.'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'timestamp',
          'weight' => $weight,
        ])
      ->setDisplayConfigurable('form', FALSE);
    $weight += 10;

    //
    // Custom fields
    // -------------
    // Folders have three custom fields. Parent and root IDs
    // indicate the item's immediate parent and highest root folders.
    //
    // Description.
    // - Long text.
    // - Optional, no default.
    // - 'view' shows the text.
    // - 'form' edits the text as a text area.
    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setDescription(t('The description of the item. The text may be empty, brief, or long.'))
      ->setRequired(FALSE)
      ->setSettings(['default_value' => ''])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'hidden',
          'type'   => 'text_moreless',
          'weight' => $weight,
        ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
        'form',
        [
          'type'        => 'text_textfield',
          'description' => 'Enter a description of the item. The description may be empty, brief, or long.',
          'rows'        => 20,
          'weight'      => $weight,
        ]);
    $weight += 10;

    //
    // Kind.
    // - String.
    // - Required, no default.
    // - 'view' shows the text.
    // - 'form' unsupported.
    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Kind'))
      ->setDescription(t('The kind of item, such as a file or folder.'))
      ->setRequired(TRUE)
      ->setSettings(['default_value' => ''])
      ->setSetting('is_ascii', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'hidden',
          'type'   => 'text_default',
          'weight' => $weight,
        ])
      ->setDisplayConfigurable('form', FALSE);
    $weight += 10;

    //
    // MIME.
    // - String.
    // - Required, no default.
    // - 'view' shows the text.
    // - 'form' unsupported.
    $fields['mime'] = BaseFieldDefinition::create('string')
      ->setLabel(t('MIME type'))
      ->setDescription(t("The MIME type describing a file's contents, such as a JPEG image or a PDF document."))
      ->setRequired(TRUE)
      ->setSettings(['default_value' => ''])
      ->setSetting('is_ascii', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'hidden',
          'type'   => 'text_default',
          'weight' => $weight,
        ])
      ->setDisplayConfigurable('form', FALSE);
    $weight += 10;

    //
    // Parent ID.
    // - Entity reference to parent Folder, if any.
    // - Optional, no default (empty means folder is a root folder)
    // - 'view' shows the parent folder's name.
    // - 'form' unsupported.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // for all users.
    $fields['parentid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Parent folder'))
      ->setDescription(t("The folder's parent folder. When a folder is a top-level folder, this field is empty (NULL)."))
      ->setRequired(FALSE)
      ->setSetting('target_type', self::ENTITY_TYPE_ID)
      ->setSetting('handler', 'default')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'hidden',
          'type'   => 'entity_reference_label',
          'weight' => $weight,
        ]);
    $weight += 10;

    //
    // Root ID.
    // - Entity reference to root Folder, if any.
    // - Optional, no default (empty means folder is a root folder)
    // - 'view' shows the root folder's name.
    // - 'form' unsupported.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // for all users.
    $fields['rootid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Top-level folder'))
      ->setDescription(t("The folder's highest folder ancestor. When a folder is a top-level folder itself, this points back to the same folder."))
      ->setRequired(FALSE)
      ->setSetting('target_type', self::ENTITY_TYPE_ID)
      ->setSetting('handler', 'default')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'hidden',
          'type'   => 'entity_reference_label',
          'weight' => $weight,
        ]);
    $weight += 10;

    //
    // Size.
    // - Big integer.
    // - Optional, no default (filled in as needed by auto size calc)
    // - 'view' shows the size.
    // - 'form' unsupported.
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // for all users.
    $fields['size'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Size'))
      ->setDescription(t('The storage space (in bytes) used by the file or folder, and all of its child files and folders.'))
      ->setRequired(FALSE)
      ->setSetting('size', 'big')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'foldershare_storage_size',
          'weight' => $weight,
        ]);
    $weight += 10;

    //
    // File.
    // - A single File entity for a file item in a folder (i.e. kind == file).
    // - 'view' shows item.
    // - 'form' unsupported (custom code later).
    //
    // Field editing is blocked by FolderShareAccessControlHandler
    // for all users.
    //
    // Because folders are intended to contain any type of file,
    // we would rather not restrict the set of file extensions.
    // Unfortunately, this is currently not possible:
    // @see https://www.drupal.org/node/997900
    //
    // Instead, elsewhere we provide our own handling of uploaded
    // files in order to take control over file extension testing.
    //
    // File names are constrained to not be empty, not include :, /, or \,
    // and not collide with an existing file. We check names explicitly
    // durring add/rename operations instead of using a field constraint,
    // which would be less efficient since it would require checking and
    // rechecking the name every time the entity was modified for any
    // reason (such as changing its description).
    //
    // A 'file' field type extends an entity reference field type and
    // adds these stored values with every instance:
    // - display     = true/false for whether the file should be shown.
    // - description = description text for the file.
    //
    // The 'file' field also adds these settings applied to this usage
    // of the field type:
    // - file_extensions   = the allowed extensions list.
    // - file_directory    = a subdirectory in which to store files.
    // - max_filesize      = the maximum uploaded file size allowed.
    // - description_field = whether to include a description per file.
    //
    // FolderShare does not use all of these features of 'file' fields:
    // - description_field is set to FALSE and the description text is
    //   never set or used. The containing FolderShare entity has its
    //   own description field.
    // - file_directory is ignored and FolderShare determines the directory
    //   path on its own (see FolderShareStream).
    // - max_filesize is ignored in favor of module settings that control
    //   upload sizes.
    $extensions = '';
    if (Settings::getFileRestrictExtensions() === TRUE) {
      $extensions = Settings::getFileAllowedExtensions();
    }

    $fields['file'] = BaseFieldDefinition::create('file')
      ->setLabel(t('File'))
      ->setDescription(t('A non-image file within a folder.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'file')
      ->setSetting('handler', 'default')
      ->setSetting('file_extensions', $extensions)
      ->setSetting('description_field', FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'file_default',
          'weight' => $weight,
        ]);
    $weight += 10;

    //
    // Image.
    // - A single Image entity for a file item in a folder (i.e. kind == image).
    // - 'view' shows item.
    // - 'form' unsupported (custom code later).
    //
    // See comments above for the file field.
    //
    // An 'image' field type extends the 'file' field type and adds these
    // stored values for every instance:
    // - alt    = alternate text for the image's 'alt' attribute.
    // - title  = title text for the image's 'title' attribute.
    // - width  = the image width.
    // - height = the image height.
    //
    // The 'image' field also adds these settings applied to this usage
    // of the field type:
    // - alt_field            = whether to include an alt value per image.
    // - alt_field_required   = whether alt text is required.
    // - title_field          = whether to include a title value per image.
    // - title_field_required = whether title text is required.
    // - min_resolution       = the minimum accepted image resolution.
    // - max_resolution       = the maximum accepted image resolution.
    // - default_image        = a default image if none is provided.
    //
    // FolderShare does not use all of these features of 'image' fields:
    // - alt_field and title_field are not used and alt_field_required
    //   and title_field_required are set to FALSE.
    // - default_image is not used because the image field is only set if
    //   we have an image file.
    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Image'))
      ->setDescription(t('An image file within a folder.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'file')
      ->setSetting('handler', 'default')
      ->setSetting('file_extensions', $extensions)
      ->setSetting('description_field', FALSE)
      ->setSetting('alt_field', FALSE)
      ->setSetting('alt_field_required', FALSE)
      ->setSetting('title_field', FALSE)
      ->setSetting('title_field_required', FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'image',
          'weight' => $weight,
        ]);
    $weight += 10;

    //
    // Media.
    // - A single Media entity for a media item in a folder (i.e. kind ==media).
    // - 'view' shows item.
    // - 'form' unsupported (custom code later).
    //
    // See comments above for the file field.
    //
    // Media entities do not have a corresponding media field type. Instead
    // we refer to a media entity using an entity_reference.
    $fields['media'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Media'))
      ->setDescription(t('A media item within a folder.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'media')
      ->setSetting('handler', 'default')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'media_thumbnail',
          'weight' => $weight,
        ]);
    $weight += 10;

    //
    // Access control for author access.
    // Access control for view access.
    // Access control for disabled access (but still show in share form).
    // - List of UIDs.
    // - 'view' shows author names (except disabled field).
    // - 'form' unsupported (custom code required).
    //
    // Field editing blocked by FolderShareAccessControlHandler
    // for all users.
    //
    $fields['grantauthoruids'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author grants'))
      ->setDescription(t("A list of IDs for users that have been granted author access to the item. Only top-level folders have values. The item's owner is always on this list."))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'author',
          'weight' => $weight,
        ]);
    $weight += 10;

    $fields['grantviewuids'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Viewer grants'))
      ->setDescription(t("A list of IDs for users that have been granted view access to the item. Only top-level folders have values. The item's owner is always on this list."))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions(
        'view',
        [
          'label'  => 'inline',
          'type'   => 'author',
          'weight' => $weight,
        ]);
    $weight += 10;

    $fields['grantdisableduids'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Disabled grants'))
      ->setDescription(t('A list of IDs for users that may have been granted access to the item at one time, but who currently have access disabled. Only top-level folders have values.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

  /*---------------------------------------------------------------------
   *
   * Entity management.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   *
   * This method is called immediately after a entity has been saved.
   * If the core 'search' module is installed, the entity is marked
   * for search re-indexing.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    // Let the parent class do its work.
    parent::postSave($storage, $update);

    // If search module is enabled, mark entity as in need of re-indexing.
    if ($update === TRUE &&
        \Drupal::moduleHandler()->moduleExists('search') === TRUE) {
      search_mark_for_reindex(Constants::SEARCH_INDEX, (int) $this->id());
    }
  }

  /**
   * {@inheritdoc}
   *
   * This method is called immediately before an entity is deleted.
   * If the core 'search' module is installed, the search index entry
   * for the entity is cleared.
   */
  public static function preDelete(
    EntityStorageInterface $storage,
    array $items) {
    // Let the parent class do its work.
    parent::preDelete($storage, $items);

    // If search module is enabled, clear entity from the search index.
    if (\Drupal::moduleHandler()->moduleExists('search') === TRUE) {
      // Loop through each of the entities about to be deleted.
      foreach ($items as $item) {
        // Clear the entities's entry in the search index.
        search_index_clear(
          Constants::SEARCH_INDEX,
          (int) $item->id(),
          $item->langcode->value);
      }
    }
  }

  /*---------------------------------------------------------------------
   *
   * Get/set file name extensions.
   *
   * During file upload and rename, file name extensions may be
   * restricted to those on an approved list. If the list is empty,
   * all extensions are allowed.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns file name extensions allowed for files and images in a folder.
   *
   * The field definition for the 'file' field (which is always the same
   * as for the 'image' field) is queried and its current file name extensions
   * setting returned. This setting is a single string containing a
   * space-separated list of allowed file name extensions. Extensions do
   * not include a leading "dot".
   *
   * File name extensions are always lower case. There are no redundant
   * extensions. Extensions in the list are not ordered.
   *
   * If the list of extensions is empty, then any extension is allowed
   * for uploaded and renamed files.
   *
   * File name extension handling is managed by the module during file
   * uploads.
   *
   * <B>Access control:</B>
   * This function does not check permissions or access controls.
   * None are required.
   *
   * <B>Locking:</B>
   * This function does not lock anything for exclusive access.
   * This is not required.
   *
   * @return string
   *   A string containing a space-separated list of file
   *   extensions (without the leading dot) supported for folder files.
   *
   * @see ::setFileAllowedExtensions()
   * @see ::renameFile()
   * @see ::addUploadFiles()
   * @see FileUtilities::isNameExtensionAllowed()
   * @see \Drupal\foldershare\Settings::getFileAllowedExtensionsDefault()
   * @see \Drupal\foldershare\Settings::getFileAllowedExtensions()
   */
  public static function getFileAllowedExtensions() {
    // Get the extensions string on the 'file' field. These will always be
    // the same as on the 'image' field.
    $m = \Drupal::service('entity_field.manager');
    $def = $m->getFieldDefinitions(
      self::ENTITY_TYPE_ID,
      self::ENTITY_TYPE_ID);

    return $def['file']->getSetting('file_extensions');
  }

  /**
   * Sets the file name extensions allowed for files and images in a folder.
   *
   * The field definitions for the 'file' and 'image' fields are changed and
   * their current file name extensions settings updated. This setting is a
   * single string containing a space-separated list of allowed file name
   * extensions. Extensions do not include a leading "dot".
   *
   * File name extensions are automatically folded to lower case.
   * Redundant extensions are removed.
   *
   * If the list of extensions is empty, then any extension is allowed
   * for uploaded and renamed files.
   *
   * File name extension handling is managed by the module during file
   * uploads.
   *
   * <B>Access control:</B>
   * This function does not check permissions or access controls.
   * None are required.
   *
   * <B>Locking:</B>
   * This function does not lock anything for exclusive access.
   * This is not required.
   *
   * @param string $extensions
   *   A string containing a space list of file name extensions
   *   (without the leading dot) supported for folder files.
   *
   * @see ::getFileAllowedExtensions()
   * @see \Drupal\foldershare\Settings::getFileAllowedExtensionsDefault()
   * @see \Drupal\foldershare\Settings::setFileAllowedExtensions()
   */
  public static function setFileAllowedExtensions(string $extensions) {
    if (empty($extensions) === TRUE) {
      // The given extensions list is empty, so no further processing
      // is required.
      $uniqueExtensions = '';
    }
    else {
      // Fold the entire string to lower case. Then split it into
      // individual extensions.  Use multi-byte character functions
      // so that we can support UTF-8 file names and extensions.
      $extList = mb_split(' ', mb_convert_case($extensions, MB_CASE_LOWER));

      // Check for and remove any leading dot on extensions.
      foreach ($extList as $key => $value) {
        if (mb_strpos($value, '.') === 0) {
          $extList[$key] = mb_substr($value, 1);
        }
      }

      // Remove redundant extensions and rebuild the list string.
      $uniqueExtensions = implode(' ', array_unique($extList));
    }

    // Set the extensions string on the 'file' and 'image' fields.
    $m = \Drupal::service('entity_field.manager');
    $def = $m->getFieldDefinitions(
      self::ENTITY_TYPE_ID,
      self::ENTITY_TYPE_ID);

    $cfd = $def['file']->getConfig(self::ENTITY_TYPE_ID);
    $cfd->setSetting('file_extensions', $uniqueExtensions);
    $cfd->save();

    $cfd = $def['image']->getConfig(self::ENTITY_TYPE_ID);
    $cfd->setSetting('file_extensions', $uniqueExtensions);
    $cfd->save();

    // There is no equivalent file extensions setting for media fields.
  }

  /*---------------------------------------------------------------------
   *
   * General utilities.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the current user ID.
   *
   * This function provides the deault value callback for the 'uid'
   * base field definition.
   *
   * @return array
   *   An array of default values. In this case, the array only
   *   contains the current user ID.
   *
   * @see ::baseFieldDefinitions()
   */
  public static function getCurrentUserId() {
    return [\Drupal::currentUser()->id()];
  }

  /*---------------------------------------------------------------------
   *
   * File & folder names.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns TRUE if a proposed name is unique within this folder.
   *
   * When a file or folder is NEW and a proposed name is provided,
   * this method checks if the name is in use already by any other
   * file or folder in the parent folder.  True is returned if the
   * name is unique, and FALSE otherwise.
   *
   * When a file or folder is NOT NEW and is being renamed, or
   * redundantly checked for validity, the proposed name might already
   * be in use by the file or folder itself. This is not an error.
   * To catch this case, an optional second argument provides the ID
   * of the existing file or folder and name comparisons against this
   * ID are ignored. True is returned if the given name is unique or
   * matches the name for the ID item, and FALSE otherwise.
   *
   * @param string $name
   *   A proposed file or folder name.
   * @param int $inUseId
   *   (optional) The file or folder ID of an existing item who is already
   *   using the proposed name. Defaults to -1.
   *
   * @return bool
   *   Returns TRUE if the name is unique within the folder, and
   *   FALSE otherwise.
   *
   * @see FileUtilities::isNameLegal()
   * @see FileUtilities::isNameExtensionAllowed()
   * @see ::isRootNameUnique()
   * @see ::createUniqueName()
   */
  public function isNameUnique(string $name, int $inUseId = (-1)) {

    // Get an array of all child file and folder names. The array has
    // names as keys and IDs as values.
    $childNames = $this->getChildNames();

    // If the proposed name is not in the list, return TRUE.
    if (isset($childNames[$name]) === FALSE) {
      return TRUE;
    }

    // If we have an exclusion ID and that's the child name list
    // entry that matches the given name, return TRUE anyway because
    // the name is in use by the intended ID.
    if ($inUseId !== -1 && $childNames[$name] === $inUseId) {
      return TRUE;
    }

    // Otherwise the name is already in use, and not by the
    // exclusion ID.
    return FALSE;
  }

  /**
   * Returns TRUE if a proposed name is unique among root folders.
   *
   * When a root folder is NEW and a proposed name is provided,
   * this method checks if the name is in use already by any other
   * root folder FOR THE SAME USER.  True is returned if the
   * name is unique, and FALSE otherwise.
   *
   * When a folder is NOT NEW and is being renamed, or
   * redundantly checked for validity, the proposed name might already
   * be in use by the folder itself. This is not an error.
   * To catch this case, an optional second argument provides the ID
   * of the existing folder and name comparisons against this
   * ID are ignored. True is returned if the given name is unique or
   * matches the name for the ID item, and FALSE otherwise.
   *
   * @param string $name
   *   A proposed root folder name.
   * @param int $inUseId
   *   (optional, default = -1 = none) The folder ID of an existing folder
   *   who is already using the proposed name.
   * @param int $uid
   *   (optional, default = -1 = the current user) The user ID of the user
   *   among whose root folders the name msut be unique.
   *
   * @return bool
   *   Returns TRUE if the name is unique among this user's root
   *   folders, and FALSE otherwise.
   *
   * @see FileUtilities::isNameLegal()
   * @see FileUtilities::isNameExtensionAllowed()
   * @see ::isNameUnique()
   * @see ::createUniqueName()
   */
  public static function isRootNameUnique(string $name, int $inUseId = -1, int $uid = -1) {

    // Get an array of all root folders names for this user.
    // The array has names as keys and IDs as values.
    if ($uid === (-1)) {
      $uid = \Drupal::currentUser()->id();
    }

    $rootNames = self::getAllRootFolderNames($uid);

    // If the proposed name is not in the list, return TRUE.
    if (isset($rootNames[$name]) === FALSE) {
      return TRUE;
    }

    $usedBy = $rootNames[$name];

    // If we have an exclusion ID and that's the root name list
    // entry that matches the given name, return TRUE anyway because
    // the name is in use by the intended ID.
    if ($inUseId !== (-1) && $usedBy === $inUseId) {
      return TRUE;
    }

    // Otherwise the name is already in use, and not by the
    // exclusion ID.
    return FALSE;
  }

  /**
   * Returns a name, or variant, adjusted to insure uniqueness.
   *
   * If the given name is not in the names-in-use list, the name is
   * returned as-is. Otherwise the given suffix (if any) is added and
   * checked against the names-in-use list. If the suffix-ed name is
   * not in the list, it is returned. Otherwise, a series of incrementing
   * numbers are added after the suffix and tested until a unique name
   * is found and returned.
   *
   * A typical suffix might be " copy" (note the leading space to
   * separate it from the body of the name).
   *
   * The suffix and numbers are added immediately before the first
   * dot in the name. If there are no dots in the name, the suffix
   * and numbers are added to the end of the name.
   *
   * Example:  For name "myfile.png" and suffix " copy", the names
   * tested (in order) are:
   * - "myfile.png"
   * - "myfile copy.png"
   * - "myfile copy 1.png"
   * - "myfile copy 2.png"
   * - "myfile copy 3.png"
   *
   * Example:  For name "myfolder" and suffix " archive", the names
   * tested (in order) are:
   * - "myfolder"
   * - "myfolder archive"
   * - "myfolder archive 1"
   * - "myfolder archive 2"
   * - "myfolder archive 3"
   *
   * If adding the suffix and/or number makes the name longer than
   * 255 characters, the end of the original name is cropped to make
   * room for the suffix and number.
   *
   * False is returned under several conditions:
   * - The given name is empty.
   *
   * - The name needs a suffix, but the suffix is >= 255 characters,
   *   which leaves no room for the name before it.
   *
   * - The suffix + number is >= 255 characters, which leaves no
   *   room for the name before it.
   *
   * - No unique name could be found after checking running through
   *   all possible name + suffix + number + extension results for
   *   increasing numbers.  However, this is extrordinarily unlikely
   *   since it would require a huge names-in-use list that would probably
   *   exceed the memory limits of a PHP instance, or the time allotted
   *   to the instance.
   *
   * @param array $namesInUse
   *   An array of names to in use, where keys are names and values
   *   are entity IDs.
   * @param string $name
   *   A desired name string to return, if it does not collide with
   *   any of the exclusion names.
   * @param string $suffix
   *   (optional) A suffix to add during tries to find a new unique
   *   name that doesn't collide with any of the exclusion names.
   *   Defaults to an empty string.
   *
   * @return false|string
   *   Returns a unique name that starts with the given name and
   *   may include the given suffix and a number such that it does not
   *   collide with any of the names in $namesInUse.  False is returned
   *   on failure.
   *
   * @see ::isNameUnique()
   * @see ::isRootNameUnique()
   * @see \Drupal\foldershare\Constants::MAX_NAME_LENGTH
   */
  private static function createUniqueName(
    array $namesInUse,
    string $name,
    string $suffix = '') {

    // Validate
    // --------
    // If no name, then fail.
    if (empty($name) === TRUE) {
      return FALSE;
    }

    //
    // Check for unmodified name
    // -------------------------
    // If name is not in use, then allow.
    if (isset($namesInUse[$name]) === FALSE) {
      return $name;
    }

    //
    // Setup for renaming
    // ------------------
    // Break down the name into a base name before the FIRST '.',
    // and the extension after the FIRST '.'.  There may be no
    // extension if there is no '.'.
    //
    // Note that we call everything after the FIRST dot the extension.
    // These are the parts of the file name that must be retained for
    // the file to be treated properly (e.g. ".tar.gz").  All name
    // changes we make below must be before this FIRST dot.
    //
    // Note:  We must use multi-byte functions to support the
    // multi-byte characters of UTF-8 names.
    $parts = mb_split('\.', $name, 2);
    if (count($parts) === 2) {
      $base = $parts[0];
      $ext = '.' . $parts[1];
    }
    else {
      // No '.' was found, so no extension.
      $base = $name;
      $ext = '';
    }

    if ($suffix === NULL) {
      $suffix = '';
    }

    if (mb_strlen($suffix . $ext) >= Constants::MAX_NAME_LENGTH) {
      // The suffix and/or extension are huge. They leave no
      // room for the base name within the character budget.
      // There is no name modification we can do that will produce
      // a short enough name.
      return FALSE;
    }

    //
    // Check for name + suffix + extension
    // -----------------------------------
    // If there is a suffix, check that there's room to add it.
    // Then add it and see if that is sufficient to create a
    // unique name.
    if (empty($suffix) === FALSE) {
      $name = $base . $suffix . $ext;

      if (mb_strlen($name) > Constants::MAX_NAME_LENGTH) {
        // The built name is too long.  Crop the base name.
        $len = (Constants::MAX_NAME_LENGTH - mb_strlen($name));
        $base = mb_substr($base, 0, $len);
        $name = $base . $suffix . $ext;
      }

      if (isset($namesInUse[$name]) === FALSE) {
        return $name;
      }
    }

    //
    // Check for name + suffix + number + extension
    // --------------------------------------------
    // Otherwise, start adding a number, counting up from 1.
    // This search continues indefinitely until a number is found
    // that is not in use.
    $num = 1;

    // Intentional infinite loop.
    while (TRUE) {
      $name = $base . $suffix . ' ' . $num . $ext;

      if (mb_strlen($name) > Constants::MAX_NAME_LENGTH) {
        // The built name is too long.  Crop the base name.
        $len = (Constants::MAX_NAME_LENGTH - mb_strlen($name));
        if ($len <= 0) {
          break;
        }

        $base = mb_substr($base, 0, $len);
        $name = $base . $suffix . ' ' . $num . $ext;
      }

      if (isset($namesInUse[$name]) === FALSE) {
        return $name;
      }

      ++$num;
    }

    // One of two errors has occurred:
    // - Every possible name has been generated and found in use.
    //
    // - The suffix + number + extension for a large number has
    //   consumed the entire character budget for names.  There is
    //   no room left for even one character of the original name.
    //
    // These are both very unlikely errors. They require either a
    // huge set of names already in use, or an unreasonably large
    // suffix and extension that left very little room to search for
    // a new name.
    return FALSE;
  }

  /*---------------------------------------------------------------------
   *
   * Locking.
   *
   * There are three acquire and release pairs:
   * - Folder lock
   *   - Manage the lock on a single folder to limit changes to
   *     the folder's name, description, or list of folders.
   *     The folder may be a root folder or a subfolder.
   *
   * - Root folders lock
   *   - Manage the lock on the root folder list to limit changes to
   *     the list, such as to add or remove from the list.
   *
   * - Deep folder lock
   *   - Manage the lock on a folder AND all subfolders, recursively.
   *     This requires that multiple single folder locks be managed
   *     and is used to limit access to an entire folder tree.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the name of the item edit lock.
   *
   * Encapsulating this within a function helps insure that all uses
   * of an edit lock on the item use the same name.
   *
   * @return string
   *   The edit lock name.
   *
   * @see ::acquireDeepLock()
   * @see ::acquireLock()
   * @see ::getRootEditLockName()
   */
  private function getItemEditLockName() {
    return self::EDIT_CONTENT_LOCK_NAME . $this->id();
  }

  /**
   * Returns the name of the root folder list edit lock.
   *
   * Encapsulating this within a function helps insure that all uses
   * of an edit lock on the roots use the same name.
   *
   * @return string
   *   The edit lock name
   *
   * @see ::acquireDeepLock()
   * @see ::acquireRootLock()
   * @see ::getItemEditLockName()
   */
  private static function getRootEditLockName() {
    return self::EDIT_CONTENT_LOCK_NAME . 'ROOT';
  }

  /**
   * Acquires a lock on this folder item.
   *
   * @return bool
   *   True if a lock on this item was acquired, and FALSE otherwise.
   *
   * @see ::acquireDeepLock()
   * @see ::acquireRootLock()
   * @see ::getItemEditLockName()
   * @see ::releaseLock()
   */
  private function acquireLock() {
    if (self::EDIT_CONTENT_LOCK_ENABLED === FALSE) {
      return TRUE;
    }

    return \Drupal::lock()->acquire(
      $this->getItemEditLockName(),
      self::EDIT_CONTENT_LOCK_DURATION);
  }

  /**
   * Acquires a lock on the root folder list.
   *
   * @return bool
   *   True if a lock on the root folder list was acquired,
   *   and FALSE otherwise.
   *
   * @see ::acquireDeepLock()
   * @see ::acquireLock()
   * @see ::getRootEditLockName()
   * @see ::releaseRootLock()
   */
  private static function acquireRootLock() {
    if (self::EDIT_CONTENT_LOCK_ENABLED === FALSE) {
      return TRUE;
    }

    return \Drupal::lock()->acquire(
      self::getRootEditLockName(),
      self::EDIT_CONTENT_LOCK_DURATION);
  }

  /**
   * Acquires a lock on this folder and all descendant folders.
   *
   * On failure, all acquired locks are released.
   *
   * @return bool
   *   True if a lock on this folder, and all descendants, was
   *   acquired, and FALSE otherwise.
   *
   * @see ::acquireLock()
   * @see ::acquireRootLock()
   * @see ::getRootEditLockName()
   * @see ::getItemEditLockName()
   * @see ::releaseDeepLock()
   */
  private function acquireDeepLock() {
    if (self::EDIT_CONTENT_LOCK_ENABLED === FALSE) {
      return TRUE;
    }

    // Lock this folder.
    if ($this->isRootFolder() === TRUE) {
      if (\Drupal::lock()->acquire(
        $this->getRootEditLockName(),
        self::EDIT_CONTENT_LOCK_DURATION) === FALSE) {
        return FALSE;
      }
    }
    else {
      if (\Drupal::lock()->acquire(
        $this->getItemEditLockName(),
        self::EDIT_CONTENT_LOCK_DURATION) === FALSE) {
        return FALSE;
      }
    }

    // Recurse through all child folders.  If any one of them
    // fails, release prior locks.
    $storage = $this->entityTypeManager()->getStorage(
      $this->getEntityTypeId());
    $childFolderIds = $this->getChildFolderIds();
    $lockedChildFolders = [];

    foreach ($childFolderIds as $id) {
      $childFolder = $storage->load($id);
      if ($childFolder === NULL) {
        continue;
      }

      if ($childFolder->acquireDeepLock() === TRUE) {
        $lockedChildFolders[] = $childFolder;
      }
      else {
        // Failed to acquire lock on the child folder or one
        // of its subfolders. Back out.
        foreach ($lockedChildFolders as $folder) {
          $folder->releaseDeepLock();
        }

        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Releases a lock on this folder item only.
   *
   * @see ::acquireDeepLock
   * @see ::acquireLock
   * @see ::releaseDeepLock
   * @see ::releaseRootLock
   */
  private function releaseLock() {
    if (self::EDIT_CONTENT_LOCK_ENABLED === FALSE) {
      return;
    }

    return \Drupal::lock()->release(
      $this->getItemEditLockName(),
      self::getRootEditLockName());
  }

  /**
   * Releases a lock on the root folder list.
   *
   * @see ::acquireDeepLock
   * @see ::acquireLock
   * @see ::releaseDeepLock
   * @see ::releaseLock
   */
  private static function releaseRootLock() {
    if (self::EDIT_CONTENT_LOCK_ENABLED === FALSE) {
      return;
    }

    return \Drupal::lock()->release(
      self::getRootEditLockName());
  }

  /**
   * Releases a lock on this folder and all descendant folders.
   *
   * @see ::acquireLock
   * @see ::acquireRootLock
   * @see ::releaseLock
   * @see ::releaseRootLock
   */
  private function releaseDeepLock() {
    if (self::EDIT_CONTENT_LOCK_ENABLED === FALSE) {
      return;
    }

    // Release lock on this folder.
    if ($this->isRootFolder() === TRUE) {
      \Drupal::lock()->release(
        $this->getRootEditLockName(),
        self::getRootEditLockName());
    }
    else {
      \Drupal::lock()->release(
        $this->getItemEditLockName(),
        self::getRootEditLockName());
    }

    // Recurse through all child folders and release their locks.
    $storage = $this->entityTypeManager()->getStorage(
      $this->getEntityTypeId());
    $childFolderIds = $this->getChildFolderIds();

    foreach ($childFolderIds as $id) {
      $childFolder = $storage->load($id);
      if ($childFolder === NULL) {
        continue;
      }

      $childFolder->releaseDeepLock();
    }
  }

  /*---------------------------------------------------------------------
   *
   * Fields access.
   *
   * All of the following methods get/set fields within an entity.
   *
   * Some fields are supported by parent class methods:
   *
   * | Field            | Get method                           |
   * | ---------------- | ------------------------------------ |
   * | id               | ContentEntityBase::id()              |
   * | uuid             | ContentEntityBase::uuid()            |
   * | name (label)     | ContentEntityBase::getName()         |
   * | changed          | EntityChangedTrait::getChangedTime() |
   * | langcode         | ContentEntityBase::language()        |
   *
   * Some fields are supported by methods here:
   *
   * | Field            | Get method                           |
   * | ---------------- | ------------------------------------ |
   * | uid              | getOwner()                           |
   * | description      | getDescription()                     |
   * | created          | getCreatedTime()                     |
   * | size             | getSize()                            |
   * | kind             | getKind()                            |
   * | mime             | getMimeType()                        |
   * | file             | getFileId()                          |
   * | image            | getImageId()                         |
   * | media            | getMediaId()                         |
   *
   * Some fields have no get/set methods or no set methods because handling
   * them requires special code and context:
   *
   * | Field            | Get method                           |
   * | ---------------- | ------------------------------------ |
   * | parentid         |                                      |
   * | rootid           |                                      |
   * | file             |                                      |
   * | image            |                                      |
   * | media            |                                      |
   *
   * File, image, media, and folder handling special methods are later
   * in this class.
   *
   *---------------------------------------------------------------------*/

  /*---------------------------------------------------------------------
   *
   * Kind field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getKind() {
    return $this->get('kind')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isFile() {
    return ($this->get('kind')->value === self::FILE_KIND);
  }

  /**
   * {@inheritdoc}
   */
  public function isFolder() {
    return ($this->get('kind')->value === self::FOLDER_KIND);
  }

  /**
   * {@inheritdoc}
   */
  public function isFolderOrRootFolder() {
    $v = $this->get('kind')->value;
    return ($v === self::FOLDER_KIND) || ($v === self::ROOT_FOLDER_KIND);
  }

  /**
   * {@inheritdoc}
   */
  public function isImage() {
    return ($this->get('kind')->value === self::IMAGE_KIND);
  }

  /**
   * {@inheritdoc}
   */
  public function isMedia() {
    return ($this->get('kind')->value === self::MEDIA_KIND);
  }

  /**
   * {@inheritdoc}
   */
  public function isRootFolder() {
    return ($this->get('kind')->value === self::ROOT_FOLDER_KIND);
  }

  /*---------------------------------------------------------------------
   *
   * MIME type field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getMimeType() {
    return $this->get('mime')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMimeType(string $mime) {
    $this->set('mime', $mime);
    return $this;
  }

  /*---------------------------------------------------------------------
   *
   * File, image, and media fields.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getFileId() {
    if ($this->isFile() === TRUE) {
      return (int) $this->get('file')->target_id;
    }

    return (-1);
  }

  /**
   * {@inheritdoc}
   */
  public function getFile() {
    if ($this->isFile() === TRUE) {
      $id = $this->getFileId();
      if ($id !== (-1)) {
        return File::load($this->getFileId());
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getImageId() {
    if ($this->isImage() === TRUE) {
      return (int) $this->get('image')->target_id;
    }

    return (-1);
  }

  /**
   * {@inheritdoc}
   */
  public function getImage() {
    if ($this->isImage() === TRUE) {
      $id = $this->getImageId();
      if ($id !== (-1)) {
        // The image module does not define an Image entity type.
        // Instead it uses the File entity type, but references it
        // via an image field type. So, to load an image, we need
        // to use the File module.
        return File::load($this->getImageId());
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMediaId() {
    if ($this->isMedia() === TRUE) {
      return (int) $this->get('media')->target_id;
    }

    return (-1);
  }

  /**
   * {@inheritdoc}
   */
  public function getMedia() {
    if ($this->isMedia() === TRUE) {
      $id = $this->getMediaId();
      if ($id !== (-1)) {
        return Media::load($this->getMediaId());
      }
    }

    return NULL;
  }

  /*---------------------------------------------------------------------
   *
   * Name field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setName(string $name) {
    $this->set('name', $name);
    return $this;
  }

  /*---------------------------------------------------------------------
   *
   * Size field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function clearSize() {
    if ($this->isFolderOrRootFolder() === TRUE) {
      $this->set('size', NULL);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSize() {
    $value = $this->get('size')->getValue();
    if (empty($value) === TRUE) {
      return -1;
    }

    return $value[0]['value'];
  }

  /**
   * {@inheritdoc}
   */
  public function setSize(int $size) {
    if ($size <= -1) {
      $this->set('size', NULL);
    }
    else {
      $this->set('size', $size);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateSize() {
    //
    // Update the stored size for folders and root folders with a size
    // computed by traversing a subfolder tree.
    //
    // Return immediately if there is already a size set.
    //
    // This is essential when this method is used by a worker queue.
    // Such a queue may start a job to update sizes on a large folder
    // tree. This will call this function, which recurses through
    // the tree to do a depth-first update.
    //
    // If that recursion takes too long, the queued job may be aborted
    // on a time-out. Any folders it has already updated will have
    // already had their values set. When the queued job is restarted,
    // the next recurse will find those updates and not waste time
    // doing those folders over again.
    $size = $this->getSize();
    if ($this->isFolderOrRootFolder() === FALSE || $size !== -1) {
      return $size;
    }

    $size = 0;

    //
    // Child folders
    // -------------
    // Loop and recurse through all child folders and sum their sizes.
    foreach ($this->getChildFolders() as $folder) {
      // Recurse.
      $size += $folder->updateSize();
    }

    //
    // Child files
    // -----------
    // Loop through all children and sum their sizes.
    foreach ($this->getChildFiles() as $file) {
      $size += $file->getSize();
    }

    //
    // Set and save
    // ------------
    // Set the size and save it.
    $this->setSize($size);
    $this->save();

    return $size;
  }

  /**
   * Updates the folders storage sizes, in bytes, by summing their children.
   *
   * The given array contains a list of entity IDs for folders
   * to be updated. Folder updating is recursive, so it is sufficient
   * to include only the top-level folder entity ID for each folder
   * tree to be updated.
   *
   * If the array is empty, no updates are done.  Invalid folder IDs
   * in the array are silently skipped. IDs for items that are not folders
   * are silently skipped.
   *
   * After the size is updated, each folder is saved.
   *
   * @param array $itemIds
   *   (optional) The entity IDs of folders whose sizes need to be
   *   updated.  Defaults to an empty array.
   *
   * @see ::clearSize()
   * @see ::getKind()
   * @see ::getSize()
   * @see ::setSize()
   * @see ::updateSize()
   * @see ::enqueueUpdateSizes()
   * @see \Drupal\file\FileInterface::getSize()
   */
  public static function updateSizes(array $itemIds = []) {
    if (empty($itemIds) === TRUE) {
      return;
    }

    foreach ($itemIds as $folderId) {
      $folder = self::load($folderId);

      // Silently skip invalid folders and items that aren't folders.
      //
      // This method may be called by a queue worker to update
      // folder sizes after some earlier change to a folder tree.
      // But in the interval, a folder may have been deleted.
      // The queue will still have the folder ID, but loading
      // the folder will get a NULL. This is not an error, just
      // an out of date request, so silently ignore NULLs.
      if ($folder !== NULL) {
        $folder->updateSize();
      }

      if ($folder->isFolderOrRootFolder() === TRUE) {
        $folder->updateSize();
      }
    }
  }

  /**
   * Enqueues a request to update folder sizes.
   *
   * The given array contains a list of folder entity IDs for folders
   * to be updated. Folder updating is recursive, so it is sufficient
   * to include only the top-level folder entity ID for each folder
   * tree to be updated.
   *
   * If the array is empty, no updates are done.  Invalid folder IDs
   * in the array are silently skipped.
   *
   * After the size is updated, each folder is saved.
   *
   * @param array $itemIds
   *   (optional) The folder entity IDs of folders whose sizes need to be
   *   updated. Defaults to an empty array.
   *
   * @see ::clearSize()
   * @see ::getSize()
   * @see ::setSize()
   * @see ::updateSize()
   * @see ::updateSizes()
   * @see \Drupal\foldershare\Constants::QUEUE_UPDATE
   * @see \Drupal\file\FileInterface::getSize()
   */
  public static function enqueueUpdateSizes(array $itemIds = []) {
    if (empty($itemIds) === TRUE) {
      return;
    }

    $queue = \Drupal::queue(Constants::QUEUE_UPDATE);
    if ($queue !== NULL) {
      // The queue exists (as it should). Queue the request.
      $queue->createQueue();
      $queue->createItem($itemIds);
    }
    else {
      // The queue is missing for some reason. Do the task
      // immediately. This runs the risk of a PHP timeout
      // and a slow response to the user.
      self::updateSizes($itemIds);
    }
  }

  /*---------------------------------------------------------------------
   *
   * Created date/time field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /*---------------------------------------------------------------------
   *
   * Owner field.
   *
   * Implements EntityOwnerInterface.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function changeOwnerId(int $newUid) {
    //
    // Change the owner on this item. Do not recurse to change
    // ownership of a folder's content.
    //
    // Get the current owner ID, which we'll need for updating usage tracking.
    $priorUid = $this->getOwnerId();

    // Change the ownership of this item, whether it is a file or folder.
    // Insure the new owner has the default access grants.
    $this->setOwnerId($newUid);
    $this->addDefaultAccessGrants();
    $this->save();

    // For files, images, and media, update the underlying entity as well.
    if ($this->isFile() === TRUE) {
      $file = $this->getFile();
      if ($file !== NULL) {
        $file->setOwnerId($newUid);
        $file->save();
      }
    }
    elseif ($this->isImage() === TRUE) {
      $file = $this->getImage();
      if ($file !== NULL) {
        $file->setOwnerId($newUid);
        $file->save();
      }
    }
    elseif ($this->isMedia() === TRUE) {
      $media = $this->getMedia();
      if ($media !== NULL) {
        $media->setOwnerId($newUid);
        $media->save();
      }
    }

    // Update usage.
    if ($this->isFolder() === TRUE) {
      FolderShareUsage::updateUsageFolders($priorUid, (-1));
      FolderShareUsage::updateUsageFolders($newUid, 1);
    }

    if ($this->isFile() === TRUE ||
        $this->isImage() === TRUE ||
        $this->isMedia() === TRUE) {
      FolderShareUsage::updateUsageFiles($priorUid, (-1));
      FolderShareUsage::updateUsageFiles($newUid, 1);
    }

    if ($this->isRootFolder() === TRUE) {
      FolderShareUsage::updateUsageRootFolders($priorUid, (-1));
      FolderShareUsage::updateUsageRootFolders($newUid, 1);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function changeAllOwnerId(int $uid) {
    //
    // Change the owner on this item, and all of its file and subfolder
    // content by recursing down through the folder tree.
    $usageChange = [];

    // If this is a file, image, or media, update it easily without
    // recursing.
    if ($this->isFile() === TRUE ||
        $this->isImage() === TRUE ||
        $this->isMedia() === TRUE) {
      $this->changeOwnerId($uid);
      return;
    }

    // For folders, update ownership recursively.
    $savedException = NULL;
    try {
      $this->changeAllOwnerIdInternal($uid, $usageChange);
    }
    catch (\Exception $e) {
      $savedException = $e;
    }

    // Update the tracking.
    foreach ($usageChange as $uid => $usage) {
      FolderShareUsage::updateUsage($uid, $usage);
    }

    if ($savedException !== NULL) {
      throw $savedException;
    }
  }

  /**
   * Implements a recursive ownership change on this folder.
   *
   * This method is private. It is used by changeAllOwnerId() to
   * recursively change ownership on files and folders in a folder tree.
   *
   * This method assumes that this item is a folder.
   *
   * <B>Usage tracking:</B>
   * Usage changes are tracked along the way, but not saved.
   * The caller should save the usage changes.
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
   * @param array $usageChange
   *   The usage array is updated during the change. Array keys are
   *   user IDs, and array values are associative arrays with keys
   *   for usage and values that indicate the positive or
   *   negative change caused by this operation.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Thrown if an access lock could not be acquired on this folder,
   *   or any of the child folders.
   *
   * @see ::changeOwnerId()
   * @see ::changeAllOwnerId()
   * @see ::changeAllOwnerIdByUser()
   * @see \Drupal\user\EntityOwnerInterface::getOwner()
   * @see \Drupal\user\EntityOwnerInterface::getOwnerId()
   * @see ::isAllOwned()
   * @see \Drupal\user\EntityOwnerInterface::setOwner()
   * @see \Drupal\user\EntityOwnerInterface::setOwnerId()
   */
  private function changeAllOwnerIdInternal(int $uid, array &$usageChange) {
    $usageChange[$uid] = [
      'nRootFolders' => 0,
      'nFolders'     => 0,
      'nFiles'       => 0,
      'nBytes'       => 0,
    ];

    //
    // Acquire lock on folder
    // ----------------------
    // Lock this folder.
    if ($this->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    //
    // Change ownership on this folder
    // -------------------------------
    // Change this folder first.
    $priorUid = $this->getOwnerId();
    if (isset($usageChange[$priorUid]) === FALSE) {
      $usageChange[$priorUid] = [
        'nRootFolders' => 0,
        'nFolders'     => 0,
        'nFiles'       => 0,
        'nBytes'       => 0,
      ];
    }

    $this->setOwnerId($uid);
    $this->addDefaultAccessGrants();
    $this->save();

    $usageChange[$priorUid]['nFolders']--;
    $usageChange[$uid]['nFolders']++;

    if ($this->isRootFolder() === TRUE) {
      $usageChange[$priorUid]['nRootFolders']--;
      $usageChange[$uid]['nRootFolders']++;
    }

    //
    // Change ownership on this folder's children
    // ------------------------------------------
    // Change all children. For children that are files, change them
    // directly. For folders, recurse to handle them and their children.
    //
    // If any child change fails, count the exception but don't save it.
    // The only exception that can be thrown is a LockException.
    $nExceptions = 0;
    $childIds = $this->getChildIds();

    foreach ($childIds as $id) {
      // Try to load the child.
      $child = self::load($id);
      if ($child === NULL) {
        continue;
      }

      // Change a file child.
      if ($child->isFile() === TRUE) {
        $child->setOwnerId($uid);
        $child->addDefaultAccessGrants();
        $child->save();

        $file = $child->getFile();
        if ($file !== NULL) {
          $file->setOwnerId($uid);
          $file->save();

          $bytes = $file->getSize();
          $usageChange[$priorUid]['nFiles']--;
          $usageChange[$uid]['nFiles']++;
          $usageChange[$priorUid]['nBytes'] -= $bytes;
          $usageChange[$uid]['nBytes'] += $bytes;
        }

        continue;
      }

      // Recurse to change a folder child.
      if ($child->isFolderOrRootFolder() === TRUE) {
        try {
          // Recurse into the child folder.  This will lock that folder,
          // and so forth. The function will update usage tracking
          // along the way.
          $child->changeAllOwnerIdInternal($uid, $usageChange);
        }
        catch (\Exception $e) {
          // Operation failed.  Note the exception.
          ++$nExceptions;
        }
      }
    }

    //
    // Release lock
    // ------------
    // Unlock this folder.
    $this->releaseLock();

    if ($nExceptions !== 0) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }
  }

  /**
   * Changes the ownership for all files and folders owned by a user.
   *
   * All files and folders are found that are currently owned by the
   * indicated user. The ownership of each of them is changed to
   * the new user.  The folders and files are saved.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is updated for the prior and current owners.
   *
   * @param int $currentUid
   *   The user ID of the owner of current files and folders that are
   *   to be changed.
   * @param int $newUid
   *   The user ID of the new owner of the files and folders.
   *
   * @see ::changeOwnerId()
   * @see ::changeAllOwnerId()
   * @see ::getOwner()
   * @see ::getOwnerId()
   * @see ::isAllOwned()
   * @see ::setOwner()
   * @see ::setOwnerId()
   */
  public static function changeAllOwnerIdByUser(int $currentUid, int $newUid) {
    //
    // Change items
    // ------------
    // Get a list of all IDs for items owned by the indicated user.
    // Loop through them, load each one, and set its ownership.
    $itemIds = self::getAllIds($currentUid);
    $nFiles = 0;
    $nFolders = 0;
    $nRootFolders = 0;
    $nBytes = 0;

    foreach ($itemIds as $itemId) {
      $item = self::load($itemId);
      if ($item === NULL) {
        continue;
      }

      if ($item->isFile() === TRUE) {
        $item->setOwnerId($newUid);
        $item->save();

        $file = $this->getFile();
        if ($file !== NULL) {
          $file->setOwnerId($newUid);
          $file->save();
          $nFiles++;
          $nBytes += $file->getSize();
        }
      }
      elseif ($item->isImage() === TRUE) {
        $item->setOwnerId($newUid);
        $item->save();

        // The image field stores a File entity reference.
        $file = $this->getImage();
        if ($file !== NULL) {
          $file->setOwnerId($newUid);
          $file->save();
          $nFiles++;
          $nBytes += $file->getSize();
        }
      }
      elseif ($item->isMedia() === TRUE) {
        $item->setOwnerId($newUid);
        $item->save();

        $media = $this->getMedia();
        if ($media !== NULL) {
          $media->setOwnerId($newUid);
          $media->save();
          $nFiles++;

          // Media entities do not have a stored size.
          // FolderShare typically uses Media entities to refer
          // to external media, so the size of that media is not
          // relevant here as we track storage space used.
        }
      }
      elseif ($item->isFolder() === TRUE) {
        $item->setOwnerId($newUid);
        $item->save();
        $nFolders++;
      }
      elseif ($item->isRootFolder() === TRUE) {
        $item->setOwnerId($newUid);
        $item->save();
        $nFolders++;
        $nRootFolders++;
      }
    }

    //
    // Update usage
    // ------------
    // Reduce usage tracking for the prior user and increase those
    // for the new user.
    $currentUidUsage = FolderShareUsage::getUsage($currentUid);
    $newUidUsage = FolderShareUsage::getUsage($newUid);

    $currentUidUsage['nRootFolders'] -= $nRootFolders;
    $currentUidUsage['nFolders'] -= $nFolders;
    $currentUidUsage['nFiles'] -= $nFiles;
    $currentUidUsage['nBytes'] -= $nBytes;

    $newUidUsage['nRootFolders'] += $nRootFolders;
    $newUidUsage['nFolders'] += $nFolders;
    $newUidUsage['nFiles'] += $nFiles;
    $newUidUsage['nBytes'] += $nBytes;

    FolderShareUsage::setUsage($currentUid, $currentUidUsage);
    FolderShareUsage::setUsage($newUid, $newUidUsage);
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return (int) $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function isAllOwned(int $uid) {
    // Check this item
    // ---------------
    // Does the user own this item?
    if ($uid !== $this->getOwnerId()) {
      return FALSE;
    }

    //
    // Check children
    // --------------
    // Does the user own all of the child items?
    if ($this->isFolderOrRootFolder() === TRUE) {
      foreach ($this->getChildren() as $item) {
        if ($item->isAllOwned($uid) === FALSE) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account = NULL) {
    if ($account === NULL) {
      $account = \Drupal::currentUser();
    }

    // The caller must call save() for this change to take effect.
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    // The caller must call save() for this change to take effect.
    $this->set('uid', $uid);
    return $this;
  }

  /*---------------------------------------------------------------------
   *
   * Access grant fields.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function addAccessGrant(int $uid, string $access) {
    // Ignore illegal UIDs.
    if ($uid < 0) {
      return;
    }

    // Ignore access grants on non-root folders.
    if ($this->isRootFolder() === FALSE) {
      return;
    }

    //
    // When adding access, if the user ID is already in the access list,
    // they are not added again.
    //
    // When adding to 'view' or 'author', insure the user is no longer on
    // the 'disabled' list.
    //
    // And when adding to the 'disabled' list, insure the user is no longer
    // on the 'view' or 'author' lists.
    //
    switch ($access) {
      case 'author':
        // Append to the list of author UIDs.
        if ($this->isAccessGranted($uid, 'author') === FALSE) {
          $this->grantauthoruids->appendItem(['target_id' => $uid]);
        }

        // An remove them from the disabled list, if on it.
        $this->deleteAccessGrant($uid, 'disabled');

        return;

      case 'view':
        // Append to the list of view UIDs.
        if ($this->isAccessGranted($uid, 'view') === FALSE) {
          $this->grantviewuids->appendItem(['target_id' => $uid]);
        }

        // An remove them from the disabled list, if on it.
        $this->deleteAccessGrant($uid, 'disabled');

        return;

      case 'disabled':
        // Append to the list of disabled UIDs.
        if ($this->isAccessGranted($uid, 'disabled') === FALSE) {
          $this->grantdisableduids->appendItem(['target_id' => $uid]);
        }

        // And remove them from the author and view lists, if they
        // are on them.
        $this->deleteAccessGrant($uid, 'view');
        $this->deleteAccessGrant($uid, 'author');

        return;
    }
  }

  /**
   * Adds default access grants for the folder owner.
   *
   * Default access grants are used to initialize the grant values
   * when a folder is first created. The default grant gives the
   * folder's owner view and author access.
   *
   * The caller must call save() for the change to take effect.
   *
   * @see ::getAccessGrantUserIds()
   * @see ::getAccessGrantAuthorUserIds()
   * @see ::getAccessGrantViewUserIds()
   * @see ::getAccessGrantDisabledUserIds()
   */
  private function addDefaultAccessGrants() {
    // Ignore access grants on non-root folders.
    if ($this->isRootFolder() === FALSE) {
      return;
    }

    $uid = $this->getOwnerId();
    $this->addAccessGrant($uid, 'author');
    $this->addAccessGrant($uid, 'view');
  }

  /**
   * {@inheritdoc}
   */
  public function clearAccessGrants(int $uid = -1) {
    // Access grants are only attached to root folders.  If this item
    // is not a root folder, go ahead and clear access grants anyway to
    // clean up the item in case something leaked through.
    //
    if ($uid < 0) {
      // Clear all of the grant UIDs.
      $this->grantauthoruids->setValue([], FALSE);
      $this->grantviewuids->setValue([], FALSE);
      $this->grantdisableduids->setValue([], FALSE);

      // Add back defaults. This is silently ignored if the
      // item is not a root folder.
      $this->addDefaultAccessGrants();
    }
    elseif ($this->getOwnerId() !== $uid) {
      // Delete the user's access.
      $this->deleteAccessGrant($uid, 'view');
      $this->deleteAccessGrant($uid, 'author');
      $this->deleteAccessGrant($uid, 'disabled');
    }
  }

  /**
   * Clears all access granted to the user from all items.
   *
   * For any items that includes an access grant to the user,
   * the access is deleted and the items saved.
   *
   * This function is primarily intended for administrative actions.
   *
   * @param int $uid
   *   (optional) The user ID of a user granted access.
   *
   * @see ::clearAccessGrants()
   * @see ::getAllRootFolderIds()
   */
  public static function clearAccessGrantsFromAll(int $uid) {
    // Ignore illegal UIDs.
    if ($uid < 0) {
      return;
    }

    foreach ($this->getAllRootFolderIds() as $id) {
      $folder = self::load($id);

      // If the root folder grants access to the user, clear that access
      // and save the root folder.
      if ($folder !== NULL &&
          $this->getOwnerId() !== $uid &&
          ($folder->isAccessGranted($uid, 'view') === TRUE ||
          $folder->isAccessGranted($uid, 'author') === TRUE ||
          $folder->isAccessGranted($uid, 'disabled') === TRUE)) {
        $folder->clearAccessGrants($uid);
        $folder->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAccessGrant(int $uid, string $access) {
    // Ignore illegal UIDs.
    if ($uid < 0) {
      return;
    }

    // Ignore access grants on non-root folders.
    if ($this->isRootFolder() === FALSE) {
      return;
    }

    // If the UID to delete is the root folder's owner, don't delete
    // them. The owner ALWAYS has access.
    $ownerId = $this->getOwnerId();
    if ($ownerId === $uid) {
      return;
    }

    // If this folder is not a root folder, go ahead and try to remove
    // the UID. There should be no access grants to remove the ID from,
    // but it doesn't hurt and could clean out anything that's leaked through.
    switch ($access) {
      case 'author':
        // Remove from the list of author UIDs.
        foreach ($this->grantauthoruids->getValue() as $index => $item) {
          if ((int) $item['target_id'] === $uid) {
            $this->grantauthoruids->removeItem($index);
            return;
          }
        }
        return;

      case 'view':
        // Remove from the list of view UIDs.
        foreach ($this->grantviewuids->getValue() as $index => $item) {
          if ((int) $item['target_id'] === $uid) {
            $this->grantviewuids->removeItem($index);
          }
        }
        return;

      case 'disabled':
        // Remove from the list of disabled UIDs.
        foreach ($this->grantdisableduids->getValue() as $index => $item) {
          if ((int) $item['target_id'] === $uid) {
            $this->grantdisableduids->removeItem($index);
          }
        }
        return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessGrantAuthorUserIds() {
    // Return nothing if this is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return [];
    }

    $items = $this->grantauthoruids->getValue();

    $uids = [];
    foreach ($items as $item) {
      $uids[] = (int) $item['target_id'];
    }

    return $uids;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessGrantViewUserIds() {
    // Return nothing if this is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return [];
    }

    $items = $this->grantviewuids->getValue();

    $uids = [];
    foreach ($items as $item) {
      $uids[] = (int) $item['target_id'];
    }

    return $uids;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessGrantDisabledUserIds() {
    // Return nothing if this is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return [];
    }

    $items = $this->grantdisableduids->getValue();

    $uids = [];
    foreach ($items as $item) {
      $uids[] = (int) $item['target_id'];
    }

    return $uids;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessGrants() {
    // Return nothing if this is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return [];
    }

    $authors = $this->getAccessGrantAuthorUserIDs();
    $viewers = $this->getAccessGrantViewUserIDs();
    $disabled = $this->getAccessGrantDisabledUserIDs();

    // Create a grant list with one entry per user ID.
    // The entry is an array with the UID as the key
    // and one of these possible entries:
    // - ['disabled'] = user is disabled.
    // - ['view'] = user only has view access.
    // - ['author'] = user only has author access (which is odd).
    // - ['author', 'view'] = user has view and author access.
    //
    // Start by adding all author grants.
    $grants = [];
    foreach ($authors as $uid) {
      $grants[$uid] = ['author'];
    }

    // Add all view grants.
    foreach ($viewers as $uid) {
      if (isset($grants[$uid]) === TRUE) {
        $grants[$uid][] = 'view';
      }
      else {
        $grants[$uid] = ['view'];
      }
    }

    // Fill in with all disabled grants.
    foreach ($disabled as $uid) {
      if (isset($grants[$uid]) === FALSE) {
        $grants[$uid] = ['disabled'];
      }
    }

    return $grants;
  }

  /**
   * {@inheritdoc}
   */
  public function isAccessGranted(int $uid, string $access) {
    // Return quickly with failure if the folder is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return FALSE;
    }

    switch ($access) {
      case 'author':
        // Check the list of author UIDs.
        foreach ($this->grantauthoruids->getValue() as $item) {
          if ((int) $item['target_id'] === $uid) {
            return TRUE;
          }
        }
        return FALSE;

      case 'view':
        // Check the list of view UIDs.
        foreach ($this->grantviewuids->getValue() as $item) {
          if ((int) $item['target_id'] === $uid) {
            return TRUE;
          }
        }
        return FALSE;

      case 'disabled':
        // Check the list of disabled UIDs.
        foreach ($this->grantdisableduids->getValue() as $item) {
          if ((int) $item['target_id'] === $uid) {
            return TRUE;
          }
        }
        return FALSE;
    }

    // Otherwise, the requested access is not recognized.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAccessPrivate() {
    // Return quickly with failure if the folder is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return FALSE;
    }

    // Access is private if there are no users other than the owner
    // in the list of author and view grant UIDs.
    $uid = $this->getOwnerId();

    foreach ($this->grantviewuids->getValue() as $item) {
      if ((int) $item['target_id'] !== $uid) {
        return FALSE;
      }
    }

    foreach ($this->grantauthoruids->getValue() as $item) {
      if ((int) $item['target_id'] !== $uid) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAccessPublic() {
    // Return quickly with failure if the folder is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return FALSE;
    }

    // Access is public if the anonymous user (UID = 0) is listed in
    // author and view grant UIDs.
    foreach ($this->grantviewuids->getValue() as $item) {
      if ((int) $item['target_id'] === 0) {
        return TRUE;
      }
    }

    foreach ($this->grantauthoruids->getValue() as $item) {
      if ((int) $item['target_id'] === 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAccessShared() {
    // Return quickly with failure if the folder is not a root folder.
    if ($this->isRootFolder() === FALSE) {
      return FALSE;
    }

    // Access is shared if anyone besides the owner is listed in the
    // author and view grant UIDs.
    return $this->isAccessPrivate() === FALSE;
  }

  /**
   * Returns a simplified statement about the entity's sharing status.
   *
   * @return string
   *   Returns one of 'public', 'private', or 'shared' based upon the
   *   current user, the entity's owner, the module's sharing status,
   *   and the entity's root folder sharing grants.
   */
  public function getSharingStatus() {
    // If sharing is disabled site wide, then everything is private.
    if (Settings::getSharingAllowed() === FALSE) {
      return 'private';
    }

    $root = $this->getRootFolder();
    if ($root === NULL) {
      // Malformed entity!
      return 'private';
    }

    $rootOwner = $root->getOwner();
    if ($rootOwner === NULL) {
      // Malformed entity!
      return 'private';
    }

    // If the entity is owned by anonymous, it is always public.
    if ($rootOwner->isAnonymous() === TRUE) {
      return 'public';
    }

    // If the item is not shared with anyone except the owner, it is private.
    if ($root->isAccessPrivate() === TRUE) {
      return 'private';
    }

    // If sharing with anonymous is allowed at the site, and the content is
    // shared with anonymous, then it is public.
    $anonAllowed = Settings::getSharingAllowedWithAnonymous();
    if ($anonAllowed === TRUE && $root->isAccessPublic() === TRUE) {
      return 'public';
    }

    // If the item was only shared with anonymous, but anonymous sharing
    // is currently disabled, then it reverts to private.
    if ($anonAllowed === FALSE) {
      $uids = ($root->getAccessGrantViewUserIds() +
        $root->getAccessGrantAuthorUserIds());
      $rootOwnerId = $rootOwner->id();
      $anonymousId = User::getAnonymousUser()->id();
      $isShared = FALSE;
      foreach ($uids as $uid) {
        if ($uid !== $rootOwnerId && $uid !== $anonymousId) {
          $isShared = TRUE;
          break;
        }
      }

      if ($isShared === FALSE) {
        return 'private';
      }
    }

    // If the item is owned by the current user, then it is shared with
    // others. Otherwise it is shared with this user.
    return 'shared';
  }

  /**
   * {@inheritdoc}
   */
  public function setAccessGrants(array $grants) {
    // Do not set access grants on non-root folders.
    if ($this->isRootFolder() === FALSE) {
      return FALSE;
    }

    // Use the given grant list with one entry per user ID.
    // The entry is an array with the UID as the key
    // and one of these possible entries:
    // - ['disabled'] = user is disabled.
    // - ['view'] = user only has view access.
    // - ['author'] = user only has author access (which is odd).
    // - ['author', 'view'] = user has view and author access.
    //
    // Initialize arrays. Always include the folder owner for
    // view and author access.
    $ownerId = $this->getOwnerId();

    $authors = [$ownerId];
    $viewers = [$ownerId];
    $disabled = [];

    // Split the array into separate lists for view, author, and
    // disabled. Along the way, remove redundant entries.
    foreach ($grants as $uid => $list) {
      $isAuthor   = in_array('author', $list);
      $isViewer   = in_array('view', $list);
      $isDisabled = in_array('disabled', $list);

      // If a user is disabled AND has view or author, then
      // ignore the disabled flag.
      if ($isDisabled === TRUE && ($isAuthor === TRUE || $isViewer === TRUE)) {
        $isDisabled = FALSE;
      }

      // If the user isn't already in the author, view, or disabled lists,
      // add them.
      if ($isAuthor === TRUE && in_array($uid, $authors, TRUE) === FALSE) {
        $authors[] = $uid;
      }

      if ($isViewer === TRUE && in_array($uid, $viewers, TRUE) === FALSE) {
        $viewers[] = $uid;
      }

      if ($isDisabled === TRUE && in_array($uid, $disabled, TRUE) === FALSE) {
        $disabled[] = $uid;
      }
    }

    // Sweep through the arrays and switch them to include 'target_id'.
    foreach ($authors as $index => $uid) {
      $authors[$index] = ['target_id' => $uid];
    }

    foreach ($viewers as $index => $uid) {
      $viewers[$index] = ['target_id' => $uid];
    }

    foreach ($disabled as $index => $uid) {
      $disabled[$index] = ['target_id' => $uid];
    }

    // Set the fields.
    $this->grantauthoruids->setValue($authors, FALSE);
    $this->grantviewuids->setValue($viewers, FALSE);
    $this->grantdisableduids->setValue($disabled, FALSE);
  }

  /**
   * Clears all shared folder access grants for a user's content.
   *
   * When a user ID is provided, the method queries all folders owned
   * by the user and clears their access grants to disable sharing.
   *
   * When a user ID is not provided, the method queries all folders
   * owned by ANYBODY and clears their access grants to disable sharing.
   * This is an extreme action that affects every user at the site, so
   * it should be done with extreme caution.
   *
   * @param int $uid
   *   (optional) The user ID of the user for whome to clear all shared
   *   access. If the value is a -1, shared access is cleared for all
   *   users.
   */
  public static function unshareAll($uid = -1) {
    // Shared access grants are only on root folders.  Get a list of
    // all root folders for the indicated user, or for all users.
    $rootFolderIds = self::getAllRootFolderIds($uid);

    // Loop through the folder IDs, load each one, and clear its
    // access controls.
    foreach ($rootFolderIds as $rootFolderId) {
      $rootFolder = self::load($rootFolderId);

      if ($rootFolder !== NULL) {
        $rootFolder->clearAccessGrants();
        $rootFolder->save();
      }
    }
  }

  /**
   * Removes the user from all shared access to folders.
   *
   * The user's shared access to folders they do not own is removed.
   *
   * @param int $uid
   *   The user ID of the user to remove from shared access to all
   *   folders.
   */
  public static function unshareFromAll($uid) {
    // Shared access grants are only on root folders.  Get a list of
    // all root folders.
    $rootFolderIds = self::getAllRootFolderIds();

    // Loop through them and clear the user from the folder's access
    // grants.
    foreach ($rootFolderIds as $rootFolderId) {
      $rootFolder = self::load($rootFolderId);

      if ($rootFolder !== NULL) {
        $grants = $rootFolder->getAccessGrants();
        if (isset($grants[$uid]) === TRUE) {
          unset($grants[$uid]);
          $rootFolder->setAccessGrants($grants);
          $rootFolder->save();
        }
      }
    }
  }

  /*---------------------------------------------------------------------
   *
   * Description field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $text = $this->description->getValue();
    if ($text === NULL) {
      return '';
    }

    return $text;
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription(string $text) {
    if (empty($text) === TRUE) {
      $this->description->setValue(NULL);
    }
    else {
      $this->description->setValue($text);
    }
  }

  /*---------------------------------------------------------------------
   *
   * Usage reporting.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns TRUE if there are files, images, or media managed by the module.
   *
   * @return bool
   *   TRUE if there are any files, and FALSE otherwise.
   *
   * @see ::getNumberOfBytes()
   * @see ::getNumberOfFiles()
   * @see ::getNumberOfFolders()
   * @see ::getNumberOfRootFolders()
   * @see ::hasFolders()
   */
  public static function hasFiles() {
    return (self::getNumberOfFiles() !== 0);
  }

  /**
   * Returns TRUE if there are folders managed by the module.
   *
   * @return bool
   *   TRUE if there are any folders, and FALSE otherwise.
   *
   * @see ::getNumberOfBytes()
   * @see ::getNumberOfFiles()
   * @see ::getNumberOfFolders()
   * @see ::getNumberOfRootFolders()
   * @see ::hasFiles()
   */
  public static function hasFolders() {
    return (self::getNumberOfFolders() !== 0) ||
      (self::getNumberOfRootFolders() !== 0);
  }

  /**
   * Returns the total number of bytes used by files managed by the module.
   *
   * The returned value only includes storage space used by files or images.
   * Media objects typically refer to external storage (such as via a URL),
   * so the storage space of that media is not included here.  Any storage
   * space required in the database for folder or file metadata is also
   * not included here.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return int
   *   The total number of bytes used for files.
   *
   * @see ::getNumberOfFiles()
   * @see ::getNumberOfFolders()
   * @see ::getNumberOfRootFolders()
   * @see ::hasFiles()
   * @see ::hasFolders()
   */
  public static function getNumberOfBytes() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return 0;
    }

    // Get all entries for files.
    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'size', 'size');
    $query->condition('kind', self::FILE_KIND, '=');
    $results = $query->execute()
      ->fetchAll();

    // Sum their size fields.
    $bytes = 0;
    foreach ($results as $result) {
      $bytes += $result->size;
    }

    return $bytes;
  }

  /**
   * Returns the total number of items managed by the module.
   *
   * This includes all files, images, media, folders, and root folders.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return int
   *   The total number of items.
   *
   * @see ::getAllIds()
   * @see ::getNumberOfBytes()
   * @see ::getNumberOfFiles()
   * @see ::getNumberOfFolders()
   * @see ::getNumberOfRootFolders()
   * @see ::hasFiles()
   * @see ::hasFolders()
   */
  public static function getNumberOfItems() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return 0;
    }

    // Get all entries and return their count.
    $connection = Database::getConnection();
    return (int) $connection->select(self::BASE_TABLE, 'fs')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the total number of files, images, and media managed by the module.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return int
   *   The total number of files.
   *
   * @see ::getAllIds()
   * @see ::getNumberOfBytes()
   * @see ::getNumberOfFolders()
   * @see ::getNumberOfRootFolders()
   * @see ::hasFiles()
   * @see ::hasFolders()
   */
  public static function getNumberOfFiles() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return 0;
    }

    // Get all file entries and return their count.
    $connection = Database::getConnection();
    return (int) $connection->select(self::BASE_TABLE, 'fs')
      ->condition('kind', self::FILE_KIND, '=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the total number of folders managed by the module.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return int
   *   The total number of folders.
   *
   * @see ::getAllIds()
   * @see ::getNumberOfBytes()
   * @see ::getNumberOfFiles()
   * @see ::getNumberOfRootFolders()
   * @see ::hasFiles()
   * @see ::hasFolders()
   */
  public static function getNumberOfFolders() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return 0;
    }

    // Get all folder entries and return their count.
    $connection = Database::getConnection();
    return (int) $connection->select(self::BASE_TABLE, 'fs')
      ->condition('kind', self::FOLDER_KIND, '=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the total number of root folders managed by the module.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return int
   *   The total number of root folders.
   *
   * @see ::getAllRootFolderIds()
   * @see ::getNumberOfBytes()
   * @see ::getNumberOfFiles()
   * @see ::getNumberOfFolders()
   * @see ::hasFiles()
   * @see ::hasFolders()
   */
  public static function getNumberOfRootFolders() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return 0;
    }

    // Get all root folder entries and return their count.
    $connection = Database::getConnection();
    return (int) $connection->select(self::BASE_TABLE, 'fs')
      ->condition('kind', self::ROOT_FOLDER_KIND, '=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /*---------------------------------------------------------------------
   *
   * All items.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns a list of all item IDs, or only those owned by a user.
   *
   * The method queries the database for all items (e.g. files, folders)
   * and returns an array of entity IDs, or all all IDs owned by a
   * specific user.
   *
   * @param int $uid
   *   (optional) The owner user ID used to restrict the returned list so that
   *   only folders owned by the user are returned.  Defaults to
   *   the -1 to indicate all folders owned by any user.
   *
   * @return int[]
   *   An unordered list of integer entity IDs for all folders,
   *   or all folders owned by the indicated user.
   *
   * @see ::getAllFolderUserIds()
   * @see ::getAllRootFolderIds()
   * @see ::getNumberOfFolders()
   */
  public static function getAllIds(int $uid = -1) {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return [];
    }

    // An entityQuery() always returns only the entity IDs of the
    // indicated entities.
    if ($uid === -1) {
      return \Drupal::entityQuery(self::BASE_TABLE)
        ->execute();
    }

    $results = \Drupal::entityQuery(self::BASE_TABLE)
      ->condition('uid', $uid, '=')
      ->execute();

    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result;
    }

    return $ids;
  }

  /**
   * Returns a list of file, image, & media IDs and their owner IDs.
   *
   * The method queries the database for all file, image, or media kinds
   * and returns an array where entity IDs as keys and user IDs as values.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return array
   *   An array with entity IDs as keys, and user IDs as values. Entity IDs
   *   are for FolderShare entities with file, image, or media kinds.
   *
   * @see ::getAllFileUserIdsAndSizes()
   * @see ::getAllIds()
   * @see ::getAllRootFolderIds()
   * @see ::getNumberOfFiles()
   */
  public static function getAllFileUserIds() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return [];
    }

    // Get all file entries and return their count.
    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->addField('fs', 'uid', 'uid');
    $group = $query->orConditionGroup()
      ->condition('kind', self::FILE_KIND, '=')
      ->condition('kind', self::IMAGE_KIND, '=')
      ->condition('kind', self::MEDIA_KIND, '=');
    $query = $query->condition($group);
    $results = $query->execute()
      ->fetchAll();

    $ids = [];
    foreach ($results as $result) {
      $ids[$result->id] = (int) $result->uid;
    }

    return $ids;
  }

  /**
   * Returns a list of file, image, & media IDs and their owner IDs and sizes.
   *
   * The method queries the database for all file, image, or media kinds and
   * returns an array where entity IDs as keys and values are arrays that
   * contain the user id and size as adjacent integers.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return array
   *   An array with entity IDs as keys, and user IDs as values. Entity IDs
   *   are for FolderShare entities with file, image, or media kinds.
   *
   * @see ::getAllFileUserIds()
   * @see ::getAllIds()
   * @see ::getAllRootFolderIds()
   * @see ::getNumberOfFiles()
   */
  public static function getAllFileUserIdsAndSizes() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return [];
    }

    // Get all file entries and return their count.
    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->addField('fs', 'uid', 'uid');
    $query->addField('fs', 'size', 'size');
    $group = $query->orConditionGroup()
      ->condition('kind', self::FILE_KIND, '=')
      ->condition('kind', self::IMAGE_KIND, '=')
      ->condition('kind', self::MEDIA_KIND, '=');
    $query = $query->condition($group);
    $results = $query->execute()
      ->fetchAll();

    $ids = [];
    foreach ($results as $result) {
      $ids[$result->id] = [
        (int) $result->uid,
        (int) $result->size,
      ];
    }

    return $ids;
  }

  /**
   * Returns a list of all folder IDs and their owner IDs.
   *
   * The method queries the database for all folders and returns
   * an array where folder IDs as keys and user IDs as values.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return array
   *   An array with folder IDs as keys, and user IDs as values.
   *
   * @see ::getAllFileUserIds()
   * @see ::getAllIds()
   * @see ::getAllRootFolderIds()
   * @see ::getNumberOfFolders()
   */
  public static function getAllFolderUserIds() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return [];
    }

    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->addField('fs', 'uid', 'uid');
    $query->condition('kind', self::FOLDER_KIND, '=');
    $results = $query->execute()
      ->fetchAll();

    $ids = [];
    foreach ($results as $result) {
      $ids[$result->id] = (int) $result->uid;
    }

    return $ids;
  }

  /*---------------------------------------------------------------------
   *
   * All root folders.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns a list of all root folders, or only those owned by a user.
   *
   * @param int $uid
   *   (optional, default = -1 = any) The user ID for the root folders owner.
   * @param string $name
   *   (optional, default = '' = any) The name for the root folders.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an associative array where keys are entity IDs and values
   *   are entities for root folders.
   *
   * @see ::getAllIds()
   * @see ::getAllRootFolderNames()
   * @see ::getAllRootFolderUserIds()
   * @see ::getNumberOfRootFolders()
   */
  public static function getAllRootFolders(int $uid = -1, string $name = '') {
    return FolderShare::loadMultiple(self::getAllRootFolderIds($uid, $name));
  }

  /**
   * Returns a list of all root folder IDs, or only those owned by a user.
   *
   * @param int $uid
   *   (optional, default = -1 = any) The user ID for the root folders owner.
   * @param string $name
   *   (optional, default = '' = any) The name for the root folders.
   *
   * @return int[]
   *   An unordered list of integer entity IDs for all root folders,
   *   or all root folders owned by the indicated user.
   *
   * @see ::getAllIds()
   * @see ::getAllRootFolderNames()
   * @see ::getAllRootFolderUserIds()
   * @see ::getNumberOfRootFolders()
   */
  public static function getAllRootFolderIds(int $uid = -1, string $name = '') {
    $query = \Drupal::entityQuery(self::BASE_TABLE)
      ->condition('kind', self::ROOT_FOLDER_KIND, '=');

    if ($uid !== -1) {
      $query->condition('uid', $uid, '=');
    }

    if (empty($name) === FALSE) {
      $query->condition('name', $name, '=');
    }

    $results = $query->execute();

    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result;
    }

    return $ids;
  }

  /**
   * Returns a list of all root folder names and IDs for a user or all users.
   *
   * If a positive user ID is given, root folder names for that user are
   * returned. Otherwise root folder names for all users are returned.
   *
   * The returned unoredered array has folder names as keys and folder IDs
   * as values.
   *
   * @param int $uid
   *   (optional) The user ID of the user for whome to get all root
   *   folder names. If the ID is -1, the root folder names for all users
   *   are returned.
   *
   * @return array
   *   An unordered list of root folder names and IDs.
   *
   * @see ::getAllIds()
   * @see ::getAllRootFolderIds()
   * @see ::getAllRootFolderUserIds()
   * @see ::getNumberOfRootFolders()
   */
  public static function getAllRootFolderNames(int $uid = -1) {
    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->addField('fs', 'uid', 'uid');
    $query->addField('fs', 'name', 'name');
    $query->condition('kind', self::ROOT_FOLDER_KIND, '=');

    if ($uid !== (-1)) {
      $query->condition('uid', $uid, '=');
    }

    $results = $query->execute();

    $names = [];
    foreach ($results as $result) {
      $names[$result->name] = (int) $result->id;
    }

    return $names;
  }

  /**
   * Returns a list of all root folder IDs and their owner IDs.
   *
   * The method queries the database for all root folders and returns
   * an array where folder IDs as keys and user IDs as values.
   *
   * This method is primarily used to generate usage reports.
   *
   * @return array
   *   An array with root folder IDs as keys, and user IDs as values.
   *
   * @see ::getAllIds()
   * @see ::getAllRootFolderIds()
   * @see ::getAllRootFolderNames()
   * @see ::getNumberOfRootFolders()
   */
  public static function getAllRootFolderUserIds() {
    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->addField('fs', 'uid', 'uid');
    $query->condition('kind', self::ROOT_FOLDER_KIND, '=');
    $results = $query->execute();

    $ids = [];
    foreach ($results as $result) {
      $ids[$result->id] = (int) $result->uid;
    }

    return $ids;
  }

  /**
   * Returns a list of all public root folders.
   *
   * A public root folder is one that is:
   * - Owned by the anonymous user, OR
   * - Grants access to the anonymous.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an associative array where keys are entity IDs and values
   *   are entities for root folders.
   *
   * @see ::getAllRootFolders()
   * @deprecated Bad design
   */
  public static function getAllPublicRootFolders() {
    $anonymousUid = (int) User::getAnonymousUser()->id();

    $rootFolders = [];
    foreach (FolderShare::getAllRootFolders() as &$folder) {
      if ($folder->getOwner() === $anonymousUid ||
          $folder->isAccessPublic() === TRUE) {
        $rootFolders[] = $folder;
      }
    }

    return $rootFolders;
  }

  /**
   * Returns a list of public root folders, constrained by user or name.
   *
   * A public root folder is one that is:
   * - Owned by the anonymous user, OR
   * - Grants access to anonymous.
   *
   * If a user ID is provided, the query constrains the returned list to
   * root folders owned by that user.
   *
   * If a name is provided, the query constrains the returned list to
   * root folders with that name.
   *
   * @param int $uid
   *   (optional, default = -1 = any) The user ID for the root folders owner.
   * @param string $name
   *   (optional, default = '' = any) The name for the root folders.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an associative array where keys are entity IDs and values
   *   are entities for root folders that match the user ID and name
   *   criteria, if given.
   *
   * @see ::getAllRootFolders()
   */
  public static function getPublicRootFolders(
    int $uid = (-1),
    string $name = '') {

    // Build the query, adding conditions for the user ID and
    // root folder name if provided.
    $query = \Drupal::entityQuery(self::BASE_TABLE)
      ->condition('kind', self::ROOT_FOLDER_KIND, '=');

    if (empty($name) === FALSE) {
      $query->condition('name', $name, '=');
    }

    if ($uid !== (-1)) {
      $query->condition('uid', $uid, '=');
    }

    $results = $query->execute();

    // Pull out the entity IDs.
    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result;
    }

    // Load them all.
    $entities = self::loadMultiple($ids);

    $anonymousUid = (int) User::getAnonymousUser()->id();

    // The list includes items with a specified name (maybe), and with
    // a specified owner (maybe), but we also might have a list with a
    // mix of owners. A public item is one that is:
    // - Owned by anonymous OR
    // - Grants access to anonymous.
    //
    // So we have to loop through things checking.
    $rootFolders = [];
    foreach ($entities as &$folder) {
      if ($folder->getOwner() === $anonymousUid ||
          $folder->isAccessPublic() === TRUE) {
        $rootFolders[] = $folder;
      }
    }

    return $rootFolders;
  }

  /**
   * Returns a list of all root folders shared with a specified user.
   *
   * A shared root folder is one that is:
   * - Not owned by the current user, AND
   * - Grants access to the current user.
   *
   * @param int $uid
   *   (optional, default = current user) The user ID of the user for whome
   *   to find shared folders.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an associative array where keys are entity IDs and values
   *   are entities for root folders.
   *
   * @see ::getAllRootFolders()
   * @deprecated Bad design
   */
  public static function getAllSharedRootFolders(int $uid = (-1)) {
    if ($uid === (-1)) {
      $uid = (int) Drupal::currentUser()->id();
    }

    $rootFolders = [];
    foreach (FolderShare::getAllRootFolders() as &$folder) {
      if ($folder->getOwnerId() !== $uid &&
          $folder->isAccessGranted($uid, 'view') === TRUE) {
        $rootFolders[] = $folder;
      }
    }

    return $rootFolders;
  }

  /**
   * Returns a list of shared root folders, constrained by user or name.
   *
   * A shared root folder is one that is:
   * - Not owned by the current user, AND
   * - Grants access to the current user.
   *
   * If a user ID is provided, the query constrains the returned list to
   * root folders owned by that user.
   *
   * If a name is provided, the query constrains the returned list to
   * root folders with that name.
   *
   * @param int $uid
   *   (optional, default = -1 = any) The user ID for the root folders owner.
   * @param string $name
   *   (optional, default = '' = any) The name for the root folders.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an associative array where keys are entity IDs and values
   *   are entities for root folders that match the user ID and name
   *   criteria, if given.
   *
   * @see ::getAllRootFolders()
   */
  public static function getSharedRootFolders(
    int $uid = (-1),
    string $name = '') {

    // Build the query, adding conditions for the user ID and
    // root folder name if provided.
    $query = \Drupal::entityQuery(self::BASE_TABLE)
      ->condition('kind', self::ROOT_FOLDER_KIND, '=');

    if (empty($name) === FALSE) {
      $query->condition('name', $name, '=');
    }

    if ($uid !== (-1)) {
      $query->condition('uid', $uid, '=');
    }

    $results = $query->execute();

    // Pull out the entity IDs.
    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result;
    }

    // Load them all.
    $entities = self::loadMultiple($ids);

    $currentUid = (int) \Drupal::currentUser()->id();

    // The list includes items with a specified name (maybe), and with
    // a specified owner (maybe), but we also might have a list with a
    // mix of owners. A shared item is one that is:
    // - Not owned by the current user, AND
    // - Grants access to the current user.
    //
    // So we have to loop through things checking.
    $rootFolders = [];
    foreach ($entities as &$folder) {
      if ($folder->getOwnerId() !== $currentUid &&
          $folder->isAccessGranted($currentUid, 'view') === TRUE) {
        $rootFolders[] = $folder;
      }
    }

    return $rootFolders;
  }

  /*---------------------------------------------------------------------
   *
   * Child items.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getChildIds() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return [];
    }

    if ($this->isFolderOrRootFolder() === FALSE) {
      return [];
    }

    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->condition('parentid', $this->id(), '=');
    $results = $query->execute()
      ->fetchAll();

    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result->id;
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildren() {
    return self::loadMultiple($this->getChildIds());
  }

  /**
   * {@inheritdoc}
   */
  public function getChildFileIds() {
    if (db_table_exists(self::BASE_TABLE) === FALSE) {
      return [];
    }

    if ($this->isFolderOrRootFolder() === FALSE) {
      return [];
    }

    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->condition('parentid', $this->id(), '=');
    $group = $query->orConditionGroup()
      ->condition('kind', self::FILE_KIND, '=')
      ->condition('kind', self::IMAGE_KIND, '=')
      ->condition('kind', self::MEDIA_KIND, '=');
    $query = $query->condition($group);
    $results = $query->execute()
      ->fetchAll();

    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result->id;
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildFiles() {
    return self::loadMultiple($this->getChildFileIds());
  }

  /**
   * Returns a list of folder IDs for folders in a folder.
   *
   * This function is equivalent to getChildFolderIds() on a folder
   * object, but it does not require a loaded folder object. Only
   * the folder ID is needed.
   *
   * @param int $folderId
   *   The folder to query.
   *
   * @return int[]
   *   A list of integer folder IDs for the Folder entity
   *   children of this folder.
   *
   * @see ::getChildFolderIds()
   */
  public static function getFolderChildFolderIds(int $folderId) {
    // Query all folders that list this folder as a parent.
    $results = \Drupal::entityQuery(self::ENTITY_TYPE_ID)
      ->condition('kind', self::FOLDER_KIND, '=')
      ->condition('parentid', $folderId, '=')
      ->execute();

    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result;
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildFolderIds() {
    // Query all folders that list this folder as a parent.
    $results = \Drupal::entityQuery(self::ENTITY_TYPE_ID)
      ->condition('kind', self::FOLDER_KIND, '=')
      ->condition('parentid', $this->id(), '=')
      ->execute();

    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result;
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildFolders() {
    return self::loadMultiple($this->getChildFolderIds());
  }

  /**
   * {@inheritdoc}
   */
  public function getChildNames() {
    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->addField('fs', 'name', 'name');
    $query->condition('parentid', $this->id(), '=');
    $results = $query->execute();

    // Process names into an array where keys are names and
    // values are IDs.
    $names = [];
    foreach ($results as $result) {
      $names[$result->name] = (int) $result->id;
    }

    return $names;
  }

  /*---------------------------------------------------------------------
   *
   * Parent field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getParentFolder() {
    $parentId = $this->getParentFolderId();
    if ($parentId < 0) {
      return NULL;
    }

    return self::load($parentId);
  }

  /**
   * {@inheritdoc}
   */
  public function getParentFolderId() {
    $parentField = $this->parentid->getValue();

    // If empty, there is no parent.
    if (empty($parentField) === TRUE) {
      return (-1);
    }

    $parentId = (int) $parentField[0]['target_id'];
    if ($parentId <= 0) {
      // This should not happen.
      return (-1);
    }

    return $parentId;
  }

  /*---------------------------------------------------------------------
   *
   * Ancestors and descendants.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getAncestorFolderIds() {
    $ancestors = [];
    $id = $this->getParentFolderId();

    while ($id !== (-1)) {
      // Add the parent ID.
      $ancestors[] = $id;

      // Load the parent.
      $folder = self::load($id);
      if ($folder === NULL) {
        break;
      }

      // Get its parent's ID.
      $id = $folder->getParentFolderId();
    }

    return $ancestors;
  }

  /**
   * {@inheritdoc}
   */
  public function getAncestorFolderNames() {
    $ancestors = [];
    $id = $this->getParentFolderId();

    while ($id !== (-1)) {
      // Load the parent.
      $folder = self::load($id);
      if ($folder === NULL) {
        break;
      }

      // Add the parent name.
      $ancestors[] = $folder->getName();

      // Get its parent's ID.
      $id = $folder->getParentFolderId();
    }

    return $ancestors;
  }

  /**
   * {@inheritdoc}
   */
  public function getAncestorFolders() {
    return self::loadMultiple(
      $this->getAncestorFolderIds());
  }

  /**
   * {@inheritdoc}
   */
  public function isAncestorOfFolderId(int $folderId) {
    if ($folderId < 0) {
      return FALSE;
    }

    $folder = self::load($folderId);
    if ($folder === NULL) {
      return FALSE;
    }

    // This folder is an ancestor of the given folder if that folder's
    // list of ancestor folder IDs contains this folder's ID.
    return in_array((int) $this->id(), $folder->getAncestorFolderIds());
  }

  /**
   * {@inheritdoc}
   */
  public function getDescendantFolderIds() {
    $descendants = [];
    $childIds = $this->getChildFolderIds();

    foreach ($childIds as $id) {
      // Load the child.
      $childFolder = self::load($id);
      if ($childFolder === NULL) {
        continue;
      }

      // Add the child folder and all its descendants.
      $descendants[] = $id;
      $descendants = array_merge(
        $descendants,
        $childFolder->getDescendantFolderIds());
    }

    return $descendants;
  }

  /**
   * {@inheritdoc}
   */
  public function isDescendantOfFolderId(int $folderId) {
    if ($folderId < 0) {
      return FALSE;
    }

    // This folder is a descendent if any of its ancestor folders
    // has an ID that matches the given folder ID.
    return in_array($folderId, $this->getAncestorFolderIds());
  }

  /*---------------------------------------------------------------------
   *
   * Root folder field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getRootFolder() {
    $rootId = $this->getRootFolderId();
    if ($rootId === (int) $this->id()) {
      return $this;
    }

    return self::load($rootId);
  }

  /**
   * {@inheritdoc}
   */
  public function getRootFolderId() {
    $rootField = $this->rootid->getValue();

    // If empty, this folder is the root so return its ID.
    if (empty($rootField) === TRUE) {
      return (int) $this->id();
    }

    // Otherwise return the referenced entity's ID.
    return (int) $rootField[0]['target_id'];
  }

  /**
   * Implements setting the root ID of this folder and all child folders.
   *
   * This method is private. It is used by moveToRoot(), moveToFolder(),
   * through recursion by this function.
   *
   * If one or more child folders cannot be modified, no change to
   * any of them is made and an exception is thrown.
   *
   * <B>Access control:</B>
   * The caller must already have insured valid access to this folder.
   *
   * <B>Locking:</B>
   * This function does not lock folders. If necessary, the caller MUST
   * MUST have locked the folder tree first.
   *
   * @param int $rootId
   *   The root ID for the new root ancestor of this folder and
   *   all of its descendant folders.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If content could not be modified.
   *
   * @see ::moveToRoot()
   * @see ::moveToFolder()
   * @see ::getRootFolderId()
   * @see ::isRootFolder()
   */
  private function setAllRootFolderIdInternal(int $rootId) {
    // Change root ID on this item
    // ---------------------------
    // If this item is a root folder, then set it's root to NULL.
    // Otherwise set this item's root ID.
    $previousRootId = $this->getRootFolderId();
    if ((int) $this->id() === $rootId) {
      $this->rootid->setValue(['target_id' => NULL]);
    }
    else {
      $this->rootid->setValue(['target_id' => $rootId]);
    }

    $this->save();

    //
    // Recurse through all children
    // ----------------------------
    // Change the root folder ID for all children.
    if ($this->isFolderOrRootFolder() === TRUE) {
      try {
        foreach ($this->getChildIds() as $id) {
          $child = self::load($id);
          $child->setAllRootFolderIdInternal($rootId);
        }
      }
      catch (\Exception $e) {
        // Unexpected exception.  The caller has already obtained
        // all needed folder locks, and this recursive function
        // does not throw exceptions. Something odd happened.
        //
        // Regardless, back out the change by reverting this
        // folder and all child folders back to the previous
        // root ID.
        try {
          $this->rootid->setValue(['target_id' => $previousRootId]);
          $this->save();
          $this->setAllRootFolderIdInternal($previousRootId);
        }
        catch (\Exception $ee) {
          // Double exception. The previous values cannot be restored.
          //
          // Nothing further can be done. The database must be
          // left corrupted.
          throw new ValidationException(t(self::EXCEPTION_ROOT_CORRUPTED));
        }

        throw $e;
      }
    }
  }

  /*---------------------------------------------------------------------
   *
   * Search.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the FolderShare ID wrapping the given File.
   *
   * The File's ID is looked up as a value in the 'file' or 'image' fields.
   *
   * @param \Drupal\file\FileInterface $file
   *   The File entity for which the FolderShare ID that wraps it
   *   is being found.
   *
   * @return int
   *   The FolderShare entity ID of entity that wraps the given File entity,
   *   or a -1 if not found.
   */
  public static function getFileParentFolderId(FileInterface $file) {
    if ($file === NULL) {
      return -1;
    }

    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'parentid', 'parentid');
    $group = $query->orConditionGroup()
      ->condition('file__target_id', $file->id(), '=')
      ->condition('image__target_id', $file->id(), '=');
    $query = $query->condition($group);
    $results = $query->execute()
      ->fetchAll();

    if (empty($results) === TRUE) {
      return -1;
    }

    return $results[0]->parentid;
  }

  /**
   * Returns the FolderShare ID wrapping the given Media.
   *
   * The Media's ID is looked up as a value in the 'media' field.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The Media entity for which the FolderShare ID that wraps it
   *   is being found.
   *
   * @return int
   *   The FolderShare entity ID of entity that wraps the given Media entity,
   *   or a -1 if not found.
   */
  public static function getMediaParentFolderId(MediaInterface $media) {
    if ($media === NULL) {
      return -1;
    }

    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'parentid', 'parentid');
    $query->condition('media__target_id', $media->id(), '=');
    $results = $query->execute()
      ->fetchAll();

    if (empty($results) === TRUE) {
      return -1;
    }

    return $results[0]->parentid;
  }

  /**
   * Returns the FolderShare ID for a named root folder.
   *
   * Root folders have unique names among root folders owned by the same
   * user. This search therefore requires that a user ID be provided to
   * disambiguate when multiple root folders by different users have the
   * same names.
   *
   * @param string $name
   *   The name of a root folder to find.
   * @param int $uid
   *   (optional, default = current user) The user ID of the owner of the
   *   root folder.
   *
   * @return int
   *   Returns the FolderShare entity ID of the root folder with the given
   *   name and user, or a -1 if not found.
   */
  public static function findRootFolderIdByName(string $name, int $uid = -1) {
    if (empty($name) === TRUE) {
      return -1;
    }

    if ($uid === (-1)) {
      $uid = \Drupal::currentUser()->id();
    }

    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->condition('kind', self::ROOT_FOLDER_KIND, '=');
    $query->condition('uid', $uid, '=');
    $query->condition('name', $name, '=');
    $results = $query->execute()
      ->fetchAll();

    if (empty($results) === TRUE) {
      return -1;
    }

    return $results[0]->id;
  }

  /**
   * Returns the FolderShare ID for a named file or subfolder.
   *
   * Files and subfolders have unique names within their parent folder.
   * This search therefore requires that a parent folder ID be provided to
   * disambiguate when multiple items in different folders have the same
   * names.
   *
   * @param string $name
   *   The name of a root folder to find.
   * @param int $parentId
   *   The parent folder ID.
   *
   * @return int
   *   Returns the FolderShare entity ID if the file or folder with the given
   *   name and parent folder, or a -1 if not found.
   */
  public static function findFileOrFolderIdByName(string $name, int $parentId) {
    if (empty($name) === TRUE) {
      return (-1);
    }

    if ($parentId === (-1)) {
      return (-1);
    }

    $connection = Database::getConnection();
    $query = $connection->select(self::BASE_TABLE, 'fs');
    $query->addField('fs', 'id', 'id');
    $query->condition('parentid', $parentId, '=');
    $query->condition('name', $name, '=');
    $results = $query->execute()
      ->fetchAll();

    if (empty($results) === TRUE) {
      return -1;
    }

    return $results[0]->id;
  }

  /*---------------------------------------------------------------------
   *
   * Paths.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getPath() {
    $ancestorNames = $this->getAncestorFolderNames();
    $path = '';
    foreach ($ancestorNames as &$name) {
      $path .= '/' . $name;
    }

    return $path . '/' . $this->getName();
  }

  /**
   * Parses a path into its components.
   *
   * Paths have the form:
   * - SCHEME://UID/PATH
   * - SCHEME://ACCOUNT/PATH
   *
   * Each component is semi-optional and has defaults:
   * - SCHEME://UID/ refers to the root folders of user UID.
   * - SCHEME://ACCOUNT/ refers to the root folders of user ACCOUNT.
   * - SCHEME:/PATH assumes the current user.
   * - SCHEME:/ refers to the root folders of the current user.
   * - //UID/PATH defaults to the "private" scheme of user UID.
   * - //ACCOUNT/PATH defaults to the "private" scheme of user ACCOUNT.
   * - /PATH defaults to the "private" scheme of the current user.
   * - / defaults to the "private" scheme of the current user.
   *
   * Malformed paths throw exceptions:
   * - SCHEME:///PATH.
   * - SCHEME:///.
   * - SCHEME://.
   * - SCHEME:.
   * - SCHEME.
   * - :///PATH.
   * - ://PATH.
   * - :/PATH.
   * - :///.
   * - ://
   * - :/.
   * - ///PATH.
   * - ///.
   * - //.
   *
   * The SCHEME selects a category of content from the current user's
   * point of view. The following values are supported:
   * - "private" = the user's own content.
   * - "public" = the site's public content.
   * - "shared" = the content currently shared with the user.
   *
   * The SCHEME is automatically converted to lower case. All other
   * parts of a path are case sensitive.
   *
   * If no SCHEME is provided, the default is "private".
   *
   * The UID and ACCOUNT indicate the owner of the content. The UID must
   * be numeric. It is not an error for the UID to be invalid - but no
   * content will be found if it is. The ACCOUNT must be valid is is looked
   * up to get the corresponding UID.
   *
   * If no UID or ACCOUNT is provided, the default is the current user.
   *
   * The PATH, starting with '/', gives a chain of folder names, starting
   * with a root folder and ending with a file or folder name. A minimal
   * path is '/' alone, which refers to the list of root folders in SCHEME.
   * For instance, "private:/" prefers to user's own list of root folders.
   *
   * The UID or ACCOUNT are needed to disambiguate among multiple root folders
   * available to the current user. Among a user's own root folders, this is
   * never a problem because root folder names must be unique for a user.
   * But when root folders are shared with another user or the public, the
   * set of shared or public root folders may include multiple root folders
   * from different users, combined into the same namespace. It becomes
   * possible for there to be two root folders named "ABC" in a user's
   * shared list of root folders. In this case, the UID or ACCOUNT of one
   * of the "ABC" root folders is needed to determine which one is desired.
   *
   * @param string $path
   *   The path in the form SCHEME://UID/PATH or SCHEME://ACCOUNT/PATH,
   *   where the SCHEME, UID, ACCOUNT, and PATH are each optional, but
   *   certain combinations that skip them yield a malformed syntax that
   *   causes an exception to be thrown.
   *
   * @return array
   *   Returns an associative array with keys 'scheme', 'uid', and 'path'
   *   that contain the parts of the path, or the defaults if a part was not
   *   provided. If the path includes an ACCOUNT, that ACCOUNT is looked up
   *   and the UID for the account returned in the array.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws an exception if the path is malformed:
   *   - The incoming path is empty.
   *   - The SCHEME does not have a known value.
   *   - A UID is provided, but it is invalid.
   *   - An ACCOUNT is provided, but it is invalid.
   *   - A UID or ACCOUNT is provided, but there is no path after it.
   *   - The path does not start with '/'.
   *
   * @internal
   * All text handling here must be multi-byte character safe.
   */
  public static function parseAndValidatePath(string $path) {
    //
    // Look for SCHEME
    // ---------------
    // A path may begin with a SCHEME and colon.
    if (empty($path) === TRUE) {
      throw new ValidationException(
        "Malformed file and folder path is empty.");
    }

    $parts = mb_split(':', $path, 2);
    $uid = NULL;
    if (count($parts) === 2) {
      // SCHEME found. Convert it to lower case and use the remainder
      // of the string as the path.
      $scheme = mb_convert_case($parts[0], MB_CASE_LOWER);
      $path = $parts[1];

      switch ($scheme) {
        case self::PRIVATE_SCHEME:
        case self::PUBLIC_SCHEME:
        case self::SHARED_SCHEME:
          break;

        default:
          throw new ValidationException(
            "Malformed path uses an unknown scheme name: \"$scheme\".");
      }
    }
    else {
      // No SCHEME found. Use default.
      $scheme = self::PRIVATE_SCHEME;
    }

    //
    // Look for UID or ACCOUNT
    // -----------------------
    // Following the SCHEME may be '//' and a user UID or ACCOUNT name.
    $slashslash = mb_substr($path, 0, 2);
    if ($slashslash === '//') {
      // UID or ACCOUNT found. Extract it up to the next '/'.
      $endOfUser = mb_stripos($path, '/', 2);
      if ($endOfUser === FALSE) {
        // No further '/' found after the UID. Malformed.
        throw new ValidationException(
          "Malformed path is missing a '/' after the user ID or account.");
      }

      // Determine if the value is a positive integer user ID or a
      // string account name.
      $userIdOrAccount = mb_substr($path, 2, ($endOfUser - 2));
      if (mb_ereg_match('^[0-9]?$', $userIdOrAccount) === TRUE) {
        // It's an integer user ID.
        $uid = (int) intval($userIdOrAccount);

        // Make sure the UID is valid.
        $user = User::load($uid);
        if ($user === NULL) {
          throw new ValidationException(
            "Malformed path refers to an invalid user ID: \"$uid\".");
        }
      }
      else {
        // It's a string account name. Look up the account.
        $user = user_load_by_name($userIdOrAccount);
        if ($user === FALSE) {
          // Unknown user!
          throw new ValidationException(
            "Malformed path refers to an unknown user: \"$userIdOrAccount\".");
        }

        $uid = (int) $user->id();
      }

      // The rest of incoming path is the actual folder path.
      $path = mb_substr($path, $endOfUser);
    }
    else {
      $user = \Drupal::currentUser();
      $uid  = (int) $user->id();
    }

    //
    // Look for PATH
    // -------------
    // The path is the remainder after the optional SCHEME, UID, or ACCOUNT.
    // Insure that it starts with a '/'.
    $slash = mb_substr($path, 0, 1);
    if ($slash !== '/') {
      throw new ValidationException(
        "Malformed path is missing a '/' before the top-level folder name.");
    }

    // Clean the path by removing any embedded '//' or a trailing '/'.
    $cleanedPath = mb_ereg_replace('%//%', '/', $path);
    if ($cleanedPath !== FALSE) {
      $path = $cleanedPath;
    }

    $cleanedPath = mb_ereg_replace('%/?$%', '', $path);
    if ($cleanedPath !== FALSE) {
      $path = $cleanedPath;
    }

    return [
      'scheme' => $scheme,
      'uid'    => $uid,
      'path'   => $path,
    ];
  }

  /**
   * Returns the entity ID identified by a path.
   *
   * Paths have the form:
   * - SCHEME://UID/PATH
   *
   * where SCHEME is the name of a root folder group (e.g. "public",
   * "private", or "shared"), UID is the user ID or account name, and
   * PATH is a folder path.
   *
   * The path is broken down into a list of ancestors and each ancestor
   * looked up, starting with the root folder. The indicated child is
   * found in that ancestor, and so forth down to the last entity on the
   * path. That entity is returned.
   *
   * @param string $path
   *   The path in the form SCHEME://UID/PATH, or one of its subforms.
   *
   * @return int
   *   Returns the entity ID of the last entity on the path.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws an exception if the path is malformed.
   * @throws \Drupal\foldershare\Entity\Exception\NotFoundException
   *   Throws an exception if the path contains a root folder, parent
   *   folder, or child that could not be found.
   *
   * @see self::parseAndValidatePath()
   *
   * @internal
   * All text handling here must be multi-byte character safe.
   */
  public static function getEntityIdForPath(string $path) {
    //
    // Parse path
    // ----------
    // Parse the path into SCHEME, UID, ACCOUNT, and PATH components, or their
    // defaults. Throw an exception if the path is malformed.
    $components = self::parseAndValidatePath($path);

    // Split the path into a chain of folder names, ending with a
    // file or folder name. Since the path must start with '/', the
    // first returned part will be empty. Ignore it.
    if ($components['path'] === '/') {
      throw new NotFoundException(t(
        "Missing top-level folder name after '/'."));
    }

    $parts = mb_split('/', $components['path']);
    array_shift($parts);


    //
    // Find root folder
    // ----------------
    // The root folder name is the first in the list. Use it and a possible
    // user ID to look up candidate root folders based upon the scheme.
    $rootName = array_shift($parts);

    // Get a list of matching root folders.
    $uid = $components['uid'];
    switch ($components['scheme']) {
      case self::PRIVATE_SCHEME:
        // If no UID is given, default to the current user.
        if ($uid === NULL || $uid === (-1)) {
          $uid = \Drupal::currentUser()->id();
        }

        $rootFolders = self::getAllRootFolders($uid, $rootName);
        break;

      case self::PUBLIC_SCHEME:
        // If no UID is given, default to (-1) to get public owned by anyone.
        if ($uid === NULL) {
          $uid = (-1);
        }

        $rootFolders = self::getPublicRootFolders($uid, $rootName);
        break;

      case self::SHARED_SCHEME:
        // If no UID is given, default to (-1) to get shared owned by anyone.
        if ($uid === NULL) {
          $uid = (-1);
        }

        $rootFolders = self::getSharedRootFolders($uid, $rootName);
        break;
    }

    if (empty($rootFolders) === TRUE) {
      throw new NotFoundException(t(
        'The folder could not be found: "@rootName".',
        [
          '@rootName' => $rootName,
        ]));
    }

    if (count($rootFolders) > 1) {
      throw new ValidationException(t(
        "Multiple folders match the request. Add a user ID or account to the path."));
    }

    $rootFolder = reset($rootFolders);
    $id = $rootFolder->id();

    //
    // Follow descendants
    // ------------------
    // The remaining parts of the path must be descendants of the root folder.
    // Follow the path downwards.
    foreach ($parts as $name) {
      $id = self::findFileOrFolderIdByName($name, $id);
      if ($id === (-1)) {
        throw new NotFoundException(t(
          'The file or folder could not be found: "@name".',
          [
            '@name' => $name,
          ]));
      }
    }

    return $id;
  }

  /**
   * Returns the entity identified by a path.
   *
   * Paths have the form:
   * - SCHEME://UID/PATH
   *
   * where SCHEME is the name of a root folder group (e.g. "public",
   * "private", or "shared"), UID is the user ID or account name, and
   * PATH is a folder path.
   *
   * @param string $path
   *   The path in the form SCHEME://UID/PATH, or one of its subforms.
   *
   * @return \Drupal\foldershare\Entity\FolderShareInterface
   *   Returns the last entity on the path.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Throws an exception if the path is malformed or no such entity
   *   could be found.
   *
   * @see self::parseAndValidatePath()
   * @see self::getEntityIdForPath()
   */
  public static function getEntityForPath(string $path) {
    return FolderShare::load(self::getEntityIdForPath($path));
  }

  /*---------------------------------------------------------------------
   *
   * Folder operations.
   *
   *---------------------------------------------------------------------*/

  /**
   * Creates a new root folder with the given name.
   *
   * If the name is empty, it is set to a default.
   *
   * The name is checked for uniqueness among all root folders owned by
   * the current user. If needed, a sequence number is appended before
   * the extension(s) to make the name unique (e.g. 'My new root 12').
   *
   * <B>Usage tracking:</B>
   * Usage tracking is updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * The root list is locked for exclusive editing access by this
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
   * @return \Drupal\foldershare\Entity\FolderShare
   *   Returns the new root folder.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Throws an exception if an access lock on the root list could
   *   not be acquired.
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If the name is already in use or is not legal.
   *
   * @see ::createFolder()
   */
  public static function createRootFolder(
    string $name = '',
    bool $allowRename = TRUE) {

    //
    // Validate
    // --------
    // If no name given, use a default. Otherwise insure the name is legal.
    if (empty($name) === TRUE) {
      $name = t(self::NEW_PREFIX) . t('folder');
    }
    elseif (FileUtilities::isNameLegal($name) === FALSE) {
      throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
    }

    //
    // Lock
    // ----
    // LOCK ROOT LIST.
    if (self::acquireRootLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ROOT_LIST_IN_USE));
    }

    //
    // Execute
    // -------
    // Create a name, if needed, then create the folder.
    $uid = \Drupal::currentUser()->id();
    $savedException = NULL;
    $folder = NULL;

    // Create the new root folder.
    try {
      if ($allowRename === TRUE) {
        // Insure name doesn't collide with existing root folders.
        //
        // Checking for name uniqueness can only be done safely while
        // the root list is locked so that no other process can add or
        // change a name.
        $name = self::createUniqueName(
          self::getAllRootFolderNames($uid),
          $name,
          '');
      }
      elseif (self::isRootNameUnique($name) === FALSE) {
        throw new ValidationException(t(self::EXCEPTION_NAME_IN_USE));
      }

      // Give the new root folder no parent or root.
      // - Empty parent ID.
      // - Empty root ID.
      // - Automatic id.
      // - Automatic uuid.
      // - Automatic creation date.
      // - Automatic changed date.
      // - Automatic langcode.
      // - Empty description.
      // - Empty size.
      // - Empty author grants.
      // - Empty view grants.
      // - Empty disabled grants.
      $folder = self::create([
        'name' => $name,
        'uid'  => $uid,
        'kind' => self::ROOT_FOLDER_KIND,
        'mime' => self::ROOT_FOLDER_MIME,
      ]);

      // Add default grants to a root folder.
      $folder->addDefaultAccessGrants();

      $folder->save();
    }
    catch (\Exception $e) {
      $savedException = $e;
    }

    //
    // Unlock
    // ------
    // UNLOCK ROOT LIST.
    self::releaseRootLock();

    if ($savedException !== NULL) {
      throw $savedException;
    }

    // Update usage tracking.
    FolderShareUsage::updateUsageRootFolders($uid, 1);

    return $folder;
  }

  /**
   * {@inheritdoc}
   */
  public function createFolder(
    string $name = '',
    bool $allowRename = TRUE) {

    //
    // Validate
    // --------
    // If no name given, use a default. Otherwise insure the name is legal.
    if (empty($name) === TRUE) {
      $name = t(self::NEW_PREFIX) . t('folder');
    }
    elseif (FileUtilities::isNameLegal($name) === FALSE) {
      throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
    }

    //
    // Lock
    // ----
    // LOCK PARENT FOLDER.
    if ($this->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    //
    // Execute
    // -------
    // Create a name, if needed, then create the folder.
    $uid = \Drupal::currentUser()->id();
    $savedException = NULL;
    $folder = NULL;

    // Create the new folder.
    try {
      if ($allowRename === TRUE) {
        // Insure name doesn't collide with existing files or folders.
        //
        // Checking for name uniqueness can only be done safely while
        // the parent folder is locked so that no other process can add or
        // change a name.
        $name = self::createUniqueName($this->getChildNames(), $name, '');
      }
      elseif ($this->isNameUnique($name) === FALSE) {
        throw new ValidationException(t(self::EXCEPTION_NAME_IN_USE));
      }

      // Create and set the parent ID to this folder,
      // and the root ID to this folder's root.
      // - Automatic id.
      // - Automatic uuid.
      // - Automatic creation date.
      // - Automatic changed date.
      // - Automatic langcode.
      // - Empty description.
      // - Empty size.
      // - Empty author grants.
      // - Empty view grants.
      // - Empty disabled grants.
      $folder = self::create([
        'name'     => $name,
        'uid'      => $uid,
        'kind'     => self::FOLDER_KIND,
        'mime'     => self::FOLDER_MIME,
        'parentid' => $this->id(),
        'rootid'   => $this->getRootFolderId(),
      ]);

      $folder->save();
    }
    catch (\Exception $e) {
      $savedException = $e;
    }

    //
    // Unlock
    // ------
    // UNLOCK PARENT FOLDER.
    $this->releaseLock();

    if ($savedException !== NULL) {
      throw $savedException;
    }

    // Update usage tracking.
    FolderShareUsage::updateUsageFolders($uid, 1);

    return $folder;
  }

  /**
   * {@inheritdoc}
   */
  public function copyToRoot(bool $adjustName = TRUE, string $newName = '') {
    //
    // Validate
    // --------
    // This entity must be a folder or root folder.
    if ($this->isFolderOrRootFolder() === FALSE) {
      throw new ValidationException(t(
        'Items that are not folders cannot be copied to the top-level folder list.'));
    }

    //
    // Check names
    // -----------
    // If there is a new name, make sure it is legal.
    if (empty($newName) === FALSE) {
      // Check that the new name is legal.
      if (FileUtilities::isNameLegal($newName) === FALSE) {
        throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
      }
    }

    //
    // Lock
    // ----
    // LOCK ROOT LIST.
    if (self::acquireRootLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ROOT_LIST_IN_USE));
    }

    //
    // Execute
    // -------
    // Copy this folder. The following variants are handled:
    //
    // - If $newName is not empty, then the copied item will be renamed
    //   to have the new name. Name collisions are handled based on that
    //   new name.
    //
    // - If $newName is empty, then the copy will have the same name as the
    //   original.
    //
    // - If $adjustName is TRUE, then name collisions are handled automatically
    //   by adding a number to the end of the name.
    //
    // - If $adjustName is FALSE, then name collisions cause an exception to
    //   thrown.
    $uid = \Drupal::currentUser()->id();
    $savedException = NULL;
    $folder = NULL;
    $usageChange = [
      'nRootFolders' => 0,
      'nFolders'     => 0,
      'nFiles'       => 0,
      'nBytes'       => 0,
    ];

    // Copy the folder, renaming it as needed.
    try {
      $folder = $this->copyToFolderInternal(NULL, $adjustName, $newName, $usageChange);
    }
    catch (\Exception $e) {
      $savedException = $e;
    }

    // UNLOCK ROOT LIST.
    self::releaseRootLock();

    // Update usage tracking.
    FolderShareUsage::updateUsage($uid, $usageChange);

    if ($savedException !== NULL) {
      throw $savedException;
    }

    return $folder;
  }

  /**
   * {@inheritdoc}
   */
  public function copyToFolder(
    FolderShareInterface $parent = NULL,
    bool $adjustName = TRUE,
    string $newName = '') {

    //
    // Validate
    // --------
    // If there is no destination folder, then copy into a new root folder.
    if ($parent === NULL) {
      $this->copyToRoot();
      return NULL;
    }

    // Verify that the copy destination is this folder.
    if ((int) $parent->id() === (int) $this->id()) {
      throw new ValidationException(t(self::EXCEPTION_INVALID_COPY));
    }

    // Verify that the copy destination is not deeper down in this folder.
    //
    // Note that this check must be done BEFORE a deep lock because
    // if a parent is a descendant, then the locks below would try
    // to double-lock the same parent folder, and we'd get an error.
    if ($parent->isDescendantOfFolderId($this->id()) === TRUE) {
      throw new ValidationException(t(self::EXCEPTION_INVALID_COPY));
    }

    //
    // Check names
    // -----------
    // If there is a new name, make sure it is legal.
    if (empty($newName) === FALSE) {
      // Check that the new name is legal.
      if (FileUtilities::isNameLegal($newName) === FALSE) {
        throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
      }

      // If this item is a file or image, verify that the name meets any
      // file name extension restrictions.
      if ($this->isFile() === TRUE || $this->isImage() === TRUE) {
        $extensionsString = self::getFileAllowedExtensions();
        if (empty($extensionsString) === FALSE) {
          $extensions = mb_split(' ', $extensionsString);
          if (FileUtilities::isNameExtensionAllowed($newName, $extensions) === FALSE) {
            throw new ValidationException(t(self::EXCEPTION_NAME_EXT_NOT_ALLOWED));
          }
        }
      }
    }

    //
    // Lock
    // ----
    // LOCK PARENT FOLDER.
    if ($parent->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_DST_FOLDER_IN_USE));
    }

    //
    // Execute
    // -------
    // Copy this folder. The following variants are handled:
    //
    // - If $newName is not empty, then the copied item will be renamed
    //   to have the new name. Name collisions are handled based on that
    //   new name.
    //
    // - If $newName is empty, then the copy will have the same name as the
    //   original.
    //
    // - If $adjustName is TRUE, then name collisions are handled automatically
    //   by adding a number to the end of the name.
    //
    // - If $adjustName is FALSE, then name collisions cause an exception to
    //   thrown.
    $uid = \Drupal::currentUser()->id();
    $savedException = NULL;
    $copy = NULL;
    $usageChange = [
      'nRootFolders' => 0,
      'nFolders'     => 0,
      'nFiles'       => 0,
      'nBytes'       => 0,
    ];

    // Copy the item, renaming it if needed.
    try {
      $copy = $this->copyToFolderInternal($parent, $adjustName, $newName, $usageChange);
    }
    catch (\Exception $e) {
      $savedException = $e;
    }

    //
    // Update sizes
    // ------------
    // The parent folder has now gained a new child item or folder tree, so
    // the parent's size is no longer correct. Clear it.
    $parent->clearSize();
    $parent->save();

    // If no errors occurred while doing the copy, then the new copy
    // has a non-empty size that includes the size of everything
    // copied. Updating the parent is then quick and can be done immediately.
    //
    // But if anything went wrong anywhere down through the copy folder
    // tree, then the copied folder will have an empty size and we need to
    // queue a size update task, which might take awhile depending upon
    // the depth of the tree and the number of files and folders.
    $queueUpdate = TRUE;
    if ($copy !== NULL && $copy->getSize() !== -1) {
      // We have a copy folder object, which means no exceptions were
      // thrown and the copy must have completed properly.  Additionally,
      // the copy folder has a valid size. So updating the parent is
      // fast. Do it now.
      self::updateSizes([(int) $parent->id()]);
      $queueUpdate = FALSE;
    }

    //
    // Unlock
    // ------
    // UNLOCK PARENT FOLDER.
    $parent->releaseLock();

    // Once all locks are released, we can queue an update to folder
    // sizes, if needed.
    if ($queueUpdate === TRUE) {
      self::enqueueUpdateSizes([(int) $parent->id()]);
    }

    // Update usage tracking.
    FolderShareUsage::updateUsage($uid, $usageChange);

    if ($savedException !== NULL) {
      throw $savedException;
    }

    return $copy;
  }

  /**
   * Implements copying of this item or folder tree into a new folder tree.
   *
   * This method is private. It used by copyToRoot(), copyToFolder(),
   * and this function during a recursive copy.
   *
   * If one or more child files or folders cannot be copied, the
   * rest are copied and an exception is thrown.
   *
   * The copied files and folders are owned by the current user.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is NOT updated directly, but the provided $usageChange
   * array is updated to record the folders, files, and storage space
   * used for the new copy items. The caller is responsible for using
   * this to update usage tracking for the current user.
   *
   * <B>Locking:</B>
   * This folder and all subfolders are locked for exclusive editing
   * access by this function for the duration of the copy. The caller
   * MUST have locked the destination folder or root list before calling
   * this function.
   *
   * @param \Drupal\foldershare\Entity\FolderShare $dstParent
   *   (optional, default = NULL = copy to the root list) The destination
   *   parent for this folder. When NULL, the copy is added to the root list.
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
   * @param array $usageChange
   *   (optional, default = NULL = no usage update) The usage array is
   *   updated during the change. The associative array
   *   has keys for for usage for the current user, and values that
   *   indicate the positive change caused by this operation.
   *
   * @return \Drupal\foldershare\Entity\FolderShare
   *   Returns the new folder created as a copy of this folder.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   If this folder, or one or more of its child files or folders,
   *   cannot be modified, or if there is a name collision and $adjustName
   *   is FALSE.
   *
   * @see ::copyToRoot()
   * @see ::copyToFolder()
   */
  private function copyToFolderInternal(
    FolderShare $dstParent = NULL,
    bool $adjustName = TRUE,
    string $newName = '',
    array &$usageChange = NULL) {
    //
    // Validate
    // --------
    // When this item is a folder or root folder tree, this method
    // recurses to copy the folder tree into a new folder tree. Locks
    // are held during recursion downward, and released on the way up.
    //
    // When this item is a file, this method doesn't need to recurse.
    // The entity is copied, along with copying the underlying File entity.
    //
    // The destination lock is done by the caller, which is this
    // function during recursion.
    //
    // The caller should already have insured we aren't being asked to
    // copy a file into the root list, but check again anyway.
    if ($this->isFolderOrRootFolder() === FALSE && $dstParent === NULL) {
      throw new ValidationException(t(
        'Items that are not folders cannot be copied to the top-level folder list.'));
    }

    //
    // Lock
    // ----
    // LOCK THIS FOLDER.
    if ($this->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_FOLDER_TREE_IN_USE));
    }

    // Setup.
    $uid = \Drupal::currentUser()->id();

    //
    // Check names
    // -----------
    // Check that the proposed name for the copy is unique within the
    // destination. If it is not, and $adjustName is TRUE, then adjust
    // the name to make it unique.
    if (empty($newName) === TRUE) {
      $copyName = $this->getName();
    }
    else {
      $copyName = $newName;
    }

    // Adjust the name, if needed.
    if ($dstParent === NULL) {
      $avoidNames = self::getAllRootFolderNames($uid);
    }
    else {
      $avoidNames = $dstParent->getChildNames();
    }

    if ($adjustName === TRUE) {
      $copyName = self::createUniqueName($avoidNames, $copyName, ' copy');
    }
    elseif (isset($avoidNames[$copyName]) === TRUE) {
      // UNLOCK THIS FOLDER.
      $this->releaseLock();
      throw new ValidationException(
        t(self::EXCEPTION_COPY_NAME_IN_USE));
    }

    //
    // Execute
    // -------
    // Create a new item in the destination parent, if any.
    // If this item is a folder, create a folder. If this item is
    // something else, create that.
    if ($dstParent === NULL) {
      // No destination so new item has no parent. This puts the copy into
      // a list of root folders, so this item must be a folder or root folder
      // to begin with. For anything else, throw an exception.
      if ($this->isFolderOrRootFolder() === FALSE) {
        throw new ValidationException(
          t(self::EXCEPTION_INVALID_NONFOLDER_ROOT_COPY));
      }

      $k = $this->getKind();
      if ($k === self::FOLDER_KIND) {
        // Copying a folder. Make it a root folder now since there is no parent.
        $k = self::ROOT_FOLDER_KIND;
      }

      // Duplicate this original entity.
      $copy = parent::createDuplicate();

      // Duplication copies all of the original's values, except the ID
      // and UUID. This will get us the right owner UID, and mime,
      // but we still have to set the proper name, kind, parent ID, and
      // root ID.
      $copy->set('name', $copyName);
      $copy->set('kind', $k);
      $copy->set('parentid', NULL);
      $copy->set('rootid', NULL);

      // Add default access grants to a root folder.
      $copy->addDefaultAccessGrants();
    }
    elseif ($this->isFolderOrRootFolder() === TRUE) {
      // Copy folder to destination folder.
      $k = $this->getKind();
      if ($k === self::ROOT_FOLDER_KIND) {
        // Copying a root folder. Make it a subfolder now since it now
        // has a parent.
        $k = self::FOLDER_KIND;
      }

      // Duplicate this original entity.
      $copy = parent::createDuplicate();

      // Duplication copies all of the original's values, except the ID
      // and UUID. This will get us the right owner UID, and mime,
      // but we still have to set the proper name, kind, parent ID, and
      // root ID.
      $copy->set('name', $copyName);
      $copy->set('kind', $k);
      $copy->set('parentid', $dstParent->id());
      $copy->set('rootid', $dstParent->getRootFolderId());
    }
    elseif ($this->isFile() === TRUE || $this->isImage() === TRUE) {
      // Create new file or image in destination.
      //
      // First copy the underling File object.
      if ($this->isFile() === TRUE) {
        $file = $this->getFile();
      }
      else {
        $file = $this->getImage();
      }

      if ($file === NULL) {
        // We can't find the file!
        //
        // UNLOCK THIS FOLDER.
        $this->releaseLock();
        throw new ValidationException(t(self::EXCEPTION_INCOMPLETE_COPY));
      }

      try {
        $newFile = self::duplicateFileEntityInternal($file);
      }
      catch (\Exception $e) {
        // We can't copy the file!
        //
        // UNLOCK THIS FOLDER.
        $this->releaseLock();
        throw $e;
      }

      // The duplicated File object has the same public filename as the
      // original, even though the stored filename is new and unique.
      // But if the FolderShare entity that wraps this File object has
      // a new name to avoid name collisions, then set the File object's
      // public filename to be that new name. This keeps the File object
      // and FolderShare entity storing the same names.
      $newFile->setFilename($copyName);
      $newFile->setFileUri(FileUtilities::getModuleDataFileUri($newFile));
      $newFile->save();

      // Duplicate this original entity.
      $copy = parent::createDuplicate();

      // Duplication copies all of the original's values, except the ID
      // and UUID. This will get us the right owner UID, kind, and mime,
      // but we still have to set the proper name, file ID, image ID,
      // parent ID, and root ID.
      $copy->set('name', $copyName);
      $copy->set('parentid', $dstParent->id());
      $copy->set('rootid', $dstParent->getRootFolderId());
      if ($this->isFile() === TRUE) {
        $copy->set('file', $newFile->id());
      }
      else {
        $copy->set('image', $newFile->id());
      }
    }
    elseif ($this->isMedia() === TRUE) {
      // Create new media in destination.
      //
      // First copy the underling Media object.
      $media = $this->getMedia();

      if ($media === NULL) {
        // We can't find the media!
        //
        // UNLOCK THIS FOLDER.
        $this->releaseLock();
        throw new ValidationException(t(self::EXCEPTION_INCOMPLETE_COPY));
      }

      try {
        $newMedia = $media->createDuplicate();
      }
      catch (\Exception $e) {
        // We can't copy the media!
        //
        // UNLOCK THIS FOLDER.
        $this->releaseLock();
        throw $e;
      }

      // The duplicated Media object has the same public name as the
      // original.  But if the FolderShare entity that wraps this Media
      // object has a new name to avoid name collisions, then set the
      // Media object's public name to be that new name. This keeps the
      // Media object and FolderShare entity storing the same names.
      $newMedia->setName($copyName);
      $newMedia->save();

      // Duplicate this original entity.
      $copy = parent::createDuplicate();

      // Duplication copies all of the original's values, except the ID
      // and UUID. This will get us the right owner UID, kind, and mime,
      // but we still have to set the proper name, media ID, parent ID,
      // and root ID.
      $copy->set('name', $copyName);
      $copy->set('parentid', $dstParent->id());
      $copy->set('rootid', $dstParent->getRootFolderId());
      $copy->set('media', $newMedia->id());
    }

    $copy->save();

    //
    // Update usage
    // ------------
    // Track usage tracking change.
    if ($this->isFolderOrRootFolder() === TRUE) {
      $usageChange['nFolders']++;
    }
    else {
      $usageChange['nFiles']++;
    }

    if ($dstParent === NULL) {
      $usageChange['nRootFolders']++;
    }

    //
    // Unlock
    // ------
    // If the item we just copied is not a folder (e.g. a file), then
    // we are done.
    if ($this->isFolderOrRootFolder() === FALSE) {
      // UNLOCK THIS FOLDER.
      $this->releaseLock();
      $copy->setSize($this->getSize());
      $copy->save();
      return $copy;
    }

    //
    // Lock
    // ----
    // LOCK COPY FOLDER.
    //
    // We are about to start adding to the copy folder, so lock it
    // so nobody can change it while we work.
    $nExceptions = 0;
    if ($copy->acquireLock() === FALSE) {
      // Something locked the empty copy folder so fast
      // we didn't get a chance to lock it first.  We can't
      // finish the copy.  Release our locks and throw an exception.
      //
      // UNLOCK COPY FOLDER.
      $this->releaseLock();
      throw new ValidationException(t(self::EXCEPTION_INCOMPLETE_COPY));
    }

    // Copy children.
    foreach ($this->getChildren() as $child) {
      try {
        // Recurse. Don't worry about creating unique names any more.
        // Continue to track usage changes.
        $child->copyToFolderInternal($copy, FALSE, '', $usageChange);
      }
      catch (\Exception $e) {
        // On any error, note the exception and keep going.
        ++$nExceptions;
      }
    }

    //
    // Update folder size
    // ------------------
    // Above, the copy's size was initialized to empty. During
    // the child file and folder copy, if no exceptions occur then we
    // know everything in copy has been copied and we can copy
    // the size of the original folder. Later, during folder size updates
    // this will save having to run through the whole folder tree and
    // recompute sizes.
    if ($nExceptions === 0) {
      $copy->setSize($this->getSize());
      $copy->save();
    }

    //
    // Unlock
    // ------
    // UNLOCK COPY FOLDER.
    // UNLOCK THIS FOLDER.
    $copy->releaseLock();
    $this->releaseLock();

    if ($nExceptions !== 0) {
      throw new ValidationException(t(self::EXCEPTION_INCOMPLETE_COPY));
    }

    return $copy;
  }

  /**
   * Deletes this item and all of its child files and folders.
   *
   * Implements \Drupal\Core\Entity\EntityInterface::delete.
   * Overrides \Drupal\Core\Entity\Entity::delete.
   *
   * If one or more child files or folders cannot be deleted, the
   * rest are deleted but this folder is left in place and an exception
   * is thrown.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder and all subfolders are locked for exclusive editing
   * access by this function for the duration of the delete.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   *
   * @see ::deleteAll()
   */
  public function delete() {
    //
    // Validate
    // --------
    // Only delete the item if it has been fully created (i.e. it is not
    // marked as "new"). New items don't have an ID yet, and therefore
    // can't have any subfolders or files yet, so really there's nothing
    // to delete.
    if ($this->isNew() === TRUE) {
      return;
    }

    //
    // Execute
    // -------
    // Do the delete, then update usage tracking.
    $uid = \Drupal::currentUser()->id();
    $savedException = NULL;
    $usageChange = [
      'nRootFolders' => 0,
      'nFolders'     => 0,
      'nFiles'       => 0,
      'nBytes'       => 0,
    ];

    // Delete.
    try {
      $this->deleteInternal(FALSE, $usageChange);
    }
    catch (\Exception $e) {
      $savedException = $e;
    }

    // Update usage tracking.
    FolderShareUsage::updateUsage($uid, $usageChange);

    if ($savedException !== NULL) {
      throw $savedException;
    }
  }

  /**
   * Implements deletion of this item and all of its child files and folders.
   *
   * This method is private. It is used by delete(), deleteAll(),
   * and this function during a recursive delete.
   *
   * If one or more child files or folders cannot be deleted, the
   * rest are deleted but this folder is left in place and an exception
   * is thrown.
   *
   * <B>Usage tracking:</B>
   * If the $fast parameter is FALSE, the provided $usageChange array is
   * updated to record the folders, files, and storage space
   * used for the new copy items. The caller is responsible for using
   * this to update usage for the current user.
   *
   * <B>Locking:</B>
   * If the $fast parameter is FALSE, this folder and all subfolders are
   * locked for exclusive editing access by this function for the
   * duration of the delete.
   *
   * @param bool $fast
   *   When TRUE, the function uses shortcuts to go faster by skipping all
   *   locking and usage updates. This is strictly for use during
   *   administrative actions that delete all content.
   * @param array $usageChange
   *   The usage array is updated during the change. The associative array
   *   has keys for for usage for the current user, and values that
   *   indicate the positive change caused by this operation.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   If an access lock could not be acquired.
   *
   * @see ::delete()
   * @see ::deleteAll()
   */
  private function deleteInternal(bool $fast, array &$usageChange) {
    //
    // Lock
    // ----
    // This is a recursing function that deletes a folder tree.
    // Locks are held during recursion deeper, and released on
    // the way up.
    //
    // LOCK THIS FOLDER.
    if ($fast === FALSE && $this->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    //
    // Execute
    // -------
    // This entity may be a data file, an image, a media object, or a folder.
    // Handle each of these.
    $nExceptions = 0;
    if ($this->isFile() === TRUE) {
      // This entity wraps an underlying File object.
      $file = $this->getFile();
      if ($fast === FALSE) {
        $usageChange['nBytes'] -= $this->getSize();
        $usageChange['nFiles']--;
      }

      // Clear the 'file' field and save. The save causes the File module
      // to note that the file field has changed and it decrements the
      // reference counts for the file. Later, a CRON job will delete the
      // actual file on disk.
      $this->file->setValue(['target_id' => NULL]);
      $this->save();

      // However, CRON-based deletion is not always relyable. So explicitly
      // delete the file as well.
      if ($file !== NULL) {
        $file->delete();
      }
    }
    elseif ($this->isImage() === TRUE) {
      // This entity wraps an underlying File object, treated as an image.
      $file = $this->getImage();
      if ($fast === FALSE) {
        $usageChange['nBytes'] -= $this->getSize();
        $usageChange['nFiles']--;
      }

      // Clear the 'image' field and save. The save causes the File module
      // to note that the file field has changed and it decrements the
      // reference counts for the file. Later, a CRON job will delete the
      // actual file on disk.
      $this->image->setValue(['target_id' => NULL]);
      $this->save();

      // However, CRON-based deletion is not always reliable. So explicitly
      // delete the file as well.
      if ($file !== NULL) {
        $file->delete();
      }
    }
    elseif ($this->isMedia() === TRUE) {
      // This entity wraps an underlying Media object.
      $media = $this->getMedia();
      if ($fast === FALSE) {
        $usageChange['nBytes'] -= $this->getSize();
        $usageChange['nFiles']--;
      }

      // Clear the 'media' field and save.
      $this->media->setValue(['target_id' => NULL]);
      $this->save();

      // And delete the media.
      if ($media !== NULL) {
        $media->delete();
      }
    }
    else {
      // This entity does not wrap another entity. It is a folder.
      //
      // Delete folder children recursively. On an exception, keep going to
      // delete as much as can be deleted.
      //
      // Since recursion calls this same deleteInternal() function, and
      // this function's only exception is for locks, the only exception
      // that can occur during this whole recursion is for locks. There
      // is no need to save every exception - just count them.
      foreach ($this->getChildren() as $child) {
        try {
          // Recurse!
          $child->deleteInternal($fast, $usageChange);
        }
        catch (\Exception $e) {
          // Lock failure on the child or something deeper.
          // Count the exception and continue with the other
          // child folders.
          ++$nExceptions;
        }
      }
    }

    //
    // Unlock
    // ------
    // We have to release the lock before deleting the folder because
    // the lock uses the folder's ID, which becomes invalid once the
    // folder is deleted.
    //
    // UNLOCK THIS FOLDER.
    if ($fast === FALSE) {
      $this->releaseLock();
    }

    // If we got any exceptions, some child folder(s) could not be
    // locked and deleted, so we cannot delete this folder or else the
    // child folders would become orphaned.
    if ($nExceptions !== 0) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    // Let the parent class clean up and finish deleting this folder.
    // This invokes pre- and post-delete hooks and removes the entity
    // from storage between the two.
    parent::delete();
  }

  /**
   * Deletes all files and folders owned by any user.
   *
   * This function is intended for site administrators to enable them
   * to clear out content quickly. It can be used to delete all content
   * prior to uninstalling the module or changing it's configuration.
   *
   * This function does not lock or check locks on folders or the root
   * list. Doing so would not be practical because it is a bulk
   * operation. The site should have no logged in users and, ideally,
   * it should be in mainenance mode.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is cleared for all users.
   *
   * <B>Access control:</B>
   * This function does not check permissions or folder-based access
   * controls. The user should have administrative access.  Upon
   * completion, all access controls are deleted.
   *
   * <B>Locking:</B>
   * This function does not lock anything. It presumes that it is safe to
   * immediately take action.
   *
   * @param int $uid
   *   (optional) The user ID of the user for whome to delete all content.
   *   If the user ID is -1, all content is deleted.
   *
   * @see ::delete()
   */
  public static function deleteAll(int $uid = -1) {
    //
    // Validate
    // --------
    // The user ID must be either a (-1) or a valid user ID.
    if ($uid < -1) {
      // Invalid UID.
      return;
    }

    //
    // Execute for user
    // ----------------
    // If a user ID was provided, delete everything owned by that user.
    if ($uid !== -1) {
      self::deleteAllForUser($uid);
      return;
    }

    //
    // Execute for everything
    // ----------------------
    // If the user ID is (-1), delete EVERYTHING! This is extreme but can
    // be used to clear away all content before uninstalling the module.
    //
    // It is tempting to just truncate the folder table, but this
    // doesn't give other modules a chance to clean up in response to hooks.
    // The primary relevant module is the File module, which keeps usage
    // counts for files. But other modules may have added fields to
    // folders, and they too need a graceful way of handling deletion.
    //
    // So, we need to get all root folders and delete each of them, which
    // triggers recursion down through the folder tree.
    $rootIds = self::getAllRootFolderIds();

    foreach ($rootIds as $id) {
      try {
        // Load the next root folder.
        $folder = self::load($id);
        if ($folder !== NULL) {
          // Delete it and everything below it in its subtree.
          $usageChange = [];
          $folder->deleteInternal(TRUE, $usageChange);
        }
      }
      catch (\Exception $e) {
        // This should not be possible since the above code does
        // not call anything that can throw exceptions. Keep going.
      }
    }

    //
    // Execute delete of user usage tracking
    // -------------------------------------
    // Clear the usage for everybody since nobody has any content left.
    FolderShareUsage::clearAllUsage();
  }

  /**
   * Deletes all files and folders owned by a user.
   *
   * This method is private. Is used by deleteAll().
   *
   * This function is inherently slow. It must find all root folders,
   * subfolders, and files owned by the user anywhere and delete them
   * one by one. During deletion, it must lock and unlock relevant
   * folders and update access controls and usage.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is cleared for the user.
   *
   * <B>Access control:</B>
   * This function does not check permissions or folder-based access
   * controls. The user should have administrative access.
   *
   * <B>Locking:</B>
   * The root folder list and individual folders and subfolders are all
   * locked for exclusive editing access by this function for the
   * duration of the delete.
   *
   * @param int $uid
   *   The user ID of the user for whome to delete all content.
   *
   * @see ::deleteAll()
   */
  private static function deleteAllForUser(int $uid) {
    //
    // Execute delete of folder trees
    // ------------------------------
    // Start by getting all root folders owned by the user and
    // deleting their folder trees.  This deletes all subfolders
    // and files in those trees and will likely delete most of
    // the content owned by this user.
    //
    // As a side-effect, if there are any files or folders within
    // these trees that are owned by somebody else, they too will be
    // deleted. There is no alternative since leaving those items
    // would require re-parenting them somewhere, and we have no place
    // to put them.  Besides, access control rules have already established
    // that if someone owns a folder, they are free to delete anything
    // in the folder, regardless of who owns it.
    //
    // Deleting a folder automatically deletes the associated
    // access controls and usage.
    $rootFolderIds = self::getAllRootFolderIds($uid);

    foreach ($rootFolderIds as $rootFolderId) {
      $rootFolder = self::load($rootFolderId);
      if ($rootFolder !== NULL) {
        $rootFolder->delete();
      }
    }

    //
    // Execute delete of isolated items (e.g. files and folders).
    // --------------------------------
    // The user may also own items inside root folder trees owned
    // by another user. Find those and delete them too.
    //
    // The list of IDs has no order and may have parent and
    // child folders interleaved. It is possible, then, to encounter
    // a parent folder and delete it, and its children, and then
    // encounter the child. That child will fail to load, so ignore it.
    $ids = self::getAllIds($uid);
    foreach ($ids as $id) {
      $item = self::load($id);
      if ($item !== NULL) {
        $item->delete();
      }
    }

    //
    // Execute delete of user usage tracking
    // -------------------------------------
    // Clear the usage for the user since they no longer
    // have any content to count.
    //
    // This is probably redundant since the above activities should
    // have automatically updated the usage (repeatedly) as
    // each item was deleted.
    FolderShareUsage::clearUsage($uid);
  }

  /**
   * {@inheritdoc}
   */
  public function createDuplicate() {
    // This method's actions are slightly in conflict with the base Entity
    // class's definition for the createDuplicate() method.
    //
    // createDuplicate() is supposed to:
    // - Create and return a clone of $this with all fields filled in,
    //   except for an ID, so that saving it will insert the new entity
    //   into the storage system.
    //
    // This is not practical for FolderShare, where duplicating the entity
    // also needs to duplicate its children. And those children need to
    // point back to the parent, so the parent has to have been fully
    // created first.
    //
    // Therefore, this entity's implementation of createDuplicate() creates
    // AND saves the entity, thereby creating the ID and commiting the new
    // entity to storage. If the caller calls save() again on the returned
    // entity, this won't hurt anything. But if they think that skipping a
    // call to save() will avoid saving the entity, that won't be true.
    //
    // Execute
    // -------
    // Duplicate this entity.
    return $this->duplicate();
  }

  /**
   * {@inheritdoc}
   */
  public function duplicate() {
    //
    // Validate
    // --------
    // Make sure there is a parent folder that can hold a new copy of
    // this item.
    $parent = $this->getParentFolder();
    if ($parent === NULL) {
      return $this->copyToRoot();
    }

    //
    // Execute
    // -------
    // Copy this item into this item's parent. This will automatically
    // rename the copy so that it doesn't collide with this entity.
    return $this->copyToFolder($parent);
  }

  /**
   * {@inheritdoc}
   */
  public function moveToRoot(string $newName = '') {
    //
    // Validate
    // --------
    // This entity must be a folder. It could be a root folder, which just
    // means that below we'll be renaming it but leaving it as a root folder.
    if ($this->isFolderOrRootFolder() === FALSE) {
      throw new ValidationException(t(
        'Only folders can be moved to the top-level folder list.'));
    }

    //
    // Check names
    // -----------
    // If there is a new name, make sure it is legal.
    $doRename = FALSE;
    if (empty($newName) === FALSE) {
      // Check that the new name is legal.
      if (FileUtilities::isNameLegal($newName) === FALSE) {
        throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
      }

      $doRename = TRUE;
    }

    //
    // Lock
    // ----
    // This is indirectly a recursing function that moves a folder
    // tree into a new folder tree.  Locks are held during recursion
    // deeper, and released on the way up.
    //
    // LOCK THIS FOLDER TREE.
    // LOCK ROOT LIST.
    if ($this->acquireDeepLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    if (self::acquireRootLock() === FALSE) {
      // UNLOCK THIS FOLDER TREE.
      $this->releaseDeepLock();
      throw new LockException(t(self::EXCEPTION_ROOT_LIST_IN_USE));
    }

    //
    // Check names
    // -----------
    // Watch for name collisions.  This is only a safe check if
    // we have a lock on the root list (which we do) while checking
    // names so that nothing can change during our check.
    $savedException = NULL;
    $uid = \Drupal::currentUser()->id();
    if ($doRename === TRUE) {
      $nameToCheck = $newName;
    }
    else {
      $nameToCheck = $this->getName();
    }

    if (isset(self::getAllRootFolderNames($uid)[$nameToCheck]) === TRUE) {
      // UNLOCK ROOT LIST.
      // UNLOCK THIS FOLDER TREE.
      self::releaseRootLock();
      $this->releaseDeepLock();
      throw new ValidationException(t(self::EXCEPTION_MOVE_NAME_IN_USE));
    }

    //
    // Execute
    // -------
    // Move this folder.  Change the parent ID.
    // Don't change the root ID yet.
    $this->parentid->setValue(['target_id' => NULL]);

    // Add default access grants now that this folder is a root folder.
    $this->addDefaultAccessGrants();

    // Update the kind to be a root folder.
    $k = $this->getKind();
    if ($k === self::FOLDER_KIND) {
      $this->kind->setValue(self::ROOT_FOLDER_KIND);
    }

    // Change the name!
    if ($doRename === TRUE) {
      $this->setName($newName);
    }

    $this->save();

    // Update root IDs for all child folders.
    $savedException = NULL;
    try {
      // We call the 'internal' function because we already
      // have a deep lock on the folder tree.
      $this->setAllRootFolderIdInternal((int) $this->id());
    }
    catch (\Exception $e) {
      $savedException = $e;
    }

    //
    // Unlock
    // ------
    // UNLOCK ROOT LIST.
    // UNLOCK THIS FOLDER TREE.
    self::releaseRootLock();
    $this->releaseDeepLock();

    if ($savedException !== NULL) {
      throw $savedException;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function moveToFolder(
    FolderShareInterface $dstParent = NULL,
    string $newName = '') {
    //
    // Validate
    // --------
    // There must be a destination, it must be a folder, and it can't
    // be the current parent folder.
    if ($dstParent === NULL) {
      // There is no destination, so move it to the root folder list.
      $this->moveToRoot();
      return;
    }

    if ($dstParent->isFolderOrRootFolder() === FALSE) {
      throw new ValidationException(t(
        'Items can only be moved into folders.'));
    }

    // If this folder's parent is the destination, then the move
    // is redundant.
    $dstParentId = (int) $dstParent->id();
    if ($this->getParentFolderId() === $dstParentId) {
      return;
    }

    //
    // Check names
    // -----------
    // If there is a new name, make sure it is legal and meets any
    // file extension restrictions.
    $doRename = FALSE;
    if (empty($newName) === FALSE) {
      // Check that the new name is legal.
      if (FileUtilities::isNameLegal($newName) === FALSE) {
        throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
      }

      // If this item is a file or image, verify that the name meets any
      // file name extension restrictions.
      if ($this->isFile() === TRUE || $this->isImage() === TRUE) {
        $extensionsString = self::getFileAllowedExtensions();
        if (empty($extensionsString) === FALSE) {
          $extensions = mb_split(' ', $extensionsString);
          if (FileUtilities::isNameExtensionAllowed($newName, $extensions) === FALSE) {
            throw new ValidationException(t(self::EXCEPTION_NAME_EXT_NOT_ALLOWED));
          }
        }
      }

      $doRename = TRUE;
    }

    //
    // Lock
    // ----
    // This is indirectly a recursing function that moves a folder
    // tree into a new folder tree.  Locks are held during recursion
    // deeper, and released on the way up.
    //
    // LOCK THIS FOLDER TREE.
    if ($this->acquireDeepLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    // LOCK PARENT FOLDER.
    if ($dstParent->acquireLock() === FALSE) {
      // UNLOCK THIS FOLDER TREE.
      $this->releaseDeepLock();
      throw new LockException(t(self::EXCEPTION_DST_FOLDER_IN_USE));
    }

    //
    // Check names
    // -----------
    // Watch for name collisions and circular moves.
    //
    // This is only a safe to do if we have a lock on the folders
    // (which we do) while checking so that nothing can change
    // during our check.
    $savedException = NULL;
    if ($doRename === TRUE) {
      $nameToCheck = $newName;
    }
    else {
      $nameToCheck = $this->getName();
    }

    if ($dstParentId === (int) $this->id() ||
        $this->isAncestorOfFolderId($dstParentId) === TRUE) {
      $savedException = new ValidationException(
          t(self::EXCEPTION_INVALID_MOVE));
    }
    elseif (isset($dstParent->getChildNames()[$nameToCheck]) === TRUE) {
      $savedException = new ValidationException(
        t(self::EXCEPTION_MOVE_NAME_IN_USE));
    }

    if ($savedException !== NULL) {
      // UNLOCK PARENT FOLDER.
      // UNLOCK THIS FOLDER TREE.
      $dstParent->releaseLock();
      $this->releaseDeepLock();
      throw $savedException;
    }

    //
    // Execute
    // -------
    // Move this folder.  Change the parent ID.  Don't change the root ID yet.
    $wasRoot = $this->isRootFolder();
    $this->parentid->setValue(['target_id' => $dstParentId]);

    // If the folder was a root folder, then any access controls on the
    // folder are now unused. The folder is now subject to access controls
    // on the folder's new root.
    if ($wasRoot === TRUE) {
      $this->clearAccessGrants();
    }

    // Update the kind to be a subfolder.
    $k = $this->getKind();
    if ($k === self::ROOT_FOLDER_KIND) {
      $this->kind->setValue(self::FOLDER_KIND);
    }

    // Change the name!
    if ($doRename === TRUE) {
      $this->setName($newName);
    }

    $this->save();

    if ($doRename === TRUE) {
      // If the item is a file, image, or media object change the underlying
      // item's name too.
      $this->renameUnderlyingFile($newName);
    }

    // Clear the destination's folder size. It needs to be updated
    // to account for the new child folder tree.
    $dstParent->clearSize();
    $dstParent->save();

    // Update root IDs for all child folders.
    $savedException = NULL;
    $rootId = $dstParent->getRootFolderId();
    if ($this->getRootFolderId() !== $rootId) {
      try {
        // We call the 'internal' function because we already
        // have a deep lock on the folder tree.
        $this->setAllRootFolderIdInternal($rootId);
      }
      catch (\Exception $e) {
        $savedException = $e;
      }
    }

    //
    // Unlock
    // ------
    // UNLOCK PARENT FOLDER.
    // UNLOCK THIS FOLDER TREE.
    $dstParent->releaseLock();
    $this->releaseDeepLock();

    // Update folder size.
    //
    // We could queue a task to update sizes, but the destination folder
    // has added just one folder (this one). While it is possible this
    // folder doesn't have a size, it probably does. In that case, this
    // folder is already in cache and updating the size should be very
    // quick. So do it immediately.
    self::updateSizes([(int) $dstParent->id()]);

    if ($savedException !== NULL) {
      throw $savedException;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rename(string $newName) {
    //
    // Validate
    // --------
    // The new name must be different and legal.
    if ($this->getName() === $newName) {
      // No change.
      return;
    }

    //
    // Check names
    // -----------
    // Check that the new name is legal.
    if (FileUtilities::isNameLegal($newName) === FALSE) {
      throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
    }

    // If this item is a file or image, verify that the name meets any
    // file name extension restrictions.
    if ($this->isFile() === TRUE || $this->isImage() === TRUE) {
      $extensionsString = self::getFileAllowedExtensions();
      if (empty($extensionsString) === FALSE) {
        $extensions = mb_split(' ', $extensionsString);
        if (FileUtilities::isNameExtensionAllowed($newName, $extensions) === FALSE) {
          throw new ValidationException(t(self::EXCEPTION_NAME_EXT_NOT_ALLOWED));
        }
      }
    }

    //
    // Lock
    // ----
    // LOCK THIS ITEM.
    if ($this->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    // LOCK PARENT FOLDER.
    // If this item has a parent, lock the parent too while we
    // check for a name collision.
    $parentFolder = $this->getParentFolder();
    if ($parentFolder !== NULL && $parentFolder->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    //
    // Check names
    // -----------
    // Check name uniqueness in the parent. This has to be done while the
    // parent is locked so that no other item can get in while we're checking.
    if ($parentFolder !== NULL) {
      if ($parentFolder->isNameUnique($newName, (int) $this->id()) === FALSE) {
        // UNLOCK PARENT FOLDER.
        // UNLOCK THIS ITEM.
        $parentFolder->releaseLock();
        $this->releaseLock();
        throw new ValidationException(t(self::EXCEPTION_NAME_IN_USE));
      }
    }
    elseif (self::isRootNameUnique($newName, (int) $this->id()) === FALSE) {
      // UNLOCK THIS ITEM.
      $this->releaseLock();
      throw new ValidationException(t(self::EXCEPTION_NAME_IN_USE));
    }

    //
    // Execute
    // -------
    // Change the name!
    $this->setName($newName);
    $this->save();

    // If the item is a file, image, or media object change the underlying
    // item's name too.
    $this->renameUnderlyingFile($newName);

    //
    // Unlock
    // ------
    // UNLOCK THIS ITEM.
    if ($parentFolder !== NULL) {
      // UNLOCK PARENT FOLDER.
      $parentFolder->releaseLock();
    }

    $this->releaseLock();
  }

  /**
   * Renames entities underlying files, images, and media.
   *
   * After a FolderShare entity has been renamed, this method updates any
   * underlying entities to share the same name. This includes File objects
   * underneath 'file' and 'image' kinds, and Media objects underneath
   * 'media' kinds.
   *
   * This method has no effect if the current entity is not a file, image,
   * or media wrapper.
   *
   * @param string $newName
   *   The new name for the underlying entities.
   */
  private function renameUnderlyingFile(string $newName) {
    // If the item is a file, image, or media object change the underlying
    // item's name too.  This public name appears in the file and image URI
    // as well so that field formatters can see a name and extension,
    // if they need it.
    if ($this->isFile() === TRUE || $this->isImage() === TRUE) {
      // Get the file's MIME type based upon the new name, which may
      // have changed the file name extension.
      $mimeType = \Drupal::service('file.mime_type.guesser')->guess($newName);

      if ($this->isFile() === TRUE) {
        $file = $this->getFile();
      }
      else {
        $file = $this->getImage();
      }

      if ($file !== NULL) {
        $file->setFilename($newName);
        $file->setFileUri(FileUtilities::getModuleDataFileUri($file));
        $file->setMimeType($mimeType);
        $file->save();
      }

      // If the file name has changed, the extension may have changed,
      // and thus the MIME type may have changed. Save the new MIME type.
      $this->setMimeType($mimeType);

      // If the newly renamed file now has a top-level MIME type that
      // indicates a change from a generic file to an image, or the
      // reverse, then we need to swap use of the 'file' and 'image'
      // fields in the FolderShare entity. Both fields still reference
      // a File entity.
      $isForImage = self::isMimeTypeImage($mimeType);
      if ($this->isFile() === TRUE && $isForImage === TRUE) {
        // The file was a generic file, and now it is an image.
        $this->image->setValue(['target_id' => $file->id()]);
        $this->file->setValue(['target_id' => NULL]);
        $this->kind->setValue(self::IMAGE_KIND);
      }
      elseif ($this->isImage() === TRUE && $isForImage === FALSE) {
        // The file was an image, and now it is a generic file.
        $this->file->setValue(['target_id' => $file->id()]);
        $this->image->setValue(['target_id' => NULL]);
        $this->kind->setValue(self::FILE_KIND);
      }

      $this->save();
    }
    elseif ($this->isMedia() === TRUE) {
      $media = $this->getMedia();
      if ($media !== NULL) {
        $media->setName($newName);
        $media->save();
      }
    }
  }

  /*---------------------------------------------------------------------
   *
   * File operations.
   *
   * All of these methods work with File objects from the Drupal core
   * File module. File objects are always wrapped with a FolderShare entity
   * of kind 'file', with the File object's ID saved to a 'file' field.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function addFile(FileInterface $file, bool $allowRename = FALSE) {
    // Add the file, checking that the name is legal (possibly rename).
    // Check if the file is already in the folder, and lock the folder
    // as needed.
    $ary = $this->addFilesInternal([$file], TRUE, $allowRename, TRUE, TRUE);
    if (empty($ary) === TRUE) {
      return NULL;
    }

    return $ary[0];
  }

  /**
   * {@inheritdoc}
   */
  public function addFiles(array $files, bool $allowRename = FALSE) {
    // Add the files, checking that their names are legal (possibly rename).
    // Check if the files are already in the folder, and lock the folder
    // as needed.
    return $this->addFilesInternal($files, TRUE, $allowRename, TRUE, TRUE);
  }

  /**
   * Implements addition of files to this folder.
   *
   * This method is private. It is used by addFile(), addFiles(),
   * and addUploadFiles().
   *
   * An exception is thrown if any file is already in a folder, or if
   * this entity is not a folder.
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
   * <B>Usage tracking:</B>
   * Usage tracking is updated for the current user.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This folder is *optionally* locked by this method, based upon the
   * lock argument.  However, if locking is disabled by this method
   * the caller MUST have locked this folder for exclusive editing
   * access BEFORE calling this method.
   *
   * @param \Drupal\foldershare\file\FileInterface[] $files
   *   An array of File enities to be added to this folder.  An empty
   *   array and NULL file objects are silently skipped.
   * @param bool $checkNames
   *   When TRUE, each file's name is checked to be sure that it is
   *   valid and not already in use. When FALSE, name checking is
   *   skipped (including renaming) and the caller must assure that
   *   names are good.
   * @param bool $allowRename
   *   When TRUE (and when $checkNames is TRUE), each file's name
   *   will be automatically renamed, if needed, to insure that it
   *   is unique within the folder. When FALSE (and when $checkNames
   *   is TRUE), non-unique file names cause an exception to be thrown.
   * @param bool $checkForInFolder
   *   When TRUE, an exception is thrown if the file is already in a
   *   folder. When FALSE, this expensive check is skipped.
   * @param bool $lock
   *   True to have this method lock around folder access, and
   *   FALSE to skip locking. When skipped, the caller MUST have locked
   *   the folder BEFORE calling this method.
   *
   * @return \Drupal\foldershare\FolderShareInterface[]
   *   Returns an array of the newly created FolderShare file entities
   *   added to this folder using the given $files File entities.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Thrown if an access lock on this folder could not be acquired.
   *   This exception is never thrown if $lock is FALSE.
   *
   * @throws \Drupal\foldershare\Entity\Exception\ValidationException
   *   Thrown if addition of the files does not validate (very unlikely).
   *   If $checkForInFolder, also thrown if any file is already in a
   *   folder.  If $checkNames, also thrown if any name is illegal.
   *   If $checkNames and $allowRename, also thrown if a unique name
   *   could not be found. If $checkNames and !$allowRename, also
   *   thrown if a name is already in use.
   *
   * @see ::addFile()
   * @see ::addFiles()
   * @see ::addUploadFiles()
   */
  private function addFilesInternal(
    array $files,
    bool $checkNames = TRUE,
    bool $allowRename = FALSE,
    bool $checkForInFolder = TRUE,
    bool $lock = TRUE) {

    //
    // Validate
    // --------
    // Make sure we're given files to add, and that this is a folder.
    // Then check that none of the files are already in a folder.
    if (empty($files) === TRUE) {
      // Nothing to add.
      return NULL;
    }

    if ($this->isFolderOrRootFolder() === FALSE) {
      throw new ValidationException(t(
        'Cannot add files to an item that is not a folder.'));
    }

    // If requested, insure that none of the files are already in a folder.
    $filesWithGoodParentage = [];
    if ($checkForInFolder === TRUE) {
      foreach ($files as $index => $file) {
        if ($file === NULL) {
          continue;
        }

        // Get the file's parent folder ID.  There are three cases:
        // - (-1)      = file has no parent, which is what we want.
        // - $this->id = file's parent is this folder, so skip it.
        // - other     = file already has parent, so error.
        $parentFolderId = self::getFileParentFolderId($file);

        if ($parentFolderId !== -1) {
          if ($parentFolderId === (int) $this->id()) {
            // Already in this folder. Skip it.
            continue;
          }

          // Complain.
          $v = new ValidationException(
            t(self::EXCEPTION_FILE_ALREADY_IN_FOLDER));
          $v->setItemNumber($index);
          throw $v;
        }

        // The file is not already in a folder, so add it to the list
        // to process.
        $filesWithGoodParentage[] = $file;
      }
    }
    else {
      // Files are assumed to be not in a folder.
      foreach ($files as $file) {
        if ($file !== NULL) {
          $filesWithGoodParentage[] = $file;
        }
      }
    }

    if (empty($filesWithGoodParentage) === TRUE) {
      return NULL;
    }

    // Before the expense of a folder lock, check that all files
    // have legal names.
    if ($checkNames === TRUE) {
      foreach ($filesWithGoodParentage as $index => $file) {
        $name = $file->getFilename();
        if (FileUtilities::isNameLegal($name) === FALSE) {
          $e = new ValidationException(
            t(self::EXCEPTION_NAME_INVALID));
          $e->setItemNumber($index);
          throw $e;
        }
      }
    }

    // LOCK THIS FOLDER.
    if ($lock === TRUE && $this->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    // Check name uniqueness.
    $filesToAdd     = [];
    $originalNames  = [];
    $savedException = NULL;

    if ($checkNames === TRUE) {
      // Checking a list of child names is only safe to do and act
      // upon while the folder is locked and no other process can
      // make changes.
      $childNames = $this->getChildNames();

      foreach ($filesWithGoodParentage as $index => $file) {
        $name = $file->getFilename();

        if ($allowRename === TRUE) {
          // If not unique, rename.
          $uniqueName = self::createUniqueName($childNames, $name);

          if ($uniqueName === FALSE) {
            $savedException = new ValidationException(
              t(self::EXCEPTION_NAME_INVALID));
            $savedException->setItemNumber($index);
            break;
          }

          // Change public file name. This name also appears in the URI.
          $originalNames[(int) $file->id()] = $name;
          $file->setFilename($uniqueName);
          $file->setFileUri(FileUtilities::getModuleDataFileUri($file));
          $file->save();

          // Add to the child name list so that further checks
          // will catch collisions.
          $childNames[$uniqueName] = 1;
        }
        elseif (isset($childNames[$name]) === TRUE) {
          // Not unique and not allowed to rename. Fail.
          $savedException = new ValidationException(
            t(self::EXCEPTION_NAME_IN_USE));
          $savedException->setItemNumber($index);
          break;
        }

        $filesToAdd[] = $file;
      }

      if ($savedException !== NULL) {
        // Something went wrong. Some files might already have
        // been renamed. Restore them to their prior names.
        if ($allowRename === TRUE) {
          foreach ($filesWithGoodParentage as $file) {
            if (isset($originalNames[(int) $file->id()]) === TRUE) {
              // Restore the prior name.
              $file->setFilename($originalNames[(int) $file->id()]);
              $file->setFileUri(FileUtilities::getModuleDataFileUri($file));
              $file->save();
            }
          }
        }

        // UNLOCK THIS FOLDER.
        if ($lock === TRUE) {
          $this->releaseLock();
        }

        throw $savedException;
      }
    }
    else {
      // All of the files are assumed to have safe names.
      $filesToAdd = $filesWithGoodParentage;
    }

    if (empty($filesToAdd) === TRUE) {
      // UNLOCK THIS FOLDER.
      if ($lock === TRUE) {
        $this->releaseLock();
      }

      return NULL;
    }

    //
    // Add files
    // ---------
    // Create FolderShare entities for the files. For each one,
    // set the parent ID to be this folder, and the root ID to
    // be this folder's root.
    $uid = \Drupal::currentUser()->id();
    $parentid = $this->id();
    $rootid = $this->getRootFolderId();
    $newEntities = [];

    $nBytes = 0;
    foreach ($filesToAdd as $file) {
      $nBytes += $file->getSize();

      // Get the MIME type for the file.
      $mimeType = $file->getMimeType();

      // If the file is an image, set the 'image' field to the file ID
      // and the kind to IMAGE_KIND. Otherwise set the 'file' field to
      // the file ID and the kind to FILE_KIND.
      if (self::isMimeTypeImage($mimeType) === TRUE) {
        $valueForFileField = NULL;
        $valueForImageField = $file->id();
        $valueForKindField = self::IMAGE_KIND;
      }
      else {
        $valueForFileField = $file->id();
        $valueForImageField = NULL;
        $valueForKindField = self::FILE_KIND;
      }

      // Create new FolderShare entity in this folder.
      // - Automatic id.
      // - Automatic uuid.
      // - Automatic creation date.
      // - Automatic changed date.
      // - Automatic langcode.
      // - Empty description.
      // - Empty author grants.
      // - Empty view grants.
      // - Empty disabled grants.
      $f = self::create([
        'name'     => $file->getFilename(),
        'uid'      => $uid,
        'kind'     => $valueForKindField,
        'mime'     => $mimeType,
        'file'     => $valueForFileField,
        'image'    => $valueForImageField,
        'size'     => $file->getSize(),
        'parentid' => $parentid,
        'rootid'   => $rootid,
      ]);

      $f->save();

      $newEntities[] = $f;
    }

    // Clear the folder's size, which has now increased.
    $this->clearSize();
    $this->save();

    // UNLOCK THIS FOLDER.
    if ($lock === TRUE) {
      $this->releaseLock();
    }

    // Update folder size.
    //
    // We could queue a job to update sizes, but the added files only
    // afect this folder and their sizes are already available in their
    // File objects, which are already in cache. Updating this folder's
    // size should be very quick, so do it immediately.
    self::updateSizes([(int) $this->id()]);

    // Update usage tracking.
    FolderShareUsage::updateUsageFiles($uid, count($filesToAdd));
    FolderShareUsage::updateUsageBytes($uid, $nBytes);

    return $newEntities;
  }

  /*---------------------------------------------------------------------
   *
   * Archive operations.
   *
   * These functions handle creating archives of files and folders.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function archiveToZip(array $children) {
    //
    // Validate
    // --------
    // This item must be a folder or root folder, and all of the children
    // must be non-NULL and children of this folder. All of these are
    // programmer errors.
    if ($this->isFolderOrRootFolder() === FALSE) {
      throw new ValidationException(t(self::EXCEPTION_ITEM_NOT_FOLDER));
    }

    if (empty($children) === TRUE) {
      throw new ValidationException(t(self::EXCEPTION_ARRAY_EMPTY));
    }

    // Count valid children and reject those that are not children of
    // this folder.
    $childIds = $this->getChildIds();
    $nItems = 0;
    $firstItem = NULL;
    foreach ($children as $child) {
      if ($child === NULL) {
        // Silently ignore empty children.
        continue;
      }

      if (in_array($child->id(), $childIds) === FALSE) {
        // Not a child of this folder.
        throw new ValidationException(t(self::EXCEPTION_ITEM_NOT_IN_FOLDER));
      }

      switch ($child->getKind()) {
        case self::FILE_KIND:
        case self::IMAGE_KIND:
        case self::FOLDER_KIND:
        case self::ROOT_FOLDER_KIND:
          ++$nItems;
          if ($firstItem === NULL) {
            $firstItem = $child;
          }
          break;

        case self::MEDIA_KIND:
        default:
          // Item's kind does not support a file that we can add to the
          // archive. Ignore it.
          break;
      }
    }

    if ($nItems === 0) {
      throw new SystemException(
        t(self::EXCEPTION_ARCHIVE_NOTHING_SUITABLE));
    }

    //
    // Confirm that the site allows ZIP archives
    // -----------------------------------------
    // If the site restricts extensions and doesn't allow .zip files,
    // then we cannot create an archive.
    $extensionsString = self::getFileAllowedExtensions();
    if (empty($extensionsString) === FALSE) {
      // Extensions are limited.
      $extensions = mb_split(' ', $extensionsString);
      $zipFound = FALSE;
      foreach ($extensions as $ext) {
        if ($ext === 'zip') {
          $zipFound = TRUE;
        }
      }

      if ($zipFound === FALSE) {
        throw new SystemException(
          t(self::EXCEPTION_ARCHIVE_REQUIRES_ZIP_EXT_NOT_ALLOWED));
      }
    }

    //
    // Create archive in local temp storage
    // ------------------------------------
    // Create a ZIP archive and add this item and all of its children into it.
    // Upon completion, a ZIP archive exists on the local file system in a
    // temporary file. This throws an exception and deletes the archive on
    // an error.
    $archiveUri = self::createZipArchive($children);

    //
    // Create File entity
    // ------------------
    // Create a new File entity from the local file. This also moves the
    // file into FolderShare's directory tree and gives it a numeric name.
    // The MIME type is also set, and the File is marked as permanent.
    // This throws an exception if the File could not be created.
    try {
      // Choose the name of the archive. If there is one item, use it's
      // name with ".zip" appended. Otherwise use a generic name for
      // multiple children.
      if ($nItems === 1) {
        $archiveName = $firstItem->getName() . '.zip';
      }
      else {
        $archiveName = self::NEW_ZIP_ARCHIVE;
      }

      $archiveFile = self::createFileEntityFromLocalFile(
        $archiveUri,
        $archiveName);
    }
    catch (\Exception $e) {
      FileUtilities::unlink($archiveUri);
      throw $e;
    }

    //
    // Add the archive to this folder
    // ------------------------------
    // Add the file, checking for and correcting name collisions. Lock
    // this folder during the add and pdate usage tracking.
    try {
      $ary = $this->addFilesInternal(
        [$archiveFile],
        TRUE,
        TRUE,
        FALSE,
        TRUE);
    }
    catch (\Exception $e) {
      // On any failure, the archive wasn't added and we need to delete it.
      $archiveFile->delete();
      throw $e;
    }

    if (empty($ary) === TRUE) {
      return NULL;
    }

    return $ary[0];
  }

  /**
   * Creates and adds a list of children to a local ZIP archive.
   *
   * A new ZIP archive is created in the site's temporary directory
   * on the local file system. The given list of children are then
   * added to the archive and the file path of the archive is returned.
   *
   * If an error occurs, an exception is thrown and the archive file is
   * deleted.
   *
   * If a URI for the new archive is not provided, a temporary file is
   * created in the site's temporary directory, which is normally cleaned
   * out regularly. This limits the lifetime of the file, though
   * callers should delete the file when it is no longer needed, or move
   * it out of the temporary directory.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is not updated because no new FolderShare entities are
   * created.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * Each child file or folder and all of their subfolders and files are
   * locked for exclusive editing access by this function for the duration of
   * the archiving.
   *
   * @param \Drupal\foldershare\FoldershareInterface[] $children
   *   An array of FolderShare files and/or folders that are to be included
   *   in a new ZIP archive. They should all be children of the same parent
   *   folder.
   * @param string $archiveUri
   *   (optional, default = '' = create temp name) The URI for a local file
   *   to be overwritten with the new ZIP archive. If the URI is an empty
   *   string, a temporary file with a randomized name will be created in
   *   the site's temporary directory. The name will not have a filename
   *   extension.
   *
   * @return string
   *   Returns a URI to the new ZIP archive. The URI refers to a new file
   *   in the module's temporary files directory, which is cleaned out
   *   periodically. Callers should move the file to a new destination if
   *   they intend to keep the file.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Thrown if an access lock on any child could not be acquired.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   Thrown if the archive file could not be created, such as if the
   *   temporary directory does not exist or is not writable, if a temporary
   *   file for the archive could not be created, or if any of the file
   *   children of this item could not be read and saved into the archive.
   */
  public static function createZipArchive(
    array $children,
    string $archiveUri = '') {

    if (empty($archiveUri) === TRUE) {
      // Create an empty temporary file. The file will have a randomized
      // name guaranteed not to collide with anything.
      $archiveUri = FileUtilities::tempnam(
        FileUtilities::getTempDirectoryUri(),
        'zip');

      if ($archiveUri === FALSE) {
        // This can fail if file system permissions are messed up, the
        // file system is full, or some other system error has occurred.
        throw new SystemException(
          t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_CREATE));
      }
    }

    $locked = [];
    $archive = NULL;

    //
    // Implementation note: How ZipArchive works
    //
    // Creating a ZipArchive selects a file name for the output.  Adding files
    // to the archive just adds those files to a list in memory of files
    // TO BE ADDED, but doesn't add them immediately or do any file I/O. The
    // actual file I/O occurs entirely when close() is called.
    //
    // This impacts when we lock files and folders. We cannot just lock
    // them before and after the archive addFile() call because that call
    // doesn't do the file I/O. Our locks would not guarantee that the file
    // continues to exist until the file I/O really happens on close().
    //
    // Instead, we need to do a deep lock of everything FIRST, before
    // adding anything to the archive. Then we need to add the files and
    // finally close the archive to trigger the file I/O. And then when
    // the file I/O is done we can safely unlock everything LAST.
    try {
      //
      // Lock
      // ----
      // Lock all of the children folder trees while we add them to the
      // archive.
      foreach ($children as $child) {
        if ($child !== NULL) {
          // LOCK CHILD FOLDER TREE.
          $child->acquireDeepLock();
          $locked[] = $child;
        }
      }

      //
      // Create the ZipArchive object
      // ----------------------------
      // Create the archiver and assign it the output file.
      $archive = new \ZipArchive();

      $archivePath = FileUtilities::realpath($archiveUri);

      if ($archive->open($archivePath, \ZipArchive::OVERWRITE) !== TRUE) {
        // All errors that could be returned are very unlikely. The
        // archive's path is known to be good since we just created a
        // temp file using it.  This means permissions are right, the
        // directory and file name are fine, and the file system is up
        // and working. Something catestrophic now has happened.
        throw new SystemException(
          t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_CREATE));
      }

      $archive->setArchiveComment(self::NEW_ARCHIVE_COMMENT);

      //
      // Recursively add to archive
      // --------------------------
      // For each of the given children, add them to the archive.  An exception
      // is thrown if any child, or its children, cannot be added. That
      // causes us to abort.
      foreach ($children as $child) {
        if ($child !== NULL) {
          $child->addToZipArchiveInternal($archive, '');
        }
      }

      if ($archive->numFiles === 0) {
        // Nothing was added during the above loop. This can mean that
        // everything that was selected to add had no underlying file data
        // suitable for adding the the archive.
        $archive->unchangeAll();
        unset($archive);
        FileUtilities::unlink($archiveUri);
        throw new SystemException(
          t(self::EXCEPTION_ARCHIVE_NOTHING_SUITABLE));
      }

      //
      // Clean up
      // --------
      // Close the archive. This triggers a lot of file I/O to finally add
      // all of the files to the archive.
      if ($archive->close() === FALSE) {
        // Unfortunately something went wrong with the file I/O. Abort.
        throw new SystemException(
          t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_CREATE));
      }

      // Change the permissions to be suitable for web serving.
      FileUtilities::chmod($archiveUri);

      //
      // Unlock
      // ------
      // The file I/O is done. Unlock everything.
      foreach ($children as $child) {
        if ($child !== NULL) {
          // UNLOCK CHILD FOLDER TREE.
          $child->releaseDeepLock();
        }
      }

      return $archiveUri;
    }
    catch (\Exception $e) {
      // Something failed. Back out and return.
      //
      // Delete the archive without explicitly closing it so that we
      // don't trigger any unnecessary file I/O.
      if ($archive !== NULL) {
        $archive->unchangeAll();
        unset($archive);
      }

      // Delete the archive file as it exists so far.
      FileUtilities::unlink($archiveUri);

      // Unlock everything that got locked. If we hit a lock exception,
      // this list of what was locked might be fewer than the list of
      // children.
      foreach ($locked as $child) {
        if ($child !== NULL) {
          // UNLOCK CHILD FOLDER TREE.
          $child->releaseDeepLock();
        }
      }

      throw $e;
    }
  }

  /**
   * Adds this item to the archive and recurses.
   *
   * This item is added to the archive, and then all its children are
   * added.
   *
   * If this item is a folder, all of the folder's children are added to
   * the archive. If the folder is empty, an empty directory is added to
   * the archive.
   *
   * If this item is a file, the file on the file system is copied into
   * the archive.
   *
   * To insure that the archive has the user-visible file and folder names,
   * an archive path is created during recursion. On the first call, a base
   * path is passed in as $baseZipPath. This item's name is then appended.
   * If this item is a file, the path with appended name is used as the
   * name for the file when in the archive. If this item is a folder, this
   * path with appended name is passed as the base path for adding the
   * folder's children, and so on.
   *
   * <B>Locking:</B>
   * This function does not lock anything. This is up to the caller to do.
   *
   * @param \ZipArchive $archive
   *   The archive to add to.
   * @param string $baseZipPath
   *   The folder path to be used within the ZIP archive to lead to the
   *   parent of this item.
   *
   * @throws \Drupal\foldershare\Entity\Exception\LockException
   *   Thrown if an access lock on this folder could not be acquired.
   *   This exception is never thrown if $lock is FALSE.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   Thrown if a file could not be added to the archive.
   */
  private function addToZipArchiveInternal(
    \ZipArchive &$archive,
    string $baseZipPath) {
    //
    // Implementation note:
    //
    // The file path used within a ZIP file is recommended to always use
    // the '/' directory separator, regardless of the local OS conventions.
    // Since we are building a ZIP path, we therefore use '/'.
    //
    // Use the incoming folder path and append the user-visible item name
    // to create the name for the item as it should appear in the ZIP archive.
    if (empty($baseZipPath) === TRUE) {
      $currentZipPath = $this->getName();
    }
    else {
      $currentZipPath = $baseZipPath . '/' . $this->getName();
    }

    // Add the item to the archive.
    switch ($this->getKind()) {
      case self::FOLDER_KIND:
      case self::ROOT_FOLDER_KIND:
        // For folders, create an empty directory entry in the ZIP archive.
        // Then recurse to add all of the folder's children.
        if ($archive->addEmptyDir($currentZipPath) === FALSE) {
          throw new SystemException(
            t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_ADD));
        }

        foreach ($this->getChildren() as $child) {
          $child->addToZipArchiveInternal($archive, $currentZipPath);
        }
        break;

      case self::FILE_KIND:
        // For files, get the path to the underlying stored file and add
        // the file to the archive.
        $file     = $this->getFile();
        $fileUri  = $file->getFileUri();
        $filePath = FileUtilities::realpath($fileUri);

        if ($archive->addFile($filePath, $currentZipPath) === FALSE) {
          throw new SystemException(
            t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_ADD));
        }
        break;

      case self::IMAGE_KIND:
        // For images, get the path to the underlying stored file and add
        // the file to the archive.
        $file     = $this->getImage();
        $fileUri  = $file->getFileUri();
        $filePath = FileUtilities::realpath($fileUri);

        if ($archive->addFile($filePath, $currentZipPath) === FALSE) {
          throw new SystemException(
            t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_ADD));
        }
        break;

      case self::MEDIA_KIND:
      default:
        // For any other kind, we don't know what it is or it does not have
        // a stored file, so we cannot add it to the archive. Silently
        // ignore it.
        break;
    }
  }

  /*---------------------------------------------------------------------
   *
   * Unarchive operations.
   *
   * These functions handle extracting archives of files and folders.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function unarchiveFromZip() {
    //
    // Implementation note:
    //
    // A ZIP archive includes a list of files and folders. Each entry in the
    // list has a path, modification date, size, and assorted internal
    // attributes. Entries are listed in an order so that parent directories
    // are listed before files in those directories.
    //
    // Each entry's name is a relative path. Path components are separated
    // by '/' characters, regardless of the source or current OS. An entry
    // that ends in a '/' is for a directory.
    //
    // The task here is to extract everything from a ZIP archive and create
    // new FolderShare files and folders for that content. While the ZIP
    // archive supports a single extractTo() method that can dump the whole
    // archive into a subdirectory, this can cause file and directory names
    // to be changed based upon the limitations of the local OS. Names could
    // be shortened, special characters removed, and extensions shortened.
    // We don't want any of that. We want to retain the original names in
    // the entities we create.
    //
    // The extraction task is therefore one with multiple steps:
    //
    // 1. Extract the archive into a temporary directory. Assign each
    //    extracted file a generic numeric name (e.g. 1, 2, 3, 4) instead of
    //    using the original name, which may not work for this OS. Record
    //    this temporary name, the original ZIP name, and the other item
    //    attributes for later use.
    //
    // 2. Loop through all of the extracted files and folders and create
    //    corresponding FolderShare entities. Give those entities the
    //    original ZIP names and modification dates. For FolderShare files,
    //    also create a File object that wraps the stored file. Move that
    //    stored file from the temporary directory into FolderShare's
    //    normal file directory tree and rename it to use FolderShare's
    //    entity ID-based name scheme.
    //
    // 3. Delete the temporary directory. Since all of the files will have
    //    been moved out of it, all that will be left is empty directories.
    //
    // On errors, we need to clean up. The amount of cleanup depends upon
    // where the error occurs:
    //
    // 1. If the current FolderShare entity is not a file, or it is not
    //    recognized as a ZIP file, or it is corrupted, then abort. Delete
    //    anything extracted so far.
    //
    // 2. If there is a problem creating FolderShare entities, abort but
    //    keep whatever has been created so far. Delete the temp directory
    //    and whatever it contains.
    //
    // Validate
    // --------
    // This item must be a FolderShare file. We'll leave validating the
    // ZIP file until we try to unarchive it below.
    if ($this->isFile() === FALSE) {
      throw new ValidationException(t(self::EXCEPTION_ITEM_NOT_FILE));
    }

    //
    // Extract to local directory
    // --------------------------
    // The FolderShare file entity wraps a File object which in turn wraps
    // a locally stored file. Get that file path then open and extract
    // everything from the file. Lock the File while we do this.
    //
    // LOCK THIS FILE.
    if ($this->acquireLock() === FALSE) {
      throw new LockException(t(self::EXCEPTION_ITEM_IN_USE));
    }

    // Create a temporary directory for the archive's contents.
    $tempDirUri = FileUtilities::createLocalTempDirectory();

    // Get the local file path to the archive.
    $archivePath = FileUtilities::realpath($this->getFile()->getFileUri());

    // Extract into a temp directory.
    try {
      $entries = self::extractLocalZipFileToLocalDirectory(
        $archivePath,
        $tempDirUri);
    }
    catch (\Exception $e) {
      // A problem occurred while trying to unarchive the file. This
      // could be because the ZIP file is corrupted, or because there
      // is insufficient disk space to store the unarchived contents.
      // There could also be assorted system errors, like a file system
      // going off line or a permissions problem.
      //
      // UNLOCK THIS FILE.
      $this->releaseLock();
      FileUtilities::rrmdir($tempDirUri);
      throw $e;
    }

    // UNLOCK THIS FILE.
    $this->releaseLock();

    //
    // Decide content should go into a subfolder
    // -----------------------------------------
    // A ZIP file may contain any number of files and folders in an
    // arbitrary hierarchy. There are four cases of interest regarding
    // the top-level items:
    // - A single top-level file.
    // - A single top-level folder and arbitrary content.
    // - Multiple top-level files.
    // - Multiple top-level folders and arbitrary content.
    //
    // There are two common behaviors for these:
    //
    // - Unarchive single and multiple cases the same and put them all
    //   into the current folder.
    //
    // - Unarchive single items into the current folder, but unarchive
    //   multiple top-level items into a subfolder named after the archive.
    //   This prevents an archive uncompress from dumping a large number of
    //   files and folders all over a folder, which is confusing. This is
    //   the behavior of macOS.
    $topLevelFolder = $this->getParentFolder();
    if (self::UNARCHIVE_MULTIPLE_TO_SUBFOLDER === TRUE) {
      // When there are multiple top-level items, unarchive to a subfolder.
      //
      // Start by seeing how many top-level items we have.
      $nTop = 0;
      foreach ($entries as $entry) {
        if ($entry['isTop'] === TRUE) {
          ++$nTop;
        }
      }

      if ($nTop > 1) {
        // There are multiple top-level items. We need a subfolder.
        $subFolderName = $this->getName();
        $lastDotIndex = mb_strrpos($subFolderName, '.');
        if ($lastDotIndex !== FALSE) {
          $subFolderName = mb_substr($subFolderName, 0, $lastDotIndex);
        }

        $subFolder = $topLevelFolder->createFolder($subFolderName);

        // Hereafter, treat the subfolder as the parent folder for the
        // unarchiving.
        $topLevelFolder = $subFolder;
      }
    }

    //
    // Create files and folders
    // ------------------------
    // Loop through the list of files and folders in the archive and
    // create corresponding FolderShare entities for folders, and
    // FolderShare and File entities for files.
    //
    $mapPathToEntity = [];

    try {
      // Loop through all of the entries.
      foreach ($entries as $entry) {
        // Get a few values from the entry.
        $isDirectory = $entry['isDirectory'];
        $zipPath     = $entry['zipPath'];
        $localUri    = $entry['localUri'];
        $zipTime     = $entry['time'];

        // Split the original ZIP path into the parent folder path and
        // the new child's name. For a directory, remember to skip the
        // ending '/'. Note, again, that '/' is the ZIP directory separator,
        // regardless of the separator used by the current OS.
        if ($isDirectory === TRUE) {
          $slashIndex = mb_strrpos($zipPath, '/', -1);
        }
        else {
          $slashIndex = mb_strrpos($zipPath, '/');
        }

        if ($slashIndex === FALSE) {
          // There is no slash. This entry has no parent directory, so
          // use the top-level folder. This entry may be a directory or file.
          $parentEntity = $topLevelFolder;
          $zipName      = $zipPath;
        }
        else {
          // There is a slash. Get the last name and the parent path.
          $parentZipPath = mb_substr($zipPath, 0, $slashIndex);
          $zipName       = mb_substr($zipPath, ($slashIndex + 1));

          // Find the parent entity by looking up the path in the map
          // of previously created entities. Because ZIP entries for
          // folder files always follow entries for their parent folders,
          // we are guaranteed that the parent entity has already been
          // encountered.
          $parentEntity = $mapPathToEntity[$parentZipPath];
        }

        // Create folder or file.
        if ($entry['isDirectory'] === TRUE) {
          // The ZIP entry is for a directory.
          //
          // Create a new folder in the appropriate parent.
          //
          // This function call locks the parent and updates usage tracking.
          $childFolder = $parentEntity->createFolder($zipName);

          if ($zipTime !== 0) {
            $childFolder->setCreatedTime($zipTime);
            $childFolder->setChangedTime($zipTime);
            $childFolder->save();
          }

          // Save that new folder entity back into the map.
          $mapPathToEntity[$zipPath] = $childFolder;
        }
        else {
          // The ZIP entry is for a file.
          //
          // Move the temporary file to FolderShare's directory tree and
          // wrap it with a new File entity.
          $childFile = self::createFileEntityFromLocalFile(
            $localUri,
            $zipName);

          if ($zipTime !== 0) {
            $childFile->setChangedTime($zipTime);
            $childFile->save();
          }

          try {
            // Add the file to the parent folder, locking the parent folder
            // during the addition and updating usage tracking.
            $parentEntity->addFilesInternal(
              [$childFile],
              TRUE,
              TRUE,
              FALSE,
              TRUE);
          }
          catch (\Exception $e) {
            // On any error, we cannot continue. Delete the orphaned File.
            $childFile->delete();
            throw $e;
          }
        }
      }
    }
    catch (\Exception $e) {
      // On any error, we cannot continue. Delete the temporary directory
      // containing the extracted archive.
      FileUtilities::rrmdir($tempDirUri);
      throw $e;
    }

    // We're done. Delete the temporary directory that used to contain
    // the extracted archive.
    FileUtilities::rrmdir($tempDirUri);
  }

  /**
   * Extracts a local ZIP archive into a local directory.
   *
   * The indicated ZIP archive is un-zipped to extract all of its files
   * into a flat temporary directory. The files are all given simple numeric
   * names, instead of their names in the archive, in order to avoid name
   * changes that result from the current OS not supporting the same name
   * length and character sets used within the ZIP archive.
   *
   * An array is returned that indicates the name of the temporary directory
   * and a mapping from ZIP entries to the numerically-named temporary files
   * in the temporary directory.
   *
   * @param string $archivePath
   *   The local file system path to the ZIP archive to extract.
   * @param string $directoryUri
   *   The URI for a local temp directory into which to extract the ZIP
   *   archive's files and directories.
   *
   * @return array
   *   Returns an array containing one entry for each ZIP archive file or
   *   folder. Entries are associative arrays with the following keys:
   *   - 'isDirectory' - TRUE if the entry is a directory.
   *   - 'zipPath' - the file or directory path in the ZIP file.
   *   - 'localUri' - the file or directory URI in local storage.
   *   - 'time' - the last-modified time in the ZIP file.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   Thrown if a file or directory cannot be created, or if the ZIP
   *   archive is corrupted.
   */
  private static function extractLocalZipFileToLocalDirectory(
    string $archivePath,
    string $directoryUri) {

    //
    // Implementation note:
    //
    // A ZIP archive includes files and directories with relative paths
    // that meet the name constraints on the OS and file system on which
    // the archive was created. So if the original OS only supports ASCII
    // names and 3-letter extensions, that's what will be in the ZIP archive.
    //
    // The ZipArchive class can open an archive, then extract all of it in one
    // operation:
    // @code
    // $archive->extractTo($dir);
    // @endcode
    //
    // This works and it creates a new directory tree under $dir that contains
    // all of the files and subdirectories in the ZIP archive.
    //
    // HOWEVER... the local OS and file system may have different name length
    // and character set limits from that used to create the archive. In a
    // worst case, imagine extracting an archive with long UTF-8 file and
    // directory names into an old DOS file system that requires 8.3 names
    // in ASCII. Rather than fail, ZipArchive will rename the files during
    // extraction.
    //
    // The problem is that we need to know those new file names. We want to
    // create new FolderShare entities that point to them. But extractTo()
    // does not return them.
    //
    // A variant of extractTo() takes two arguments. The first is the
    // directory path for the new files, and the second is the name of the
    // file to extract:
    // @code
    // for ($i = 0; $i < $archive->numFiles; ++$i)
    //   $archive->extractTo($dir, $archive->getNameIndex($i));
    // @endcode
    //
    // HOWEVER... each file extracted by this method is dropped into $dir
    // by APPENDING the internal ZIP file path. So if the internal ZIP path
    // is "mydir/myfile.png" and $dir is "/tmp/stuff", then the file will
    // be dropped into "/tmp/stuff/mydir/myfile.png", but with "mydir" and
    // "myfile.png" adjusted for the local file system and OS limitations.
    //
    // The problem again is that we still don't know the names of the newly
    // created local files. Even though we can specify $dir, we cannot
    // specify the name of the file that is created.
    //
    // THEREFORE... we cannot use extractTo(). This is unfortunate and
    // causes a lot more code here.
    //
    // We can bypass extractTo() by getting a stream from ZipArchive,
    // then reading from that stream directly to copy the archive's
    // contents into a new file we explicitly create and write to.
    //
    if (empty($archivePath) === TRUE || empty($directoryUri) === TRUE) {
      return NULL;
    }

    // Implementation note:
    //
    // ZIP paths always use '/', regardless of the local OS or file system
    // conventions. So, as we parse ZIP paths, we use '/', and not the
    // current OS's DIRECTORY_SEPARATOR.
    //
    //
    // Open archive
    // ------------
    // Create the ZipArchive object and open the archive. The CHECKCONS
    // flag asks that the open perform consistency checks.
    $archive = new \ZipArchive();

    if ($archive->open($archivePath, \ZipArchive::CHECKCONS) !== TRUE) {
      throw new SystemException(
        t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_OPEN));
    }

    $numFiles = $archive->numFiles;

    //
    // Check file name extensions
    // --------------------------
    // If the site is restricting file name extensions, check everything
    // in the archive first to insure that all files are supported. If any
    // are not, stop and report an error.
    $extensionsString = self::getFileAllowedExtensions();
    if (empty($extensionsString) === FALSE) {
      // Extensions are limited.
      $extensions = mb_split(' ', $extensionsString);
      for ($i = 0; $i < $numFiles; $i++) {
        $path = $archive->getNameIndex($i);
        if (FileUtilities::isNameExtensionAllowed($path, $extensions) === FALSE) {
          $archive->close();
          throw new SystemException(
            t(self::EXCEPTION_ARCHIVE_CONTAINS_NAME_EXT_NOT_ALLOWED));
        }
      }
    }

    //
    // Create temp directories
    // -----------------------
    // Sweep through the archive and make a list of directories. For each
    // one, create a corresponding temp directory. To avoid local file
    // system naming problems, use simple numbers (e.g. 0, 1, 2, 3).
    //
    // Implementation note:
    //
    // Each ZIP entry can have its own relative path. That path may include
    // parent directories that do not have their own ZIP entries. So we need
    // to parse out the parent directory path for EVERY entry and insure we
    // create a temp directory for all of them.
    //
    // For each entry, we'll use statIndex() to get these values:
    // - 'name' = the stored path for the file.
    // - 'index' = the index for the entry (equal to $i for this loop).
    // - 'crc' = the CRC (cyclic redundancy check) for the file.
    // - 'size' = the uncompressed file size.
    // - 'mtime' = the modification time stamp.
    // - 'comp_size' = the compressed file size.
    // - 'comp_method' = the compression method.
    // - 'encryption_method' = the encryption method.
    //
    // We do not support encryption, and we rely upon ZipArchive to handle
    // CRC checking and decompression. So the only values we need are:
    // - 'name'
    // - 'mtime'
    //
    // Note that the name returned is in the original file's character
    // encoding, which we don't know and it may not match that of the
    // current OS. We therefore need to attempt to detect the encoding of
    // each name and convert it to our generic UTF-8.
    //
    // Note that the creation time and recent access times are not stored
    // in the OS-independent part of the ZIP archive. They are apparently
    // stored in some OS-specific parts of the archive, but those require
    // PHP 7+ to access, and we cannot count on that.
    $entries = [];
    $counter = 0;

    for ($i = 0; $i < $numFiles; $i++) {
      // Get the next entry's info.
      $stat    = $archive->statIndex($i, \ZipArchive::FL_UNCHANGED);
      $zipPath = $stat['name'];
      $zipTime = isset($stat['mtime']) === FALSE ? 0 : $stat['mtime'];

      // Insure the ZIP file path is in UTF-8.
      $zipPathEncoding = mb_detect_encoding($zipPath, NULL, TRUE);
      if ($zipPathEncoding !== 'UTF-8') {
        $zipPath = mb_convert_encoding($zipPath, 'UTF-8', $zipPathEncoding);
      }

      // Split on the ZiP directory separator, which is always '/'.
      $zipDirs = mb_split('/', $zipPath);

      // For a directory entry, the last character in the name is '/' and
      // the last name in $dirs is empty.
      //
      // For a file entry, the last character in the name is not '/' and
      // the last name in $dirs is the file name.
      //
      // In both cases, we don't need the last entry since we are only
      // interested in all of the parent directories.
      unset($zipDirs[(count($zipDirs) - 1)]);

      // Loop through the directories on the ZIP file's path and create
      // any we haven't encountered before.
      $zipPathSoFar = '';
      $dirUriSoFar = $directoryUri;

      foreach ($zipDirs as $dir) {
        // Append the next dir to our ZIP path so far.
        if ($zipPathSoFar === '') {
          $zipPathSoFar = $dir;
          $isTop = TRUE;
        }
        else {
          $zipPathSoFar .= '/' . $dir;
          $isTop = FALSE;
        }

        if (isset($entries[$zipPathSoFar]) === TRUE) {
          // We've encountered this path before. Update it's saved
          // modification time if it is newer.
          $dirUriSoFar = $entries[$zipPathSoFar]['localUri'];

          if ($zipTime > $entries[$zipPathSoFar]['time']) {
            $entries[$zipPathSoFar]['time'] = $zipTime;
          }
        }
        else {
          // Create the local URI.
          $localUri = $dirUriSoFar . '/' . $counter;
          ++$counter;

          $entries[$zipPathSoFar] = [
            'isDirectory' => TRUE,
            'isTop'       => $isTop,
            'zipPath'     => $zipPathSoFar,
            'localUri'    => $localUri,
            'time'        => $zipTime,
          ];

          FileUtilities::mkdir($localUri);
        }
      }
    }

    //
    // Extract files
    // -------------
    // Sweep through the archive again. Ignore directory entries since
    // we have already handled them above.
    //
    // For each file, DO NOT use extractTo(), since that will create
    // local names we cannot control (see implementation notes earlier).
    // Instead, open a stream for each file and copy from the stream
    // into a file we create here with a name we can control and save.
    for ($i = 0; $i < $numFiles; $i++) {
      // Get the next entry's info.
      $stat    = $archive->statIndex($i, \ZipArchive::FL_UNCHANGED);
      $zipPath = $stat['name'];
      $zipTime = isset($stat['mtime']) === FALSE ? 0 : $stat['mtime'];

      // Insure the ZIP file path is in UTF-8.
      $zipPathEncoding = mb_detect_encoding($zipPath, NULL, TRUE);
      if ($zipPathEncoding !== 'UTF-8') {
        $zipPath = mb_convert_encoding($zipPath, 'UTF-8', $zipPathEncoding);
      }

      if ($zipPath[(mb_strlen($zipPath) - 1)] === '/') {
        // Paths that end in '/' are directories. Already handled.
        continue;
      }

      // Get the parent ZIP directory path.
      $parentZipPath = FileUtilities::dirname($zipPath);

      // Get the local temp directory URI for this path, which we have
      // created earlier.
      if ($parentZipPath === '.') {
        // The $zipPath had no parent directories. Drop the file into
        // the target directory.
        $parentLocalUri = $directoryUri;
        $isTop = TRUE;
      }
      else {
        // Get the name of the temp directory we created earlier for
        // this parent path.
        $parentLocalUri = $entries[$parentZipPath]['localUri'];
        $isTop = FALSE;
      }

      // Create a name for a local file in the parent directory. We'll
      // be writing the ZIP archive's uncompressed file here.
      $localUri = $parentLocalUri . '/' . $counter;
      ++$counter;

      // Get an uncompressed byte stream for the file.
      $stream = $archive->getStream($zipPath);

      // Create a local file. We can use the local URI because fopen()
      // is stream-aware and will track the scheme down to Drupal and
      // its installed stream wrappers.
      $fp = fopen($localUri, 'w');
      if ($fp === FALSE) {
        $archive->close();
        throw new SystemException(
          t(self::EXCEPTION_SYSTEM_FILE_CANNOT_CREATE));
      }

      while (feof($stream) !== TRUE) {
        fwrite($fp, fread($stream, 8192));
      }

      fclose($fp);
      fclose($stream);

      // Give the new file appropriate permissions.
      FileUtilities::chmod($localUri);

      // Set the new file's modification time.
      if ($zipTime !== 0) {
        FileUtilities::touch($localUri, $zipTime);
      }

      $entries[$zipPath] = [
        'isDirectory' => FALSE,
        'isTop'       => $isTop,
        'zipPath'     => $zipPath,
        'localUri'    => $localUri,
        'time'        => $zipTime,
      ];
    }

    if ($archive->close() === FALSE) {
      throw new SystemException(
        t(self::EXCEPTION_SYSTEM_ARCHIVE_CANNOT_READ));
    }

    return $entries;
  }

  /*---------------------------------------------------------------------
   *
   * MIME utilities.
   *
   * These functions handle MIME types.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns TRUE if a MIME type refers to an image type.
   *
   * @param string $mimeType
   *   The MIME type to check.
   *
   * @return bool
   *   Returns TRUE if the MIME type is for an image, and FALSE otherwise.
   */
  private static function isMimeTypeImage(string $mimeType) {
    // MIME types are a concatenation of a top-level type name, a "/",
    // and a subtype name with an optional prefix, suffix, and parameters.
    // The top-level type name is one of several well-known names:
    //
    // - application.
    // - audio.
    // - example.
    // - font.
    // - image.
    // - message.
    // - model.
    // - multipart.
    // - text.
    // - video.
    //
    // This function returns TRUE if the top-level type name is 'image'.
    list($topLevel,) = explode('/', $mimeType, 2);
    return ($topLevel === 'image');
  }

  /*---------------------------------------------------------------------
   *
   * File utilities.
   *
   * These functions handle quirks of the File module.
   *
   *---------------------------------------------------------------------*/

  /**
   * Creates a File entity for an existing local file.
   *
   * A new File entity is created using the existing file, and named using
   * the given file name. In the process, the file is moved into the proper
   * FolderShare directory tree and the stored file renamed using the
   * module's numeric naming scheme.
   *
   * The filename, MIME type, and file size are set, the File entity is
   * marked as a permanent file, and the file's permissions are set for
   * access by the web server.
   *
   * @param string $uri
   *   The URI to a stored file on the local file system.
   * @param string $name
   *   The publically visible file name for the new File entity. This should
   *   be a legal file name, without a preceding path or scheme. The MIME
   *   type of the new File entity is based on this name.
   *
   * @return \Drupal\file\FileInterface
   *   Returns a new File entity with the given file name, and a properly
   *   set MIME type and size. The entity is owned by the current user.
   *   The File's URI points to a FolderShare directory tree file moved
   *   from the given local path. A NULL is returned if the local path is
   *   empty.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   Thrown if an error occurs when trying to create any file or directory
   *   or move the local file to the proper directory.
   */
  private static function createFileEntityFromLocalFile(
    string $uri,
    string $name = NULL) {

    // If the path is empty, there is no file to create.
    if (empty($uri) === TRUE || empty($name) === TRUE) {
      return NULL;
    }

    //
    // Setup
    // -----
    // Get the new file's MIME type and size.
    $mimeType = FileUtilities::guessMimeType($name);
    $fileSize = FileUtilities::filesize($uri);

    //
    // Create initial File entity
    // --------------------------
    // Create a File object that wraps the file in its current location.
    // This is not the final File object, which must be adjusted by moving
    // the file from its current location to FolderShare's directory tree.
    //
    // The status of 0 marks the file as temporary. If left this way, the
    // File module will automatically delete the file in the future.
    $file = File::create([
      'uid'      => \Drupal::currentUser()->id(),
      'uri'      => $uri,
      'filename' => $name,
      'filemime' => $mimeType,
      'filesize' => $fileSize,
      'status'   => 0,
    ]);

    if ($file === FALSE) {
      // The file create failed.
      throw new SystemException(
        t(self::EXCEPTION_SYSTEM_FILE_CANNOT_CREATE));
    }

    $file->save();

    //
    // Move file and update File entity
    // --------------------------------
    // Creating the File object assigns it a unique ID. We need this ID
    // to create a new long-term local file name and location when the
    // file is in its proper location in the FolderShare directory tree.
    //
    // Create the new proper file URI with a numeric ID-based name and path.
    $storedUri = FileUtilities::getModuleDataFileUri($file);

    // Move the stored file from its current location to the FolderShare
    // directory tree and rename it using the File entity's ID. This updates
    // the File entity.
    //
    // Moving the file also changes the file name and MIME type to values
    // based on the new URI. This is not what we want, so we'll have to
    // fix this below.
    $newFile = file_move($file, $storedUri, FILE_EXISTS_REPLACE);

    if ($newFile === FALSE) {
      // The file move has failed.
      $file->delete();
      throw new SystemException(
        t(self::EXCEPTION_SYSTEM_FILE_CANNOT_MOVE));
    }

    // Mark the file permanent and fix the name and MIME type.
    $newFile->setPermanent();
    $newFile->setFilename($name);
    $newFile->setFileUri(FileUtilities::getModuleDataFileUri($newFile));
    $newFile->setMimeType($mimeType);
    $newFile->save();

    return $newFile;
  }

  /**
   * Duplicates a File object.
   *
   * This method is private. It is used by copyToFolderInternal().
   *
   * The file is duplicated, creating a new copy on the local file system.
   *
   * Exceptions are very unlikely and should only occur when something
   * catastrophic happens to the underlying file system, such as if it
   * runs out of space, if someone deletes key directories, or if the
   * file system goes offline.
   *
   * <B>Usage tracking:</B>
   * Usage tracking is not updated. This is up to the caller to do.
   *
   * <B>Access control:</B>
   * The caller MUST have checked and confirmed access controls
   * BEFORE calling this method.
   *
   * <B>Locking:</B>
   * This function does not lock any folders. This is up to the caller to do.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to copy.
   *
   * @return \Drupal\file\FileInterface
   *   The new file copy.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   If a serious system error occurred, such as a file system
   *   becoming unreadable/unwritable, getting full, or going offline.
   *
   * @see ::copyAndAddFilesInternal()
   */
  private static function duplicateFileEntityInternal(FileInterface $file) {
    //
    // Implementation note:
    //
    // The File module's file_copy() will copy a File object into a new
    // File object with a new URI for the file name.  However, our
    // file naming scheme for local storage file names uses the object's
    // entity ID, and we don't know that before calling file_copy().
    //
    // So, this function calls file_copy() to copy the given file into a
    // temp file, and then calls file_move() to move the temp file into
    // a file with the proper entity ID-based name.
    //
    // Complicating things, file_copy() and file_move() both invoke
    // hook functions that can rename the file, which is not appropriate
    // here. So, after moving the file, the file name is restored to
    // something reasonable.
    //
    // Furthermore, file names used by this module do not have file name
    // extensions (due to security and work-around quirks in the
    // File module's handling of extensions). To get the right MIME
    // type for the new File, this function explicitly copies it from
    // the previous File object.
    if ($file === NULL) {
      return NULL;
    }

    // Copy the file into a temp location.
    //
    // Allow the file to be renamed in order to avoid name collisions
    // with any other temp files in progress.
    $tempFile = file_copy(
      $file,
      FileUtilities::createLocalTempFile(),
      FILE_EXISTS_RENAME);

    if ($tempFile === FALSE) {
      // Unfortunately, file_copy returns FALSE on an error
      // and provides no further details to us on the problem.
      // Instead, it writes a message to the log file and/or
      // to the output page, which is not useful to us or
      // meaningful to the user.
      //
      // Looking at the source code, the following types of
      // errors could occur:
      // - The source file doesn't exist
      // - The destination directory doesn't exist
      // - The destination directory can't be written
      // - The destination filename is in use
      // - The source and destination are the same
      // - Some other error occurred (probably a system error)
      //
      // Since the directory and file names are under our control
      // and are valid, the only errors that can occur here are
      // catastrophic, such as:
      // - File deleted out from under us
      // - File system changed out from under Drupal
      // - File system full, offline, hard disk dead, etc.
      //
      // For any of these, it is unlikely we can continue with
      // copying anything. Time to abort.
      throw new SystemException(t(self::EXCEPTION_SYSTEM_FILE_COPY));
    }

    // The File copy's fields are mostly correct:
    // - 'filesize' matches the original file's size.
    // - 'uid' is for the current user.
    // - 'fid', 'uuid', 'created', and 'changed' are new and uptodate.
    // - 'langcode' is at a default.
    // - 'status' is permanent.
    //
    // The following fields need correcting:
    // - 'uri' and 'filename' are for a temp file.
    // - 'filemime' is empty since the file has no extension.
    //
    // The URI is fixed now by moving the file.
    //
    // With an entity ID for the new file, create the correct URI
    // and move the file there.
    $newFile = file_move(
      $tempFile,
      FileUtilities::getModuleDataFileUri($tempFile),
      FILE_EXISTS_REPLACE);

    if ($newFile === FALSE) {
      // See the above comment about ways file_copy() can fail.
      // The same applies here.
      $tempFile->delete();
      throw new SystemException(t(self::EXCEPTION_SYSTEM_FILE_COPY));
    }

    // Change the copied file's user-visible file name to match the
    // original file's name. This does not change the name on disk.
    //
    // Change the copied file's MIME type to match the original file's
    // MIME type.
    $newFile->setFilename($file->getFilename());
    $newFile->setFileUri(FileUtilities::getModuleDataFileUri($newFile));
    $newFile->setMimeType($file->getMimeType());
    $newFile->save();

    return $newFile;
  }

  /**
   * {@inheritdoc}
   */
  public function addUploadFiles(
    string $formFieldName,
    bool $allowRename = TRUE) {

    static $uploadCache;
    //
    // Drupal's file_save_upload() function is widely used for handling
    // uploaded files. It does several activities at once:
    //
    // - 1. Collect all uploaded files.
    // - 2. Validate that the uploads completed.
    // - 3. Run plug-in validators.
    // - 4. Optionally check for file name extensions.
    // - 5. Add ".txt" on executable files.
    // - 6. Rename inner extensions (e.g. ".tar.gz" -> "._tar.gz").
    // - 7. Validate file names are not too long.
    // - 8. Validate the destination exists.
    // - 9. Optionally rename files to avoid destination collisions.
    // - 10. chmod the file to be accessible.
    // - 11. Create a File object.
    // - 12. Set the File object's URI, filename, and MIME type.
    // - 13. Optionally replace a prior File object with a new file.
    // - 14. Save the File object.
    // - 15. Cache the File objects.
    //
    // There are several problems, though.
    //
    // - The plug-in validators in (3) are not needed by us.
    //
    // - The extension checking in (4) can only be turned off for
    //   the first file checked, due to a bug in the current code.
    //
    // - The extension changes in (5) and (6) are mandatory, but
    //   nonsense when we'll be storing files without extensions.
    //
    // - The file name length check in (7) uses PHP functions that
    //   are not multi-byte character safe, and it limits names to
    //   240 characters, independent of the actual field length.
    //
    // - The file name handling loses the original file name that
    //   we need to maintain and show to users.
    //
    // - The file movement can't leave the file in our desired
    //   destination directory because that directory's name is a
    //   function of the entity ID, which isn't known until after
    //   file_save_upload() has created the file and moved it to
    //   what it thinks is the final destination.
    //
    // - Any errors generated are logged and reported directly to
    //   to the user with drupal_set_message(). No exceptions
    //   are thrown. The only error indicator returned to the caller
    //   is that the array of returned File objects can include a
    //   FALSE for a file that failed. But there is no indication
    //   about which file it was that failed, or why.
    //
    // THIS function repeats some of the steps in file_save_upload(),
    // but skips ones we don't need. It also keeps track of errors and
    // returns error messages instead of blurting them out to the user.
    //
    // Validate inputs
    // ---------------
    // Validate uploads exist and that they are for this field.
    if (empty($formFieldName) === TRUE) {
      // No field to get files for.
      return [];
    }

    // Get a list of all uploaded files, across all ongoing activity
    // for all uploads of any type.
    $allFiles = \Drupal::request()->files->get('files', []);

    // If there is nothing for the requested form field, return.
    if (isset($allFiles[$formFieldName]) === FALSE) {
      return [];
    }

    // If the file list for the requested form field is empty, return.
    $filesToProcess = $allFiles[$formFieldName];
    unset($allFiles);
    if (empty($filesToProcess) === TRUE) {
      return [];
    }

    // If there is just one item, turn it into an array of items
    // to simplify further code.
    if (is_array($filesToProcess) === FALSE) {
      $filesToProcess = [$filesToProcess];
    }

    //
    // Cache shortcut
    // --------------
    // It is conceivable that this function gets called multiple
    // times on the same form. To avoid redundant processing,
    // check a cache of recently uploaded files and return from
    // that cache quickly if possible.
    //
    // The cache will be cleared and set to a new list of files
    // at the end of this function.
    if (isset($uploadCache[$formFieldName]) === TRUE) {
      return $uploadCache[$formFieldName];
    }

    //
    // Validate upload success
    // -----------------------
    // Loop through the available uploaded files and separate out the
    // ones that failed, along with an error message about why it failed.
    $goodFiles = [];
    $failedMessages = [];

    foreach ($filesToProcess as $index => $fileInfo) {
      if ($fileInfo === NULL) {
        // Odd. A file is listed in the uploads, but it isn't really there.
        $failedMessages[$index] = (string) t(
          'The file @index-th uploaded file could not be found. This appears to be a system error. Please try again.',
          ['@index' => $index]);
        continue;
      }

      $filename = $fileInfo->getClientOriginalName();

      // Check for errors. On any error, create an error message
      // and add it to the messages array. If the error is very
      // severe, also log it.
      switch ($fileInfo->getError()) {
        case UPLOAD_ERR_INI_SIZE:
          // Exceeds max PHP size.
        case UPLOAD_ERR_FORM_SIZE:
          // Exceeds max form size.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because it exceeds the web site\'s maximum allowed file size of @maxsize.',
            [
              '@file'    => $filename,
              '@maxsize' => format_size(file_upload_max_size()),
            ]);
          break;

        case UPLOAD_ERR_PARTIAL:
          // Uploaded only partially uploaded.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because the uploaded was interrupted and only part of the file was received.',
            ['@file' => $filename]);
          break;

        case UPLOAD_ERR_NO_FILE:
          // Upload wasn't started.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because its inclusion exceeds the web site\'s maximum allowed number of file uploads at one time.',
            ['@file' => $filename]);
          break;

        case UPLOAD_ERR_NO_TMP_DIR:
          // No temp directory configured.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because the web site encountered a site configuration error.',
            ['@file' => $filename]);
          \Drupal::logger(Constants::MODULE)->emergency(
            'File upload failed because the PHP temporary directory is missing!');
          break;

        case UPLOAD_ERR_CANT_WRITE:
          // Temp directory not writable.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because the web site encountered a site configuration error.',
            ['@file' => $filename]);
          \Drupal::logger(Constants::MODULE)->emergency(
            'File upload failed because the PHP temporary directory is not writable!');
          break;

        case UPLOAD_ERR_EXTENSION:
          // PHP extension failed for some reason.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because the web site encountered a site configuration error.',
            ['@file' => $filename]);
          \Drupal::logger(Constants::MODULE)->error(
            'File upload failed because a PHP extension failed for an unknown reason.');
          break;

        case UPLOAD_ERR_OK:
          // Success!
          if (is_uploaded_file($fileInfo->getRealPath()) === FALSE) {
            // But the file doesn't actually exist!
            $failedMessages[$index] = (string) t(
              'The file "@file" could not be added to the folder because the data was lost during the upload.',
              ['@file' => $filename]);
            \Drupal::logger(Constants::MODULE)->error(
              'File upload failed because the uploaded file went missing after the upload completed.');
          }
          else {
            $goodFiles[$index] = $fileInfo;
          }
          break;

        default:
          // Unknown error.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because of an unknown problem.',
            ['@file' => $filename]);
          \Drupal::logger(Constants::MODULE)->warning(
            'File upload failed with an unrecognized error code "' .
            $fileInfo->getError() . '".');
          break;
      }
    }

    unset($filesToProcess);

    //
    // Validate names
    // --------------
    // Check that all of the original file names are legal for storage
    // in this module. This checks file name length and character content,
    // and allows for multi-byte characters.
    $passedFiles = [];

    foreach ($goodFiles as $index => $fileInfo) {
      $filename = $fileInfo->getClientOriginalName();

      if (FileUtilities::isNameLegal($filename) === FALSE) {
        $failedMessages[$index] = (string) t(
          'The file "@file" could not be added to the folder because it\'s name must be between 1 and 255 characters long and the name cannot use ":", "/", or "\\" characters.',
          ['@file' => $filename]);
      }
      else {
        $passedFiles[$index] = $fileInfo;
      }
    }

    // And reduce the good files list to the ones that passed.
    $goodFiles = $passedFiles;
    unset($passedFiles);

    // If there are no good files left, return the errors.
    if (empty($goodFiles) === TRUE) {
      $uploadCache[$formFieldName] = $failedMessages;
      return $failedMessages;
    }

    //
    // Validate extensions
    // -------------------
    // The folder's 'file' field contains the allowed filename extensions
    // for this site. If the list is empty, do not do extension checking.
    //
    // Note that we specifically DO NOT do some of the extension handling
    // found in file_save_upload():
    //
    // - We do not add ".txt" to the end of executable files
    //   (.php, .pl, .py, .cgi, .asp, and .js). This was intended
    //   to protect web servers from unintentionally executing
    //   uploaded files. However, for this module all uploaded files
    //   will stored without extensions, so this is not necessary.
    //
    // - We do not replace inner extensions (e.g. "archive.tar.gz")
    //   with a "._". Again, this was intended to protect web servers
    //   from falling back from the last extension to an inner
    //   extension and, again, unintentionally executing uploaded
    //   files. However, for this module all uploaded files will be
    //   stored without extensions, so this is not necessary.
    $extensionsString = self::getFileAllowedExtensions();
    if (empty($extensionsString) === FALSE) {
      // Break up the extensions.
      $extensions = mb_split(' ', $extensionsString);

      // Loop through the good files again and split off the
      // ones with good extensions.
      $passedFiles = [];

      foreach ($goodFiles as $index => $fileInfo) {
        $filename = $fileInfo->getClientOriginalName();

        if (FileUtilities::isNameExtensionAllowed($filename, $extensions) === FALSE) {
          // Extension is not allowed.
          $failedMessages[$index] = (string) t(
            'The file "@file" could not be added to the folder because it uses a file name extension that is not allowed by this web site.',
            ['@file' => $filename]);
        }
        else {
          $passedFiles[$index] = $fileInfo;
        }
      }

      // And reduce the good files list to the ones that passed.
      $goodFiles = $passedFiles;
      unset($passedFiles);

      // If there are no good files left, return the errors.
      if (empty($goodFiles) === TRUE) {
        $uploadCache[$formFieldName] = $failedMessages;
        return $failedMessages;
      }
    }

    //
    // Process files
    // -------------
    // At this point we have a list of uploaded files that all exist
    // on the server and have acceptable file name lengths and extensions.
    // We can now try to create File objects.
    //
    // Get the current user. They will be the owner of the new files.
    $user = \Drupal::currentUser();
    $uid = $user->id();

    // Get the file system service.
    $fileSystem = \Drupal::service('file_system');

    // Get the MIME type service.
    $mimeGuesser = \Drupal::service('file.mime_type.guesser');

    // Loop through the files and create initial File objects.  Move
    // each file into the Drupal temporary directory.
    $fileObjects = [];
    foreach ($goodFiles as $index => $fileInfo) {
      $filename   = $fileInfo->getClientOriginalName();
      $filemime   = $mimeGuesser->guess($filename);
      $filesize   = $fileInfo->getSize();
      $uploadPath = $fileInfo->getRealPath();
      $tempUri    = file_destination(
        'temporary://' . $filename,
        FILE_EXISTS_RENAME);

      // Move file to Drupal temp directory.
      //
      // The file needs to be moved out of PHP's temporary directory
      // into Drupal's temporary directory.
      //
      // PHP's move_uploaded_file() can do this, but it doesn't
      // handle Drupal streams. So use Drupal's file system for this.
      //
      // Let the URI get changed to avoid collisions. This does not
      // affect the user-visible file name.
      if ($fileSystem->moveUploadedFile($uploadPath, $tempUri) === FALSE) {
        // Failure likely means a site problem, such as a bad
        // file system, full disk, etc.  Try to keep going with
        // the rest of the files.
        $drupalTemp = file_directory_temp();
        $failedMessages[$index] = (string) t(
          'The file "@file" could not be added to the folder because the web site encountered a site configuration error.',
          ['@file' => $filename]);
        \Drupal::logger(Constants::MODULE)->emergency(
          'File upload failed because Drupal temporary directory "' .
          $drupalTemp . '" is not available!');
        continue;
      }

      // Set permissions.  Make the file accessible to the web server, etc.
      FileUtilities::chmod($tempUri);

      // Create a File object. Make it owned by the current user. Give
      // it the temp URI, file name, MIME type, etc. A status of 0 means
      // the file is temporary still.
      $file = File::create([
        'uid'      => $uid,
        'uri'      => $tempUri,
        'filename' => $filename,
        'filemime' => $filemime,
        'filesize' => $filesize,
        'status'   => 0,
        'source'   => $formFieldName,
      ]);

      // Save!  Saving the File object assigns it a unique entity ID.
      $file->save();
      $fileObjects[$index] = $file;
    }

    unset($goodFiles);

    // If there are no good files left, return the errors.
    if (empty($fileObjects) === TRUE) {
      $uploadCache[$formFieldName] = $failedMessages;
      return $failedMessages;
    }

    //
    // Move into local directory
    // -------------------------
    // This module manages files within a directory tree built from
    // the entity ID.  This entity ID is not known until after the
    // File object is done.  So we now need another pass through the
    // File objects to use their entity IDs and move the files to their
    // final destinations.
    //
    // Along the way we also mark the file as permanent and attach it
    // to this folder.
    $movedObjects = [];

    foreach ($fileObjects as $index => $file) {
      // Create the final destination URI. This is the URI that uses
      // the entity ID.
      $finalUri = FileUtilities::getModuleDataFileUri($file);

      // Move it there. The directory will be created automatically
      // if needed.
      $newFile = file_move($file, $finalUri, FILE_EXISTS_REPLACE);

      if ($newFile === FALSE) {
        // Unfortunately, file_move() just returns FALSE on an error
        // and provides no further details to us on the problem.
        // Instead, it writes a message to the log file and/or
        // to the output page, which is not useful to us or
        // meaningful to the user.
        //
        // Looking at the source code, the following types of
        // errors could occur:
        // - The source file doesn't exist
        // - The destination directory doesn't exist
        // - The destination directory can't be written
        // - The destination filename is in use
        // - The source and destination are the same
        // - Some other error occurred (probably a system error)
        //
        // Since the directory and file names are under our control
        // and are valid, the only errors that can occur here are
        // catastrophic, such as:
        // - File deleted out from under us
        // - File system changed out from under Drupal
        // - File system full, offline, hard disk dead, etc.
        //
        // For any of these, it is unlikely we can continue with
        // anything.
        $failedMessages[$index] = (string) t(
          'The file "@file" could not be added to the folder because the web site encountered a system failure.',
          ['@file' => $filename]);
        $file->delete();
      }
      else {
        // On success, $file has already been deleted and we now
        // need to use $newFile.
        $movedObjects[$index] = $newFile;

        // Mark it permanent.
        $newFile->setPermanent();
        $newFile->save();
      }
    }

    // And reduce the good files list to the ones that go moved.
    $fileObjects = $movedObjects;
    unset($movedObjects);

    // If there are no good files left, return the errors.
    if (empty($fileObjects) === TRUE) {
      $uploadCache[$formFieldName] = $failedMessages;
      return $failedMessages;
    }

    //
    // Add to folder
    // -------------
    // At this point, $fileObjects contains a list of fully-created
    // File objects for files that have already been moved into their
    // correct locations. Add them to the folder!
    try {
      // Add them. Watch for bad names or name collisions and rename
      // if needed. Don't bother checking if the files are already in
      // the folder since we know they aren't. Do lock.
      //
      // This function call updates usage tracking.
      $this->addFilesInternal($fileObjects, TRUE, $allowRename, FALSE, TRUE);
    }
    catch (\Exception $e) {
      // The add can fail if:
      // - A file name is illegal (but we already checked).
      //
      // - A unique name could not be created because $allowRename was FALSE.
      //
      // - A folder lock could not be acquired.
      //
      // On any failure, none of the files have been added.
      foreach ($fileObjects as $index => $file) {
        $filename = $file->getFilename();
        $failedMessages[$index] = (string) t(
          'The file "@file" could not be added to the folder because the folder is locked for exclusive use by another user.',
          ['@file' => $filename]);
        $file->delete();
      }

      $uploadCache[$formFieldName] = $failedMessages;
      return $failedMessages;
    }

    //
    // Cache and return
    // ----------------
    // $fileObjects contains the new File objects, indexed by the original
    // upload indexes.
    //
    // $failedMessages contains text messages for all failures, indexed
    // by the original upload indexes.
    //
    // Merge these.  We cannot use PHP's array_merge() because it will
    // renumber the entries.
    $result = $fileObjects;
    foreach ($failedMessages as $index => $message) {
      $result[$index] = $message;
    }

    $uploadCache[$formFieldName] = $result;
    return $result;
  }

  /**
   * Reads PHP input into a new temporary file.
   *
   * PHP's input stream is opened and read to acquire incoming data
   * to route into a new Drupal temporary file. The URI of the new
   * file is returned.
   *
   * @return string
   *   The URI of the new temporary file.
   *
   * @throws \Drupal\foldershare\Entity\Exception\SystemException
   *   Throws an exception if one of the following occurs:
   *   - The input stream cannot be read.
   *   - A temporary file cannot be created.
   *   - A temporary file cannot be written.
   */
  private static function inputDataToFile() {
    //
    // Open stream
    // -----------
    // Use the "php" stream to access incoming data appended to the
    // current HTTP request. The stream is opened for reading in
    // binary (the binary part is required for Windows).
    $stream = fopen('php://input', 'rb');
    if ($stream === FALSE) {
      throw new SystemException(t(self::EXCEPTION_SYSTEM_NO_INPUT_STREAM));
    }

    //
    // Create temp file
    // ----------------
    // Use the Drupal "temproary" stream to create a temporary file and
    // open it for writing in binary.
    $fileSystem = \Drupal::service('file_system');
    $tempUri = $fileSystem->tempnam('temporary://', 'file');
    $temp = fopen($tempUri, 'wb');
    if ($temp === FALSE) {
      fclose($stream);
      throw new SystemException(t(self::EXCEPTION_SYSTEM_CANNOT_CREATE_TEMP_FILE));
    }

    //
    // Copy stream to file
    // -------------------
    // Loop through the input stream until EOF, copying data into the
    // temporary file.
    while (feof($stream) === FALSE) {
      $data = fread($stream, 8192);

      if ($data === FALSE) {
        // We aren't at EOF, but the read failed. Something has gone wrong.
        fclose($stream);
        fclose($temp);
        throw new SystemException(t(self::EXCEPTION_SYSTEM_CANNOT_READ_INPUT_STREAM));
      }

      if (fwrite($temp, $data) === FALSE) {
        fclose($stream);
        fclose($temp);
        throw new SystemException(t(self::EXCEPTION_SYSTEM_CANNOT_WRITE_FILE));
      }
    }

    //
    // Clean up
    // --------
    fclose($stream);
    fclose($temp);

    return $tempUri;
  }

  /**
   * {@inheritdoc}
   */
  public function addInputFile(string $filename, bool $allowRename = TRUE) {
    //
    // Validate
    // --------
    // There must be a file name.
    if (empty($filename) === TRUE) {
      throw new ValidationException(t(self::EXCEPTION_NAME_INVALID));
    }

    //
    // Read data into file
    // -------------------
    // Read PHP input into a local temporary file. This throws an exception
    // if the input stream cannot be read or a temporary file created.
    $tempUri = self::inputDataToFile();

    //
    // Create a File entity
    // --------------------
    // Create a File entity that wraps the given file. This moves the file
    // into the module's directory tree. An exception is thrown the file
    // cannot be moved.
    $file = self::createFileEntityFromLocalFile($tempUri, $filename);

    //
    // Add file to folder
    // ------------------
    // Add the File entity to this folder, adjusting the file name if
    // needed.
    return $this->addFile($file, $allowRename);
  }

}
