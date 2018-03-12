<?php

namespace Drupal\foldershare\Plugin\rest\resource;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\Unicode;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\File\FileSystem;

use Drupal\user\Entity\User;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\Plugin\rest\resource\EntityResourceValidationTrait;
use Drupal\rest\RequestHandler;

use Psr\Log\LoggerInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;

use Drupal\foldershare\Settings;
use Drupal\foldershare\FileUtilities;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Entity\FolderShareUsage;
use Drupal\foldershare\Entity\Exception\ValidationException;
use Drupal\foldershare\Entity\Exception\NotFoundException;
use Drupal\foldershare\Entity\Exception\LockException;
use Drupal\foldershare\Entity\Exception\SystemException;
use Drupal\foldershare\Plugin\rest\resource\UncacheableResponse;

/**
 * Responds to FolderShare entity REST GET, POST, PATCH, and DELETE requests.
 *
 * This REST resource provides a web services response for operations
 * related to FolderShare entities. This includes responses to the following
 * standard HTTP requests:
 * - GET: Get a FolderShare entity, list of entities, state, or module settings.
 * - POST: Create a FolderShare entity.
 * - PATCH: Modify a FolderShare entity's fields or sharing settings.
 * - DELETE: Delete a FolderShare entity.
 *
 * FolderShare entities are hierarchical and create a tree of files and
 * folders nested within other folders to arbitrary depth (see the
 * FolderShare entity). Working with this hierarchical necessarily requires
 * that GET operations go beyond requesting a single entity's values based
 * upon that single entity's ID. Instead, this REST resource supports
 * multiple types of GET operations that can request lists of entities,
 * the descendants or ancestors of an entity, the path to an entity, and
 * the sharing settings for a folder tree. Additional GET operations can
 * trigger a search of a folder tree and get non-entity values, such as
 * module settings and usage. Finally, GET operations can download an
 * entity to the local host.
 *
 * POST operations can create a new folder as a top-level folder, or as a
 * child of a specified folder. POST operations can also upload a file or
 * folder, copy a folder tree, or create an archive from a file or folder tree.
 *
 * PATCH operations can modify the values of an entity, such as its name
 * or description, and also move the entity to a different part of the
 * folder tree.
 *
 * DELETE operations can delete a file or folder, including any child files
 * and folders.
 *
 * <B>Routes and URLs</B><BR>
 * GET, PATCH, and DELETE operations all use a canonical URL that must
 * include an entity ID, even for operations that do not require an entity ID.
 * This is an artifact of the REST module's requirement that they all share
 * the same route, and therefore have the same URL structure.
 *
 * POST operations all use a special creation URL that cannot include an
 * entity ID, even for operations that need one, such as creating a subfolder
 * within a selected parent folder. The lack of an entity ID in the URL is
 * an artifact of the REST module's assumption that entities are not related
 * to each other.
 *
 * Since most of FolderShare's GET, POST, and PATCH operations require
 * additional information beyond what is included (or includable) on the URL,
 * this response needs another mechanism for providing that additional
 * information. While POST can receive posted data, GET and PATCH cannot.
 * This leaves us with two possible design solutions:
 * - Add URL query strings.
 * - Add HTTP headers.
 *
 * Both of these designs are possible, but the use of URL query strings is
 * cumbersome for the range and complexity of the information needed. This
 * design therefore uses several custom HTTP headers to provide
 * this information. Most headers are optional and defaults are used if
 * the header is not available.
 *
 * When paths are used, a path has one of these forms:
 * - "DOMAIN:/PATH" = a path relative to a specific content domain.
 * - "/PATH" = a path relative to the "private" content domain.
 *
 * The following domains are recognized:
 * - "private" = (default) the user's own files and folders.
 * - "public" = the site's public files and folders.
 * - "shared" = files and folders shared with the user.
 *
 * The following minimal paths have special meanings:
 * - "private:/" = the user's own root folders.
 * - "public:/" = the site's public root folders.
 * - "shared:/" = the root folders shared with the user.
 *
 * Some operations require a path to a specific file or folder. In these
 * cases, the operation will return an error with minimal paths that
 * refer to domains of content, rather than a specific entity.
 *
 * <B>Headers</B><BR>
 * Primary headers specify an operation to perform:
 *
 * - X-FolderShare-Get-Operation selects the GET variant, such as whether
 *   to get a single entity, a list of entities, or module settings.
 *
 * - X-FolderShare-Post-Operation selects the POST variant, such as whether
 *   to create a new root folder or subfolder, upload a file, copy a folder
 *   tree, or create an archive.
 *
 * - X-FolderShare-Patch-Operation selects the PATCH variant, such as whether
 *   to modify an entity's fields or move the entity.
 *
 * Many GET, PATCH, and DELETE operations require a source, or subject entity
 * to operate upon. By default, the entity is indicated by a non-negative
 * integer entity ID included on the URL. Alternately, the entity may be
 * specified via a folder path string included in the header:
 *
 * - X-FolderShare-Source-Path includes the URL encoded entity path.
 *   When present, this path overrides the entity ID in the URL.
 *
 * Some PATCH operations copy or move an entity to a new location. That
 * location is always specified via a folder path string in another header:
 *
 * - X-FolderShare-Destination-Path includes the URL encoded entity
 *   path to the destination.
 *
 * GET, POST, and PATCH can return information to the client. The structure
 * of that information may be controlled using:
 *
 * - X-FolderShare-Return-Format selects whether to return a serialized entity,
 *   a simplified key-value pair array, an entity ID, a path, or a "Linux"
 *   formatted result.
 *
 * GET search operations can use a search scope:
 *
 * - X-FolderShare-Search-Scope selects whether a search looks only at entity
 *   names, other fields, and/or file content.
 *
 * <B>Requests and responses</B><BR>
 * The following specific requests and responses are supported:
 *
 * GET entity
 *   - Get a file or folder entity's fields and return them either as a full
 *     entity description (with all of Drupal's nested arrays and subfields)
 *     or as a simplified key-value array.
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Get-Operation = "entity".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - Returns a single entity.
 *
 * GET parent
 *   - Get an entity's parent folder entity fields and return them either as
 *     a full entity description (with all of Drupal's nested arrays and
 *     subfields) or as a simplified key-value array.
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Get-Operation = "parent".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - Returns a single entity.
 *
 * GET root
 *   - Get an entity's root folder entity fields and return them either as
 *     a full entity description (with all of Drupal's nested arrays and
 *     subfields) or as a simplified key-value array.
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Get-Operation = "root".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - Returns a single entity.
 *
 * GET ancestors
 *   - Get a list of ancestor entities, ordered from root to the parent of
 *     a subject entity, and return their entity fields either as full
 *     entities (with all of Drupal's nested arrays and subfields) or as a
 *     simplified key-value array.
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Get-Operation = "ancestors".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - Returns a list of entities on the path from root to entity.
 *
 * GET descendants
 *   - Get a list of child entities of a folder and return their entity
 *     fields either as full entities (with all of Drupal's nested arrays
 *     and subfields) or as a simplified key-value array.
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Get-Operation = "descendants".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *     Minimal domain paths may be used to list root folders.
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - Returns a list of immediate children entities. For special paths,
 *     returns a list of root folder entities.
 *
 * GET sharing
 *   - ??
 *
 * GET search
 *   - ??
 *
 * GET configuration
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Get-Operation = "configuration".
 *   - X-FolderShare-Source-Path is ignored.
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns a nested array of key-value pairs for configuration settings,
 *     including relevant module settings (e.g. file extensions) and site
 *     settings (e.g. maximum upload size).
 *
 * GET usage
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Get-Operation = "usage".
 *   - X-FolderShare-Source-Path is ignored.
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns an array of key-value pairs for the user's current usage
 *     (e.g. number of files, folders, root folders, and bytes used).
 *
 * GET version
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Get-Operation = "version".
 *   - X-FolderShare-Source-Path is ignored.
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns an array of key-value pairs for the module's version number,
 *     and other relevant version numbers.
 *
 * DELETE file
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns NULL.
 *
 * DELETE folder
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns NULL.
 *
 * DELETE folder tree
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns NULL.
 *
 * DELETE file or folder
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns NULL.
 *
 * DELETE file or folder tree
 *   - URL = canonical with entity ID (ID is always ignored).
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format is ignored.
 *   - Returns NULL.
 *
 * POST root folder
 *   - URL = creation with no entity ID.
 *   - X-FolderShare-Post-Operation = "new-rootfolder".
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Source-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain a new entity. The "name" field is required.
 *     All date, ID, and internal fields may not be included.
 *   - Returns a single entity.
 *
 * POST folder
 *   - URL = creation with no entity ID.
 *   - X-FolderShare-Post-Operation = "new-folder".
 *   - X-FolderShare-Destination-Path = parent entity path.
 *   - X-FolderShare-Source-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain a new entity. The "name" field is required.
 *     All date, ID, and internal fields may not be included.
 *   - Returns a single entity.
 *
 * POST file
 *   - URL = creation with no entity ID.
 *   - X-FolderShare-Post-Operation = "new-file".
 *   - X-FolderShare-Destination-Path = parent entity path.
 *   - X-FolderShare-Source-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain a new entity. The "name" field is required.
 *     All date, ID, and internal fields may not be included.
 *   - Multi-part request must include 2nd part with file contents.
 *   - File will be automatically marked as an image or data file.
 *   - Returns a single entity.
 *
 * POST media
 *   - URL = creation with no entity ID.
 *   - X-FolderShare-Post-Operation = "new-media".
 *   - X-FolderShare-Destination-Path = parent entity path.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - ??
 *
 * PATCH entity
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Patch-Operation = "entity".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path is ignored.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain an updated entity. The "name" and "description"
 *     may be set. All date, ID, and internal fields may not be included.
 *   - Returns a single entity.
 *
 * PATCH sharing
 *   - ??
 *
 * PATCH move
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Patch-Operation = "move".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path = entity path. Special path
 *     handling:
 *       - "/" = move to private root folder list.
 *       - "/..." = move to child folder.
 *       - "private:" = move to private root folder list.
 *       - "private:/" = move to private root folder list.
 *       - "private:/..." = move to child folder.
 *       - "shared:" = error.
 *       - "shared:/" = error.
 *       - "shared:/..." = move to child folder.
 *       - "public:" = error.
 *       - "public:/" = error.
 *       - "public:/..." = move to child folder.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain an updated entity. The "name" and "description"
 *     may be set. All date, ID, and internal fields may not be included.
 *   - Returns a single entity.
 *
 * PATCH copy
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Patch-Operation = "copy".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path = entity path. Special path
 *     handling:
 *       - "/" = copy to private root folder list.
 *       - "/..." = copy to child folder.
 *       - "private:" = copy to private root folder list.
 *       - "private:/" = copy to private root folder list.
 *       - "private:/..." = copy to child folder.
 *       - "shared:" = error.
 *       - "shared:/" = error.
 *       - "shared:/..." = copy to child folder.
 *       - "public:" = error.
 *       - "public:/" = error.
 *       - "public:/..." = copy to child folder.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain an updated entity. The "name" and "description"
 *     may be set. All date, ID, and internal fields may not be included.
 *   - Returns a single entity.
 *
 * PATCH archive
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Patch-Operation = "archive".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path = entity path. If not present,
 *     new archive is placed in the same parent folder. Otherwise, the
 *     archive is made a child of the indicated folder.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain an updated entity. The "name" and "description"
 *     may be set. All date, ID, and internal fields may not be included.
 *   - Returns a single entity.
 *
 * PATCH unarchive
 *   - URL = canonical with entity ID.
 *   - X-FolderShare-Patch-Operation = "unarchive".
 *   - X-FolderShare-Source-Path = entity path (overrides URL entity ID).
 *   - X-FolderShare-Destination-Path = entity path. If not present,
 *     archive's contents rae placed in the same parent folder. Otherwise, the
 *     archive's contents rae made children of the indicated folder.
 *   - X-FolderShare-Return-Format = "full" or "keyvalue".
 *   - POST fields contain an updated entity. The "name" and "description"
 *     may be set. All date, ID, and internal fields may not be included.
 *   - Returns a single entity.
 *
 * @internal
 * <B>Collision with default entity response</B><BR>
 * The Drupal REST module automatically creates a resource for every entity
 * type, including FolderShare. However, its implementation presumes simple
 * entities that have no linkage into a hierarchy and no context-sensitive
 * constraints on names and other values. If the default response were used,
 * a malicious user could overwrite internal entity fields (like parent,
 * root, and file IDs) and corrupt the hierarchy.  This class therefore
 * provides a proper REST response for FolderShare entities and insures that
 * the hierarchy is not corrupted.
 *
 * However, we need to insure that the default resource created by the REST
 * module is disabled and <B>not available</B>. If it were available, a site
 * admin could enable it without understanding it's danger.
 *
 * There is no straight-forward way to block a default REST resource. It is
 * created at Drupal boot and added to the REST plugin manager automatically.
 * It remains in the plugin manager even if we define a custom resource for
 * the same entity type. However, we can <B>block the default resource</B>
 * by giving our resource the same plugin ID. This overwrites the default
 * resource's entry in the plugin manager's hash table, leaving only this
 * resource visible.
 *
 * <B>The plugin ID for this resource is therefore: "entity:foldershare"
 * to intentionally overwrite the default resource. This is mandatory!</B>
 *
 * @ingroup foldershare
 *
 * @RestResource(
 *   id    = "entity:foldershare",
 *   label = @Translation("FolderShare files and folders"),
 *   serialization_class = "Drupal\foldershare\Entity\FolderShare",
 *   uri_paths = {
 *     "canonical" = "/foldershare/{id}",
 *     "create" = "/entity/foldershare"
 *   }
 * )
 */
class FolderShareResource extends ResourceBase implements DependentPluginInterface {

  use EntityResourceValidationTrait;

  /*--------------------------------------------------------------------
   *
   * Constants - custom header fields.
   *
   * These header fields are recognized by this resource to selection
   * among different operations available or provide guidance on how
   * to perform an operation or return results.
   *
   *--------------------------------------------------------------------*/

  /**
   * A custom request header specifying a GET operation.
   *
   * Header values are any of those listed in GET_OPERATIONS.
   *
   * The default operation value is "entity".
   *
   * @see self::GET_OPERATIONS
   */
  const HEADER_GET_OPERATION = "X-FolderShare-Get-Operation";

  /**
   * A custom request header specifying a DELETE operation.
   *
   * Header values are any of those listed in DELETE_OPERATIONS.
   *
   * The default operation value is "entity".
   *
   * @see self::DELETE_OPERATIONS
   */
  const HEADER_DELETE_OPERATION = "X-FolderShare-Delete-Operation";

  /**
   * A custom request header specifying a POST operation.
   *
   * Header values are any of those listed in POST_OPERATIONS.
   *
   * The default operation value is "entity".
   *
   * @see self::POST_OPERATIONS
   */
  const HEADER_POST_OPERATION = "X-FolderShare-Post-Operation";

  /**
   * A custom request header specifying a PATCH operation.
   *
   * Header values are any of those listed in PATCH_OPERATIONS.
   *
   * The default operation value is "entity".
   *
   * @see self::PATCH_OPERATIONS
   */
  const HEADER_PATCH_OPERATION = "X-FolderShare-Patch-Operation";

  /**
   * A custom request header specifying a GET's search scope.
   *
   * Header values are one or more of those listed in SEARCH_SCOPES.
   * When multiple values are used, they should be listed and separated
   * by commas.
   *
   * The default search scope is "name, body".
   *
   * @see self::SEARCH_SCOPES
   */
  const HEADER_SEARCH_SCOPE = "X-FolderShare-Search-Scope";

  /**
   * A custom request header specifying a return type.
   *
   * Header values are any of those listed in RETURN_FORMATS.
   *
   * The default return type is "entity".
   *
   * @see self::RETURN_FORMATS
   */
  const HEADER_RETURN_FORMAT = "X-FolderShare-Return-Format";

  /**
   * A custom request header to specify a source entity path.
   *
   * Header values are strings for a root-to-leaf path to a file or
   * folder. Strings must be URL encoded in order for them to include
   * non-ASCII characters in an HTTP header.
   *
   * For operations that operate upon an entity, if the header is present
   * the path is used to find the entity instead of using an entity ID
   * on the route.
   */
  const HEADER_SOURCE_PATH = "X-FolderShare-Source-Path";

  /**
   * A custom request header to specify a destination entity path.
   *
   * Header values are strings for a root-to-leaf path to a file or
   * folder. Strings must be URL encoded in order for them to include
   * non-ASCII characters in an HTTP header.
   *
   * For operations that operate upon an entity, if the header is present
   * the path is used to find the entity instead of using an entity ID
   * on the route.
   */
  const HEADER_DESTINATION_PATH = "X-FolderShare-Destination-Path";

  /*--------------------------------------------------------------------
   *
   * Constants - header values.
   *
   * These are well-known values for the custom header fields above.
   *
   *--------------------------------------------------------------------*/

  /**
   * A list of well-known GET operation names.
   *
   * These names may be used as values for the self::HEADER_GET_OPERATION
   * HTTP header.
   *
   * @see self::HEADER_GET_OPERATION
   * @see self::DEFAULT_GET_OPERATION
   */
  const GET_OPERATIONS = [
    // GET operations that can use an entity ID.
    'get-entity',
    'get-parent',
    'get-root',
    'get-ancestors',
    'get-descendants',
    'get-sharing',
    'get-search',
    'download',

    // GET operations for configuration settings.
    'get-configuration',
    'get-usage',
    'get-version',
  ];

  /**
   * The default GET operation.
   *
   * @see self::GET_OPERATIONS
   */
  const DEFAULT_GET_OPERATION = 'get-entity';

  /**
   * A list of well-known DELETE operation names.
   *
   * These names may be used as values for the self::HEADER_DELETE_OPERATION
   * HTTP header.
   *
   * @see self::HEADER_DELETE_OPERATION
   * @see self::DEFAULT_DELETE_OPERATION
   */
  const DELETE_OPERATIONS = [
    // DELETE operations never take an entity ID, just a path.
    'delete-file',
    'delete-folder',
    'delete-folder-tree',
    'delete-file-or-folder',
    'delete-file-or-folder-tree',
  ];

  /**
   * The default DELETE operation.
   *
   * @see self::DELETE_OPERATIONS
   */
  const DEFAULT_DELETE_OPERATION = 'delete-file';

  /**
   * A list of well-known POST operation names.
   *
   * These names may be used as values for the self::HEADER_POST_OPERATION
   * HTTP header.
   *
   * @see self::HEADER_POST_OPERATION
   * @see self::DEFAULT_POST_OPERATION
   */
  const POST_OPERATIONS = [
    // POST operations that may take an entity ID.
    'new-rootfolder',
    'new-folder',
    'new-file',
    'new-media',
  ];

  /**
   * The default POST operation.
   *
   * @see self::POST_OPERATIONS
   */
  const DEFAULT_POST_OPERATION = 'new-rootfolder';

  /**
   * A list of well-known PATCH operation names.
   *
   * These names may be used as values for the self::HEADER_PATCH_OPERATION
   * HTTP header.
   *
   * @see self::HEADER_PATCH_OPERATION
   * @see self::DEFAULT_PATCH_OPERATION
   */
  const PATCH_OPERATIONS = [
    // PATCH operations that can use an entity ID.
    'update-entity',
    'update-sharing',

    // PATCH operations that need a destination.
    'copy-overwrite',
    'copy-no-overwrite',
    'move-overwrite',
    'move-no-overwrite',
  ];

  /**
   * The default PATCH operation.
   *
   * @see self::PATCH_OPERATIONS
   */
  const DEFAULT_PATCH_OPERATION = 'update-entity';

  /**
   * A list of well-known return types.
   *
   * These types may be used as values for the self::HEADER_RETURN_FORMAT
   * HTTP header that is supported for most GET, POST, and PATCH operations.
   *
   * @see self::HEADER_RETURN_FORMAT
   * @see self::DEFAULT_RETURN_FORMAT
   */
  const RETURN_FORMATS = [
    'full',
    'keyvalue',
  ];

  /**
   * The default return type.
   *
   * @see self::RETURN_FORMATS
   */
  const DEFAULT_RETURN_FORMAT = 'full';

  /**
   * A list of well-known search scopes.
   *
   * These values may be used for the self::HEADER_SEARCH_SCOPE HTTP header
   * that is supported by the GET "search" operation.
   *
   * @see self::HEADER_GET_OPERATION
   * @see self::GET_OPERATIONS
   * @see self::DEFAULT_SEARCH_SCOPE
   */
  const SEARCH_SCOPES = [
    'name',
    'body',
    'file-content',
  ];

  /**
   * The default search scope.
   *
   * @see self::SEARCH_SCOPES
   */
  const DEFAULT_SEARCH_SCOPE = 'name,body';


  /*--------------------------------------------------------------------
   *
   * Fields.
   *
   *--------------------------------------------------------------------*/

  /**
   * The entity type targeted by this resource.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $definition;

  /**
   * The link relation type manager used to create HTTP header links.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $linkRelationTypeManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $typeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The configuration for this REST resource.
   *
   * @var \Drupal\rest\RestResourceConfigInterface
   */
  protected $resourceConfiguration;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /*--------------------------------------------------------------------
   *
   * Construct.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs a Drupal\rest\Plugin\rest\resource\EntityResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the REST
   *   plugin instance.
   * @param string $pluginId
   *   The ID for the REST plugin instance.
   * @param mixed $pluginDefinition
   *   The REST plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $typeManager
   *   The entity type manager.
   * @param array $serializerFormats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Symfony\Component\HttpFoundation\Request $currentRequest
   *   The current HTTP request.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $linkRelationTypeManager
   *   The link relation type manager.
   * @param \Drupal\Core\File\FileSystem $fileSystem
   *   The file system service.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition,
    EntityTypeManagerInterface $typeManager,
    array $serializerFormats,
    LoggerInterface $logger,
    Request $currentRequest,
    PluginManagerInterface $linkRelationTypeManager,
    FileSystem $fileSystem) {

    parent::__construct(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $serializerFormats,
      $logger);

    //
    // Notes:
    // - $configuration is required as an argument to the parent class,
    //   but it is empty. This is *not* the resource configuration.
    //
    // - $serializerFormats is required as an argument to the parent class,
    //   but it lists *all* formats installed at the site, rather than just
    //   those configured as supported by this plugin.
    //
    // Get the FolderShare entity definition. This is needed later
    // to calculate dependencies. Specifically, this resource is dependent
    // upon the FolderShare entity being installed.
    $this->definition = $typeManager->getDefinition(
      FolderShare::ENTITY_TYPE_ID);

    // Get the resource's configuration. The configuration is named
    // after this resource plugin, replacing ':' with '.'.
    $restResourceStorage = $typeManager->getStorage('rest_resource_config');
    $this->resourceConfiguration = $restResourceStorage->load('entity.foldershare');

    // And save information for later.
    $this->typeManager = $typeManager;
    $this->currentRequest = $currentRequest;
    $this->linkRelationTypeManager = $linkRelationTypeManager;
    $this->fileSystem = $fileSystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $pluginId,
    $pluginDefinition) {

    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('entity_type.manager'),
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('plugin.manager.link_relation_type'),
      $container->get('file_system')
    );
  }

  /*--------------------------------------------------------------------
   *
   * Configuration.
   *
   * These methods implement the resource interface to answer queries
   * about the resource and how it may be used.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    if (isset($this->definition) === TRUE) {
      return ['module' => [$this->definition->getProvider()]];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function permissions() {
    // Older versions of Drupal allowed REST implementations to define
    // additional permissions for REST access. Versions of Drupal >= 8.2
    // drop REST-specific entity permissions and instead use the entity's
    // regular permissions.
    //
    // Since the FolderShare module requires Drupal 8.4 or greater, the
    // older REST permissions model is not needed and we return nothing.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getBaseRoute($canonicalPath, $method) {
    switch ($method) {
      default:
      case 'GET':
      case 'PATCH':
      case 'DELETE':
        // These methods use the canonical path, which includes an
        // entity ID in the URL. POST fields should be automatically
        // converted into a dummy entity.
        //
        // So, use the default route handler.
        $route = parent::getBaseRoute($canonicalPath, $method);

        if ($route->getOption('parameters') === FALSE) {
          $parameters = [];
          $parameters[FolderShare::ENTITY_TYPE_ID]['type'] =
            'entity:' . FolderShare::ENTITY_TYPE_ID;
          $route->setOption('parameters', $parameters);
        }
        break;

/* TODO  This won't work yet because 'handleRaw' is a patch to Drupal core
 * TODO  that has not been added to Drupal yet. Without it, we cannot do
 * TODO  file uploads.
      case 'POST':
        // POST uses the create path, which does not include an entity ID
        // in the URL. Some POSTs create new FolderShare entities using
        // values in POST fields (e.g. new folder). But some POSTs need
        // to use the POST fields specially, such as when uploading a
        // file. There is no FolderShare field to automatically set to
        // an uploaded file's URI. That URI actually belongs in a File
        // entity referenced by a FolderShare field. This makes it hard
        // to send an uploaded file and have this resource automatically
        // create that File entity as needed.
        //
        // So, the fix is to use a "raw" handler for the incoming request
        // on POSTs. The raw handler does not try to automatically create
        // a valid dummy entity.
        $route = new Route(
          $canonicalPath,
          [
            '_controller' => RequestHandler::class . '::handleRaw',
          ],
          $this->getBaseRouteRequirements($method),
          [],
          '',
          [],
          // The HTTP method is a requirement for this route.
          [$method]
        );
        break;
*/
    }

    return $route;
  }

  /*--------------------------------------------------------------------
   *
   * Header validation.
   *
   * These methods validate and return custom header values used to
   * select operations and parameters for those operations
   *
   *--------------------------------------------------------------------*/

  /**
   * Gets, validates, and returns an operation from the request header.
   *
   * The self::HEADER_GET_OPERATION header is retrieved. If not found,
   * the default operation is returned. Otherwise the header's operation
   * is validated and returned.
   *
   * An exception is thrown if the operation is not recognized.
   *
   * @param string $header
   *   The name of the HTTP header to check.
   * @param array $allowed
   *   The array of allowed HTTP header values.
   * @param string $default
   *   The default value if the header is not found.
   *
   * @return string
   *   Returns the name of the operation, or the default if no operation
   *   was specified.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a BadRequestHttpException if an unknown operation is found.
   *
   * @see ::getAndValidateGetOperation()
   * @see ::getAndValidatePatchOperation()
   * @see ::getAndValidatePostOperation()
   */
  private function getAndValidateOperation(
    string $header,
    array $allowed,
    string $default) {
    // Get the request's headers.
    $requestHeaders = $this->currentRequest->headers;

    // Get the header. If not found, return the default.
    if ($requestHeaders->has($header) === FALSE) {
      return $default;
    }

    // Get and validate the operation.
    $operation = $requestHeaders->get($header);
    if (is_string($operation) === TRUE &&
        in_array($operation, $allowed, TRUE) === TRUE) {
      return $operation;
    }

    // Fail.
    throw new BadRequestHttpException(t(
      "Communications error with unknown request: \"@operation\".",
      [
        '@operation' => $operation,
      ]));
  }

  /**
   * Gets, validates, and returns the GET operation from the request header.
   *
   * The self::HEADER_GET_OPERATION header is retrieved. If not found,
   * the default operation is returned. Otherwise the header's operation
   * is validated and returned.
   *
   * An exception is thrown if the operation is not recognized.
   *
   * @return string
   *   Returns the name of the operation, or the default if no operation
   *   was specified.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a BadRequestHttpException if an unknown operation is found.
   *
   * @see ::HEADER_GET_OPERATION
   * @see ::GET_OPERATIONS
   * @see ::DEFAULT_GET_OPERATION
   */
  private function getAndValidateGetOperation() {
    return $this->getAndValidateOperation(
      self::HEADER_GET_OPERATION,
      self::GET_OPERATIONS,
      self::DEFAULT_GET_OPERATION);
  }

  /**
   * Gets, validates, and returns the DELETE operation from the request header.
   *
   * The self::HEADER_DELETE_OPERATION header is retrieved. If not found,
   * the default operation is returned. Otherwise the header's operation
   * is validated and returned.
   *
   * An exception is thrown if the operation is not recognized.
   *
   * @return string
   *   Returns the name of the operation, or the default if no operation
   *   was specified.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a BadRequestHttpException if an unknown operation is found.
   *
   * @see ::HEADER_DELETE_OPERATION
   * @see ::DELETE_OPERATIONS
   * @see ::DEFAULT_DELETE_OPERATION
   */
  private function getAndValidateDeleteOperation() {
    return $this->getAndValidateOperation(
      self::HEADER_DELETE_OPERATION,
      self::DELETE_OPERATIONS,
      self::DEFAULT_DELETE_OPERATION);
  }

  /**
   * Gets, validates, and returns the PATCH operation from the request header.
   *
   * The self::HEADER_PATCH_OPERATION header is retrieved. If not found,
   * the default operation is returned. Otherwise the header's operation
   * is validated and returned.
   *
   * An exception is thrown if the operation is not recognized.
   *
   * @return string
   *   Returns the name of the operation, or the default if no operation
   *   was specified.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a BadRequestHttpException if an unknown operation is found.
   *
   * @see ::HEADER_PATCH_OPERATION
   * @see ::PATCH_OPERATIONS
   * @see ::DEFAULT_PATCH_OPERATION
   */
  private function getAndValidatePatchOperation() {
    return $this->getAndValidateOperation(
      self::HEADER_PATCH_OPERATION,
      self::PATCH_OPERATIONS,
      self::DEFAULT_PATCH_OPERATION);
  }

  /**
   * Gets, validates, and returns the POST operation from the request header.
   *
   * The self::HEADER_POST_OPERATION header is retrieved. If not found,
   * the default operation is returned. Otherwise the header's operation
   * is validated and returned.
   *
   * An exception is thrown if the operation is not recognized.
   *
   * @return string
   *   Returns the name of the operation, or the default if no operation
   *   was specified.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a BadRequestHttpException if an unknown operation is found.
   *
   * @see ::HEADER_POST_OPERATION
   * @see ::POST_OPERATIONS
   * @see ::DEFAULT_POST_OPERATION
   */
  private function getAndValidatePostOperation() {
    return $this->getAndValidateOperation(
      self::HEADER_POST_OPERATION,
      self::POST_OPERATIONS,
      self::DEFAULT_POST_OPERATION);
  }

  /**
   * Gets, validates, and returns the return type from the request header.
   *
   * The current request's headers are checked for self::HEADER_RETURN_FORMAT.
   * If the header is found, it's value is validated against a list of
   * well-known return types and returned. If the header is not found,
   * a default return type is returned.
   *
   * @return string
   *   Returns the name of the return type, or the default if no return type
   *   was specified.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a BadRequestHttpException if an unknown return type is found.
   *
   * @see ::HEADER_RETURN_FORMAT
   * @see ::RETURN_FORMATS
   * @see ::DEFAULT_RETURN_FORMAT
   */
  private function getAndValidateReturnFormat() {
    // Get the request's headers.
    $requestHeaders = $this->currentRequest->headers;

    // If no return type is specified, return the default.
    if ($requestHeaders->has(self::HEADER_RETURN_FORMAT) === FALSE) {
      return self::DEFAULT_RETURN_FORMAT;
    }

    // Get and validate the return type.
    $returnFormat = $requestHeaders->get(self::HEADER_RETURN_FORMAT);
    if (is_string($returnFormat) === TRUE &&
        in_array($returnFormat, self::RETURN_FORMATS, TRUE) === TRUE) {
      return $returnFormat;
    }

    // Fail.
    throw new BadRequestHttpException(t(
      "Communications error with an unknown return format: \"@returnFormat\".",
      [
        '@returnFormat' => $returnFormat,
      ]));
  }

  /**
   * Loads and validates access to the indicated entity.
   *
   * @param int $id
   *   The entity ID for a FolderShare entity.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns the loaded entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a NotFoundHttpException if the entity ID is bad, and
   *   an AccessDeniedHttpException if the user does not have access.
   */
  private function loadAndValidateEntity(int $id) {
    // Load the entity using the ID.
    $entity = FolderShare::load($id);
    if ($entity === NULL) {
      throw new NotFoundHttpException(t(
        "A file or folder with the indicated ID could not be found: @id.",
        [
          '@id' => $id,
        ]));
    }

    // Check if the user has 'view' access to the entity.
    $access = $entity->access('view', NULL, TRUE);
    if ($access->isAllowed() === FALSE) {
      // No access. If a reason was not provided, use a default.
      $message = $access->getReason();
      if (empty($message) === TRUE) {
        $message = $this->getDefaultAccessDeniedMessage('view');
      }

      throw new AccessDeniedHttpException($message);
    }

    return $entity;
  }

  /*--------------------------------------------------------------------
   *
   * Path handling.
   *
   * These methods retrieve paths from HTTP headers, parse them, and
   * resolve them into component parts.
   *
   *--------------------------------------------------------------------*/

  /**
   * Gets the source path from the request header.
   *
   * @return string
   *   Returns the source path, or an empty string if the request header
   *   was not set or it had an empty value.
   *
   * @see ::HEADER_SOURCE_PATH
   */
  private function getSourcePath() {
    $requestHeaders = $this->currentRequest->headers;
    if ($requestHeaders->has(self::HEADER_SOURCE_PATH) === FALSE) {
      return '';
    }

    $value = $requestHeaders->get(self::HEADER_SOURCE_PATH);
    if (empty($value) === TRUE) {
      return '';
    }

    return rawurldecode($value);
  }

  /**
   * Gets the destination path from the request header.
   *
   * @return string
   *   Returns the source path, or an empty string if the request header
   *   was not set or it had an empty value.
   *
   * @see ::HEADER_DESTINATION_PATH
   */
  private function getDestinationPath() {
    $requestHeaders = $this->currentRequest->headers;
    if ($requestHeaders->has(self::HEADER_DESTINATION_PATH) === FALSE) {
      return '';
    }

    $value = $requestHeaders->get(self::HEADER_DESTINATION_PATH);
    if (empty($value) === TRUE) {
      return '';
    }

    return rawurldecode($value);
  }

  /**
   * Returns the parent directory path from the path.
   *
   * PHP's dirname() uses the current locale as it parses a path. This
   * includes the locale's character set and whether it uses '/' or '\'
   * as the directory separator. However, we ALWAYS want multi-byte
   * character encoding detection AND '/' as the directory separator.
   * So we have to implement our own dirname()-like function here.
   *
   * @param string $path
   *   The path to parse.
   *
   * @return string
   *   The portion of the path up to, but not including, the last '/'.
   *   If there is no '/' or the path only contains a '/', the empty
   *   string is returned.
   */
  private function getDirname(string $path) {
    if ($path === '/') {
      return '';
    }

    // Find the location of the last '/'.
    $lastSlashPosition = mb_strrpos($path, '/');
    if ($lastSlashPosition === FALSE) {
      return '';
    }

    return mb_substr($path, 0, $lastSlashPosition);
  }

  /**
   * Returns the last name on the directory path.
   *
   * PHP's basename() uses the current locale as it parses a path. This
   * includes the locale's character set and whether it uses '/' or '\'
   * as the directory separator. However, we ALWAYS want multi-byte
   * character encoding detection AND '/' as the directory separator.
   * So we have to implement our own basename()-like function here.
   *
   * @param string $path
   *   The path to parse.
   *
   * @return string
   *   The portion of the path starting immediately after the last '/'
   *   and extending to the end of the string. If there is no '/',
   *   the entire string is returned. If there is nothing after the
   *   last '/', the empty string is returned.
   */
  private function getBasename(string $path) {
    if ($path === '/') {
      return '';
    }

    // Find the location of the last '/'.
    $lastSlashPosition = mb_strrpos($path, '/');
    if ($lastSlashPosition === FALSE) {
      return $path;
    }

    return mb_substr($path, ($lastSlashPosition + 1));
  }

  /*--------------------------------------------------------------------
   *
   * Source and destination entities.
   *
   * These methods help load source and destination entities.
   *
   *--------------------------------------------------------------------*/

  /**
   * Gets the source entity indicated by a URL entity ID or source path.
   *
   * If there is no source and $required is TRUE, an exception is thrown.
   * Otherwise a NULL is returned when there is no source.
   *
   * @param int $id
   *   (optional, default = -1) The entity ID if there is no source path.
   * @param bool $required
   *   (optional, default = TRUE) Indicate if the source entity must exist.
   *   If so and the entity cannot be found, an exception is thrown. Otherwise
   *   a NULL is returned.
   *
   * @return \Drupal\foldeshare\FolderShareInterface
   *   Returns the loaded source entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the source entity is required and there is
   *   no source found.
   */
  private function getSourceAndValidate(int $id = (-1), bool $required = TRUE) {
    // Get the source path, if any.
    $sourcePath = $this->getSourcePath();

    // If the source path exists, parse it to get the entity ID. Use that
    // ID to override the incoming entity ID.
    if (empty($sourcePath) === FALSE) {
      try {
        $id = FolderShare::getEntityIdForPath($sourcePath);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    // If there is no entity ID, throw an exception if one is required.
    if ($id === (-1)) {
      if ($required === TRUE) {
        throw new BadRequestHttpException(t(
          "Malformed request is missing the source item ID or path."));
      }

      return NULL;
    }

    // Load the entity.
    $entity = FolderShare::load($id);
    if ($entity === NULL) {
      if ($required === TRUE) {
        throw new NotFoundHttpException(t(
          "The source item could not be found."));
      }

      return NULL;
    }

    return $entity;
  }

  /**
   * Gets the destination entity indicated by a URL entity ID or source path.
   *
   * If the destination path is "/", a NULL is returned and the path parts
   * argument is set to the parsed scheme, user, and path.
   *
   * If there is no destination and $required is TRUE, an exception is thrown.
   * Otherwise a NULL is returned when there is no destination.
   *
   * @param int $id
   *   The entity ID if there is no destination path.
   * @param bool $required
   *   Indicate if the destination entity must exist. If so and the entity
   *   cannot be found, an exception is thrown. Otherwise a NULL is returned.
   * @param array $pathParts
   *   The returned parsed parts of the path, if a path was used.
   *
   * @return \Drupal\foldeshare\FolderShareInterface
   *   Returns the loaded destination entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the destination entity is required and there is
   *   no destination found.
   */
  private function getDestination(
    int $id,
    bool $required,
    array &$pathParts) {

    // Get the destination path, if any.
    $path = $this->getDestinationPath();

    // If the destination path exists, parse it to get the entity ID. Use that
    // ID to override the incoming entity ID.
    if (empty($path) === FALSE) {
      try {
        $parts = FolderShare::parseAndValidatePath($path);
        if ($parts['path'] !== '/') {
          $pathParts['scheme'] = $parts['scheme'];
          $pathParts['uid']    = $parts['uid'];
          $pathParts['path']   = $parts['path'];
          return NULL;
        }

        $id = FolderShare::getEntityIdForPath($path);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    // If there is no entity ID, throw an exception if one is required.
    if ($id === (-1)) {
      if ($required === TRUE) {
        throw new BadRequestHttpException(t(
          "Malformed request is missing the destination item ID or path."));
      }

      return NULL;
    }

    // Load the entity.
    $entity = FolderShare::load($id);
    if ($entity === NULL) {
      if ($required === TRUE) {
        throw new NotFoundHttpException(t(
          "The destination item could not be found."));
      }

      return NULL;
    }

    return $entity;
  }

  /**
   * Checks for user access to the indicated entity.
   *
   * Access is checked for the requested operation. If access is denied,
   * an exception is thrown with an appropriate message.
   *
   * @param \Drupal\foldeshare\FolderShareInterface $entity
   *   The entity to check for access. If NULL, the operation must be 'create'
   *   and access is checked for creating a root folder.
   * @param string $operation
   *   The access operation to check for.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if access is denied.
   */
  private function validateAccess(
    FolderShareInterface $entity = NULL,
    string $operation = '') {

    // If there is an entity, check the entity directly for access.
    if ($entity !== NULL) {
      $access = $entity->access($operation, NULL, TRUE);

      // If access is allowed, return immediately.
      if ($access->isAllowed() === TRUE) {
        return;
      }

      // No access. If a reason was not provided, use a default.
      $message = $access->getReason();
      if (empty($message) === TRUE) {
        $message = $this->getDefaultAccessDeniedMessage($operation);
      }

      throw new AccessDeniedHttpException($message);
    }

    // Otherwise there is no entity. The operation MUST be 'create',
    // otherwise access is denied.
    if ($operation !== 'create') {
      throw new AccessDeniedHttpException(
        $this->getDefaultAccessDeniedMessage($operation));
    }

    $accessController = \Drupal::entityTypeManager()
      ->getAccessControlHandler(FolderShare::ENTITY_TYPE_ID);

    $access = $accessController->createAccess(
      NULL,
      NULL,
      ['parentId' => (-1)],
      TRUE);

    // If access is allowed, return immediately.
    if ($access->isAllowed() === TRUE) {
      return;
    }

    // No access. If a reason was not provided, use a default.
    $message = $access->getReason();
    if (empty($message) === TRUE) {
      $message = $this->getDefaultAccessDeniedMessage('create');
    }

    throw new AccessDeniedHttpException($message);
  }

  /*--------------------------------------------------------------------
   *
   * Key-value pair pseudo-serialization.
   *
   * These methods provide a pseudo-serialization that represents an
   * entity using a simplified key-value pair structure that is suitable
   * for presentation directly to users.
   *
   *--------------------------------------------------------------------*/

  /**
   * Creates a key-value pair version of an entity.
   *
   * This method serializes an entity into an associative array of field
   * name keys and their field values. For complex fields, only the primary
   * value (used in renders) is included.
   *
   * This key-value array provides a simpler view of the entity, though it
   * lacks detail and is not compatible with further PATCH and POST requests.
   *
   * The entity should already have been cleaned to remove empty fields and
   * fields that cannot be viewed by the user.
   *
   * @param \Drupal\foldeshare\FolderShareInterface $entity
   *   The entity whose fields contribute to a key-value pair output.
   *
   * @return array
   *   Returns an associative array with keys with field names and values
   *   that contain the simplified representation of the field's value.
   */
  private function formatKeyValueEntity(FolderShareInterface &$entity) {
    // Map the entity's fields to key-value pairs. This provides the
    // primary data for the response.
    $content = [];
    foreach ($entity as $fieldName => &$field) {
      $value = $this->createKeyValueFieldItemList($field);
      if (empty($value) === FALSE) {
        $content[$fieldName] = $value;
      }
    }

    // Add pseudo-fields that are useful for the client side to interpret
    // and present the entity. All of these are safe to return to the client:
    //
    // - The sharing status is merely a simplified state that could be
    //   determined by the client by querying the entity's root folder
    //   and looking at the grant field UIDs.
    //
    // - The entity path is a locally built string that could be built by
    //   the client by querying the entity's ancestors and concatenating
    //   their names.
    //
    // - The user ID and account names are a minor translation of the data
    //   the client already provided for authentication. For content owned
    //   by the user, the UID is already in the entity's 'uid' field. The
    //   account name matches the authentication user name. The display name
    //   could be retrieved by the client by sending a REST request to the
    //   User module (if it is enabled for REST).
    //
    // - The host name is from the HTTP request provided by the client.
    //
    // - The formatted dates merely use the numeric timestamp already
    //   returned in the 'created' and 'changed' fields, and formats it
    //   for easier use.
    $content['host'] = $this->currentRequest->getHttpHost();

    $uid = $entity->getOwnerId();
    $content['user-id'] = $uid;
    $owner = User::load($uid);
    if ($owner !== NULL) {
      $content['user-account-name'] = $owner->getAccountName();
      $content['user-display-name'] = $owner->getDisplayName();
      $content['sharing-status'] = $entity->getSharingStatus();
    }
    else {
      $content['user-account-name'] = "unknown";
      $content['user-display-name'] = "unknown";
      $content['sharing-status'] = "unknown";
    }

    $content['path'] = $entity->getPath();

    // Provide formatted dates. We don't need to support a format that
    // includes every part of the date, such as seconds and microseconds.
    // The client can create its own formatting if it wants to by using
    // the raw timestamp. These formatted dates are a courtesy only.
    //
    // These formatted dates use the user's selected time zone and a
    // basic date format like that found in Linux "ls -l" output, which
    // does not include seconds and microseconds.
    $usersTimeZone = \Drupal::currentUser()->getTimeZone();
    $date = new \DateTime();
    $date->setTimezone(new \DateTimeZone($usersTimeZone));
    $content['user-time-zone'] = $usersTimeZone;

    $date->setTimestamp($entity->getCreatedTime());
    $content['created-date'] = $date->format("M d Y H:i");

    $date->setTimestamp($entity->getChangedTime());
    $content['changed-date'] = $date->format("M d Y H:i");

    return $content;
  }

  /**
   * Returns a simplified value for a field item list.
   *
   * If the field item list has one entry, the value for that entry is
   * returned.  Otherwise an array of entry values is returned.
   *
   * If the field item list is empty, a NULL is returned.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $fieldList
   *   The field item list whose values contribute to a key-value pair output.
   *
   * @return mixed
   *   Returns a value for the field item list.
   */
  private function createKeyValueFieldItemList(
    FieldItemListInterface &$fieldList) {

    // If the list is empty, return nothing.
    if ($fieldList->isEmpty() === TRUE) {
      return NULL;
    }

    // If the list has only one entry, return the representation of
    // that one entry. This is the most common case.
    if ($fieldList->count() === 1) {
      return $this->createKeyValueFieldItem($fieldList->first());
    }

    // Otherwise when the list has multiple entries, return an array
    // of their values.
    $values = [];
    foreach ($fieldList as $field) {
      $v = $this->createKeyValueFieldItem($field);
      if (empty($v) === FALSE) {
        $values[] = $v;
      }
    }

    return $values;
  }

  /**
   * Returns a simplified value for a field item.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $field
   *   The field item whose value contributes to a key-value pair output.
   *
   * @return string
   *   Returns a value for the field item.
   */
  private function createKeyValueFieldItem(FieldItemInterface $field) {
    if ($field->isEmpty() === TRUE) {
      return NULL;
    }

    return $field->getString();
  }

  /*--------------------------------------------------------------------
   *
   * Utilities.
   *
   * These methods provide help in performing the primary tasks of this
   * class.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns a generic access denied message.
   *
   * When performing access control checks, an access denied response
   * may or may not include a response message. If it does, that message
   * is used. But if not, this method fills in a generic message.
   *
   * @param string $operation
   *   The name of the operation that is not allowed (e.g. 'view').
   *
   * @return string
   *   Returns a generic default message for an AccessDeniedHttpException.
   */
  private function getDefaultAccessDeniedMessage($operation) {
    return "You are not authorized to {$operation} this file or folder.";
  }

  /**
   * Modifies an entity to clean out empty or unviewable fields.
   *
   * This method loops through an entity's fields and:
   * - Removes fields with no values.
   * - Removes fields for which the user does not have 'view' access.
   *
   * In both cases, removed fields are set to NULL. It is *assumed* that
   * the caller will not save the entity, which could corrupt the
   * database with an invalid entity.
   *
   * @param \Drupal\foldeshare\FolderShareInterface $entity
   *   The entity whose fields should be cleared if they are not viewable.
   *
   * @return \Drupal\foldershare\FolderShareInterface
   *   Returns the same entity.
   */
  private function cleanEntity(FolderShareInterface &$entity) {
    foreach ($entity as $fieldName => &$field) {
      // Check access to the field.
      if ($field->access('view', NULL, FALSE) === FALSE) {
        $entity->set($fieldName, NULL, FALSE);
        continue;
      }

      // Check for empty fields.
      if ($field->isEmpty() === TRUE) {
        $entity->set($fieldName, NULL, FALSE);
        continue;
      }

      // Fields normally implement isEmpty() in a sane way to report
      // when a field is empty. But the comment module implements isEmpty()
      // to always return FALSE because it always has state for the entity,
      // including whether or not commenting is open or closed.
      //
      // For our purposes, though, a clean entity should omit a comment
      // field if the entity has no comments yet. This means we cannot
      // rely upon isEmpty().
      //
      // This is made trickier because the right way to check this is not
      // well-defined, and because the comment module may not be installed.
      $first = $field->get(0);
      if (is_a($first, 'Drupal\comment\Plugin\Field\FieldType\CommentItem') === TRUE) {
        // We have a comment field. It contains multiple values for
        // commenting open/closed, the most recent comment, and finally
        // the number of comments so far. We only care about the latter.
        if (intval($first->get('comment_count')->getValue()) === 0) {
          $entity->set($fieldName, NULL, FALSE);
          continue;
        }
      }
    }

    return $entity;
  }

  /**
   * Adds link headers to a response.
   *
   * If the entity has an entity reference fields, this method loops
   * through them and adds them to a 'Link' header field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The FolderShare entity.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   *
   * @see https://tools.ietf.org/html/rfc5988#section-5
   */
  private function addLinkHeaders(
    EntityInterface $entity,
    Response $response) {

    // Loop through all URI relationships (entity references) in the entity.
    foreach ($entity->uriRelationships() as $relationName) {
      // If the relationship does not have a definition, skip it.
      // We don't have enough information to describe this relationship.
      $hasDef = $this->linkRelationTypeManager->hasDefinition($relationName);
      if ($hasDef === FALSE) {
        continue;
      }

      // Get the relationship information.
      $type = $this->linkRelationTypeManager->createInstance($relationName);

      if ($type->isRegistered() === TRUE) {
        $relationship = $type->getRegisteredName();
      }
      else {
        $relationship = $type->getExtensionUri();
      }

      // Generate a URL for the relationship.
      $urlObj = $entity->toUrl($relationName)
        ->setAbsolute(TRUE)
        ->toString(TRUE);

      $url = $urlObj->getGeneratedUrl();

      // Add a link header for this item.
      $response->headers->set(
        'Link',
        '<' . $url . '>; rel="' . $relationship . '"',
        FALSE);
    }
  }

  /*--------------------------------------------------------------------
   *
   * Response utilities.
   *
   * These methods help build standard responses.
   *
   *--------------------------------------------------------------------*/

  /**
   * Builds and returns a response containing a single entity.
   *
   * The entity is "cleaned" to remove non-viewable internal fields,
   * and then formatted properly using the selected return format.
   * A response containing the formatted entity is returned.
   *
   * @param \Drupal\foldershare\FolderShareInterface $entity
   *   A single entity to return, after cleaning and formatting.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   */
  private function formatEntityResponse(FolderShareInterface $entity) {
    //
    // Validate
    // --------
    // If there is no entity, return nothing.
    if ($entity === NULL) {
      return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT);
    }

    //
    // URL
    // ---
    // Create the URL for the updated entity.
    if (empty($entity->id()) === FALSE) {
      $url = $entity->urlInfo(
        'canonical',
        ['absolute' => TRUE])->toString(TRUE);

      $headers = [
        'Location' => $url->getGeneratedUrl(),
      ];
    }
    else {
      $headers = [];
    }

    //
    // Entity access control again
    // ---------------------------
    // If the user has view permission for the entity (and they likely do
    // if they had update permission checked earlier), then we can return
    // the updated entity. Otherwise not.
    if ($entity->access('view', NULL, FALSE) === FALSE) {
      // This is odd that they can edit, but not view.
      return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT, $headers);
    }

    //
    // Clean entity
    // ------------
    // A loaded entity has all fields, but only some fields are user-visible.
    // "Clean" the entity of non-viewable fields by deleting them from the
    // entity.
    $partialEntity = $this->cleanEntity($entity);

    //
    // Return entity
    // -------------
    // Based upon the return format, return the entity.
    switch ($this->getAndValidateReturnFormat()) {
      default:
      case 'full':
        // Return as full entity.
        $response = new UncacheableResponse(
          $partialEntity,
          Response::HTTP_OK,
          $headers);
        break;

      case 'keyvalue':
        // Return as key-value pairs.
        $response = new UncacheableResponse(
          $this->formatKeyValueEntity($partialEntity),
          Response::HTTP_OK,
          $headers);
        break;
    }

    if ($partialEntity->isNew() === FALSE) {
      $this->addLinkHeaders($partialEntity, $response);
    }

    return $response;
  }

  /**
   * Builds and returns a response containing a list of entities.
   *
   * The entities are "cleaned" to remove non-viewable internal fields,
   * and then formatted properly using the selected return format.
   * A response containing the formatted entity is returned.
   *
   * @param \Drupal\foldershare\FolderShareInterface[] $entities
   *   A list of entities to return, after cleaning and formatting.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   */
  private function formatEntityListResponse(array $entities) {
    //
    // Clean entities
    // --------------
    // Loaded entities have all fields, but only some fields are user-visible.
    // "Clean" the entities of non-viewable fields by deleting them from the
    // entity.
    if (empty($entities) === TRUE) {
      return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT);
    }

    $partialEntities = [];
    foreach ($entities as &$entity) {
      $partialEntities[] = $this->cleanEntity($entity);
    }

    if (empty($partialEntities) === TRUE) {
      return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT);
    }

    //
    // Return entities
    // ---------------
    // Based upon the return format, return the entities.
    switch ($this->getAndValidateReturnFormat()) {
      default:
      case 'full':
        // Return as full entities.
        return new UncacheableResponse($partialEntities);

      case 'keyvalue':
        // Return as key-value pairs.
        $kv = [];
        foreach ($partialEntities as &$entity) {
          $kv[] = $this->formatKeyValueEntity($entity);
        }
        return new UncacheableResponse($kv);
    }
  }

  /*--------------------------------------------------------------------
   *
   * GET response.
   *
   *--------------------------------------------------------------------*/

  /**
   * Responds to GET requests.
   *
   * GET requests use the 'canonical' route which must include a single
   * non-negative integer entity ID in the URL. Some requests use the ID,
   * while others do not.
   *
   * This method supports multiple GET operations that respond with different
   * types of information, from entities and lists of entities to module
   * and web site settings.
   *
   * @param int $id
   *   (optional, default = NULL) The entity ID of a FolderShare entity,
   *   or a placeholder ID if the GET operation does not require an ID or
   *   if the ID or path is specified by the X-FolderShare-Source-Path
   *   header.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws a BadRequestHttpException if any header value is not
   *   recognized, and throws AccessDeniedHttpException if the user does
   *   not have "view" access to an entity when an operation requires access.
   */
  public function get(int $id = NULL) {
    //
    // Get the operation
    // -----------------
    // Get the specific GET operation to perform from the HTTP header,
    // if any. If a header is not provided, default to getting an entity.
    $operation = $this->getAndValidateGetOperation();

    //
    // Dispatch
    // --------
    // Handle each of the GET operations.
    switch ($operation) {
      case 'get-entity':
        // Load and return an entity.
        return $this->getEntity($id);

      case 'get-parent':
        // Load and return the entity's parent.
        return $this->getEntityParent($id);

      case 'get-root':
        // Load and return the entity's root.
        return $this->getEntityRoot($id);

      case 'get-ancestors':
        // Load and return a list of the entity's ancestors.
        return $this->getEntityAncestors($id);

      case 'get-descendants':
        // Load and return a list of the entity's descendants, or a list
        // of root folders.
        return $this->getEntityDescendants($id);

      case 'get-sharing':
        // TODO implement!
        throw new BadRequestHttpException(
          'Getting sharing settings not yet supported.');

      case 'get-search':
        // TODO implement!
        throw new BadRequestHttpException(
          'Search not yet supported.');

      case 'get-usage':
        // Return usage stats for the user.
        return $this->getUsage();

      case 'get-configuration':
        // Return the module and site configuration.
        return $this->getConfiguration();

      case 'get-version':
        // Return module version numbers.
        return $this->getVersion();

      case 'download':
        // Download a single file.
        return $this->download($id);

      default:
        throw new BadRequestHttpException(t(
          "Unsupported FolderShare operation: \"@operation\".",
          [
            '@operation' => $operation,
          ]));
    }
  }

  /**
   * Responds to a GET request for an entity.
   *
   * @param int $id
   *   The ID of the entity from the URL.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the request is bad.
   */
  private function getEntity(int $id) {
    // Get the HTTP header's source path, if any.
    $source = $this->getSourcePath();

    // Parse the path to get the entity's ID, if any.
    if (empty($source) === FALSE) {
      try {
        $components = FolderShare::parseAndValidatePath($source);
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }

      // If the path is just '/', there is no actual entity to get.
      // Instead, create a temporary entity and return it.
      if ($components['path'] === '/') {
        $uid = $components['uid'];
        if ($uid === NULL || $uid === (-1)) {
          $uid = \Drupal::currentUser()->id();
        }

        $falseFolder = FolderShare::create([
          'name' => '',
          'uid'  => $uid,
          'kind' => FolderShare::ROOT_FOLDER_KIND,
          'mime' => FolderShare::ROOT_FOLDER_MIME,
        ]);

        return $this->formatEntityResponse($falseFolder);
      }

      // Get the path's entity.
      try {
        $id = FolderShare::getEntityIdForPath($source);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    return $this->formatEntityResponse(
      $this->loadAndValidateEntity($id, "view"));
  }

  /**
   * Responds to a GET request for an entity's parent.
   *
   * @param int $id
   *   The ID of the entity from the URL.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the request is bad.
   */
  private function getEntityParent(int $id) {
    // Get the HTTP header's source path, if any.
    $source = $this->getSourcePath();
    if (empty($source) === FALSE) {
      // Get the path's entity.
      try {
        $id = FolderShare::getEntityIdForPath($source);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    $entity = $this->loadAndValidateEntity($id, "view");
    if ($entity->isRootFolder() === TRUE) {
      throw new NotFoundHttpException(t(
        'The top-level folder does not have a parent folder.'));
    }

    return $this->formatEntityResponse($entity->getParentFolder());
  }

  /**
   * Responds to a GET request for an entity's root.
   *
   * @param int $id
   *   The ID of the entity from the URL.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the request is bad.
   */
  private function getEntityRoot(int $id) {
    // Get the HTTP header's source path, if any.
    $source = $this->getSourcePath();
    if (empty($source) === FALSE) {
      // Get the path's entity.
      try {
        $id = FolderShare::getEntityIdForPath($source);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    $entity = $this->loadAndValidateEntity($id, "view");
    if ($entity->isRootFolder() === TRUE) {
      throw new NotFoundHttpException(t(
        'The top-level folder does not have an ancestor top-level folder.'));
    }

    return $this->formatEntityResponse($entity->getRootFolder());
  }

  /**
   * Responds to a GET request for an entity's ancestors.
   *
   * @param int $id
   *   The ID of the entity from the URL.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the request is bad.
   */
  private function getEntityAncestors(int $id) {
    // Get the HTTP header's source path, if any.
    $source = $this->getSourcePath();
    if (empty($source) === FALSE) {
      // Get the path's entity.
      try {
        $id = FolderShare::getEntityIdForPath($source);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    $entity = $this->loadAndValidateEntity($id, "view");
    if ($entity->isRootFolder() === TRUE) {
      return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT);
    }

    return $this->formatEntityListResponse(
      array_reverse($entity->getAncestorFolders()));
  }

  /**
   * Responds to a GET request for an entity's descendants or root lists.
   *
   * @param int $id
   *   The ID of the entity from the URL.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the request is bad.
   */
  private function getEntityDescendants(int $id) {
    // Get the HTTP header's source path, if any.
    $source = $this->getSourcePath();
    if (empty($source) === FALSE) {
      // Parse the source path.
      try {
        $components = FolderShare::parseAndValidatePath($source);
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }

      // If the path is just '/', get the root list based on the scheme.
      if ($components['path'] === '/') {
        return $this->getRootList($components['scheme'], $components['uid']);
      }

      // Otherwise get the entity ID for the path.
      try {
        $id = FolderShare::getEntityIdForPath($source);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    // Load and return the entity's children.
    $entity = $this->loadAndValidateEntity($id, "view");
    if ($entity->isFolderOrRootFolder() === FALSE) {
      // The entity is not a folder. Just return the folder itself.
      return $this->formatEntityListResponse([$entity]);
    }

    return $this->formatEntityListResponse($entity->getChildren());
  }

  /**
   * Responds to a GET request for a list of root folders.
   *
   * @param string $scheme
   *   The root folder group.
   * @param int $uid
   *   (optional, default = NULL) The user ID for the group.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if arguments are bad.
   */
  private function getRootList(string $scheme, int $uid = NULL) {
    switch ($scheme) {
      case FolderShare::PRIVATE_SCHEME:
        // All of the user's root folders.
        //
        // If no UID was given, default to the current user.
        if ($uid === NULL) {
          $uid = \Drupal::currentUser()->id();
        }
        return $this->formatEntityListResponse(
          FolderShare::getAllRootFolders($uid));

      case FolderShare::PUBLIC_SCHEME:
        // All root folders shared with the public, if sharing enabled.
        if (Settings::getSharingAllowed() === FALSE ||
            Settings::getSharingAllowedWithAnonymous() === FALSE) {
          return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT);
        }

        // If no UID was given, look for public folders owned by anyone.
        if ($uid === NULL) {
          $uid = (-1);
        }
        return $this->formatEntityListResponse(
          FolderShare::getPublicRootFolders($uid));

      case FolderShare::SHARED_SCHEME:
        // All root folders shared with the user, if sharing enabled.
        if (Settings::getSharingAllowed() === FALSE) {
          return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT);
        }

        // If no UID was given, look for shared folders owned by anyone.
        if ($uid === NULL) {
          $uid = (-1);
        }
        return $this->formatEntityListResponse(
          FolderShare::getSharedRootFolders($uid));

      default:
        throw new BadRequestHttpException(t(
          "Invalid file and folder path scheme: \"@scheme\".",
          [
            '@scheme' => $scheme,
          ]));
    }
  }

  /**
   * Responds to a GET request to download a file or folder.
   *
   * The given entity ID or source path may refer to a file or folder.
   * If it refers to a file, that file is downloaded as-is. If it refers
   * to a folder, the folder is ZIPed and the ZIP file is returned.
   *
   * @param int $id
   *   The ID of the entity from the URL.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if the request is bad.
   */
  private function download(int $id) {
    //
    // Validate
    // --------
    // Get the HTTP header's source path, if any.
    $source = $this->getSourcePath();
    if (empty($source) === FALSE) {
      // Get the path's entity.
      try {
        $id = FolderShare::getEntityIdForPath($source);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (ValidationException $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    // Check if it is a file, image, or folder.
    $entity = FolderShare::load($id);
    if ($entity->isFolderOrRootFolder() === TRUE) {
      // The entity is a folder. Create a ZIP archive of the folder.
      // The new ZIP archive is in temporary storage, so it will be
      // automatically deleted later on by Drupal CRON.
      try {
        $uri = FolderShare::createZipArchive([$entity]);
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }

      $filename = $entity->getName() . '.zip';
      $mimeType = Unicode::mimeHeaderEncode(
        \Drupal::service('file.mime_type.guesser')->guess($filename));
    }
    elseif ($entity->isFile() === TRUE || $entity->isImage() === TRUE) {
      // The entity is a file or image. Get the underlying file's URI,
      // human-readable name, MIME type, and size.
      if ($entity->isFile() === TRUE) {
        $file = $entity->getFile();
      }
      else {
        $file = $entity->getImage();
      }

      $uri      = $file->getFileUri();
      $filename = $file->getFilename();
      $mimeType = Unicode::mimeHeaderEncode($file->getMimeType());
    }
    else {
      // The entity is not a file or image. It may be a media object.
      // In any case, it cannot be downloaded.
      throw new BadRequestHttpException(t(
        "The requested item is not downloadable file or folder."));
    }

    //
    // Get file attributes
    // -------------------
    $realPath = FileUtilities::realpath($uri);

    if ($realPath === FALSE || file_exists($realPath) === FALSE) {
      throw new NotFoundHttpException(t(
        "System error: The file in server storage could not be found."));
    }

    $filesize = FileUtilities::filesize($realPath);

    //
    // Build header
    // ------------
    // Build an HTTP header for the file by getting the user-visible
    // file name and MIME type. Both of these are essential in the HTTP
    // header since they tell the browser what type of file it is getting,
    // and the name of the file if the user wants to save it their disk.
    $headers = [
      // Use the File object's MIME type.
      'Content-Type'        => $mimeType,

      // Use the human-visible file name.
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',

      // Use the saved file size, in bytes.
      'Content-Length'      => $filesize,

      // To be safe, transfer everything in binary. This will work
      // for text-based files as well.
      'Content-Transfer-Encoding' => 'binary',

      // Don't cache the file because permissions and content may
      // change.
      'Pragma'              => 'no-cache',
      'Cache-Control'       => 'must-revalidate, post-check=0, pore-check=0',
      'Expires'             => '0',
      'Accept-Ranges'       => 'bytes',
    ];

    $scheme = Settings::getFileScheme();
    $isPrivate = ($scheme == 'private');

    //
    // Respond
    // -------
    // \Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()
    // sets response as not cacheable if the Cache-Control header is not
    // already modified. We pass in FALSE for non-private schemes for the
    // $public parameter to make sure we don't change the headers.
    return new BinaryFileResponse($uri, 200, $headers, !$isPrivate);
  }


  /**
   * Responds to a GET request for the user's usage of the module.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns a response containing key-value pairs for the user's current
   *   usage of the FolderShare module.
   */
  private function getUsage() {
    //
    // Add pseudo-fields
    // -----------------
    // Add pseudo-fields useful for the client:
    // - Host name.
    // - User ID.
    // - User account name.
    // - User display name.
    $user = \Drupal::currentUser();
    $uid = (int) $user->id();

    $content = [];
    $content['host'] = $this->currentRequest->getHttpHost();

    $content['user-id'] = $uid;
    $content['user-account-name'] = $user->getAccountName();
    $content['user-display-name'] = $user->getDisplayName();

    //
    // Add usage
    // ---------
    // Add usage data.
    $content += FolderShareUsage::getUsage($uid);

    return new UncacheableResponse($content);
  }

  /**
   * Responds to a GET request for the module and site configuration.
   *
   * The returned array contains key-value pairs for the module's public
   * settings. Site admin-only settings are not included.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns a response containing key-value pairs for module and
   *   sit settings.
   */
  private function getConfiguration() {
    //
    // Add pseudo-fields
    // -----------------
    // Add pseudo-fields that are useful for the client side to interpret
    // and present the data. All of these are safe to return to the client:
    // - Host name.
    $content = [];
    $content['host'] = $this->currentRequest->getHttpHost();

    //
    // Add resource settings
    // ---------------------
    // Add client-relevant settings for this resource:
    // - serializer formats.
    // - authentication providers.
    //
    // A REST resource may configured with two granularities:
    //
    // - method granularity: every method has its own serializer and
    //   authentication settings.
    //
    // - resource granularity: all methods share the same serializer and
    //   authentication settings.
    //
    // There is no method on the configuration to get the granularity.
    // We must assume the most complex case of method granularity.
    foreach ($this->resourceConfiguration->getMethods() as $method) {
      // Get the serializer formats and authentication providers configured
      // for the method.
      $formats = $this->resourceConfiguration->getFormats($method);
      $auths = $this->resourceConfiguration->getAuthenticationProviders($method);

      $content[$method] = [
        'serializer-formats' => $formats,
        'authentication-providers' => $auths,
      ];
    }

    //
    // Add module settings
    // -------------------
    // Add client-relevant module settings.
    $content['file-restrict-extensions'] =
      ((Settings::getFileRestrictExtensions() === TRUE) ?
      'true' : 'false');
    $content['file-allowed-extensions'] =
      Settings::getFileAllowedExtensions();
    $content['file-maximum-upload-size'] =
      Settings::getUploadMaximumFileSize();
    $content['file-maximum-upload-number'] =
      Settings::getUploadMaximumFileNumber();

    $content['sharing-allowed'] =
      ((Settings::getSharingAllowed() === TRUE) ?
      'true' : 'false');
    $content['sharing-allowed-with-anonymous'] =
      ((Settings::getSharingAllowedWithAnonymous() === TRUE) ?
      'true' : 'false');

    return new UncacheableResponse($content);
  }

  /**
   * Responds to a GET request for Drupal and module version numbers.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns a response containing key-value pairs for version numbers.
   */
  private function getVersion() {
    //
    // Add pseudo-fields
    // -----------------
    // Add pseudo-fields that are useful for the client side to interpret
    // and present the data. All of these are safe to return to the client:
    // - Host name.
    $content = [];
    $content['server'] = [];
    $content['host'] = $this->currentRequest->getHttpHost();

    //
    // Add version numbers
    // -------------------
    // Add numbers for Drupal core, key support modules, and FolderShare.
    //
    // Get the Drupal core version number.
    $content['server']['drupal'] = [
      'name'    => 'Drupal content management system',
      'version' => \Drupal::VERSION,
    ];

    // Get the PHP version number.
    $content['server']['serverphp'] = [
      'name'    => 'PHP',
      'version' => phpversion(),
    ];

    // Get the Symfony version number.
    $content['server']['symfony'] = [
      'name'    => 'Symfony library',
      'version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
    ];

    // Get the relevant module names and version numbers.
    $modules = system_get_info('module');

    if (isset($modules['rest']) === TRUE) {
      $content['server']['rest'] = [
        'name'    => $modules['rest']['name'] . ' module',
        'version' => $modules['rest']['version'],
      ];
    }

    if (isset($modules['serialization']) === TRUE) {
      $content['server']['serializer'] = [
        'name'    => $modules['serialization']['name'] . ' module',
        'version' => $modules['serialization']['version'],
      ];
    }

    if (isset($modules['basic_auth']) === TRUE) {
      $content['server']['basic_auth'] = [
        'name'    => $modules['basic_auth']['name'] . ' module',
        'version' => $modules['basic_auth']['version'],
      ];
    }

    if (isset($modules['foldershare']) === TRUE) {
      $content['server']['foldershare'] = [
        'name'    => $modules['foldershare']['name'] . ' module',
        'version' => $modules['foldershare']['version'],
      ];
    }

    return new UncacheableResponse($content);
  }

  /*--------------------------------------------------------------------
   *
   * DELETE response.
   *
   *--------------------------------------------------------------------*/

  /**
   * Responds to entity DELETE requests.
   *
   * @param int $id
   *   (optional, default = NULL) The entity ID of a FolderShare entity,
   *   or a placeholder ID if the GET operation does not require an ID or
   *   if the ID or path is specified by the X-FolderShare-Source-Path
   *   header.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if arguments are bad.
   */
  public function delete(int $id = NULL) {
    //
    // Get the operation
    // -----------------
    // Get the specific DELETE operation to perform from the HTTP header,
    // if any. If a header is not provided, default to getting an entity.
    $operation = $this->getAndValidateDeleteOperation();

    //
    // Get source
    // ----------
    // Get the source entity using the HTTP header, if any.
    $source = $this->getSourcePath();

    if (empty($source) === FALSE) {
      try {
        $id = FolderShare::getEntityIdForPath($source);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    // Load the entity. Check for 'delete' access.
    $entity = $this->loadAndValidateEntity($id, "delete");

    //
    // Delete
    // ------
    // Delete the entity. If the entity is a folder, this recurses to delete
    // all children as well.
    switch ($operation) {
      default:
      case 'delete-file':
        // Delete a file. Fail if the entity is not a file.
        if ($entity->isFolderOrRootFolder() === TRUE) {
          throw new BadRequestHttpException(t(
            "\"@name\" cannot be deleted. It is a folder, not a file.",
            [
              '@name' => $entity->getName(),
            ]));
        }
        break;

      case 'delete-folder':
        // Delete a folder, without recursion. Fail if the entity is not a
        // folder or the folder is not empty.
        if ($entity->isFolderOrRootFolder() === FALSE) {
          throw new BadRequestHttpException(t(
            "\"@name\" cannot be deleted. It is a file, not a folder.",
            [
              '@name' => $entity->getName(),
            ]));
        }

        if (empty($entity->getChildIds()) === FALSE) {
          throw new BadRequestHttpException(t(
            "\"@name\" cannot be deleted. The folder is not empty.",
            [
              '@name' => $entity->getName(),
            ]));
        }
        break;

      case 'delete-folder-tree':
        // Delete a folder, with recursion. Fail if the entity is not a folder.
        if ($entity->isFolderOrRootFolder() === FALSE) {
          // When deleting a folder, the entity must be a folder.
          throw new BadRequestHttpException(t(
            "\"@name\" cannot be deleted. It is a file, not a folder.",
            [
              '@name' => $entity->getName(),
            ]));
        }
        break;

      case 'delete-file-or-folder':
        // Delete a file or folder, without recursion. Fail if the entity
        // is a folder and the folder is not empty.
        if (empty($entity->getChildIds()) === FALSE) {
          throw new BadRequestHttpException(t(
            "\"@name\" cannot be deleted. The folder is not empty.",
            [
              '@name' => $entity->getName(),
            ]));
        }
        break;

      case 'delete-file-or-folder-tree':
        // Delete a file or folder, with recursion. This will delete anything.
        // Fall through.
        break;
    }

    try {
      // Delete!
      $entity->delete();

      // Log it.
      $this->logger->notice(
        'Deleted entity %type with ID %id.',
        [
          '%type' => $entity->getEntityTypeId(),
          '%id'   => $entity->id(),
        ]);

      return new UncacheableResponse(NULL, Response::HTTP_NO_CONTENT);
    }
    catch (LockException $e) {
      throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
    }
    catch (\Exception $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }
  }

  /*--------------------------------------------------------------------
   *
   * POST response.
   *
   *--------------------------------------------------------------------*/

  /**
   * Responds to POST requests to create a new FolderShare entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if arguments are bad.
   */
  public function post(EntityInterface $dummy = NULL) {
    //
    // Get operation
    // -------------
    // Use the HTTP header to get the POST operation, if any.
    $operation = $this->getAndValidatePostOperation();

    //
    // Validate dummy entity
    // ---------------------
    // A dummy entity must have been provided, it must have the proper
    // entity type, it cannot have an entity ID, and it must have a name.
    if ($dummy === NULL) {
      throw new BadRequestHttpException(t(
        "Malformed request is missing information about the item to create."));
    }

    if ($dummy->getEntityTypeId() !== FolderShare::ENTITY_TYPE_ID) {
      throw new BadRequestHttpException(t(
        "Malformed request has the wrong entity type for creating a new file or folder."));
    }

    if (count($dummy->_restSubmittedFields) === 0) {
      throw new BadRequestHttpException(t(
        "Malformed request that has no entity content."));
    }

    if ($dummy->isNew() === FALSE) {
      throw new BadRequestHttpException(t(
        "Malformed request has an entity ID set for an entity that doesn't exist yet."));
    }

    if (empty($dummy->getName()) === TRUE) {
      throw new BadRequestHttpException(t(
        "Malformed request is missing the name for a new item to create."));
    }

    //
    // Dispatch
    // --------
    // Handle each of the POST operations.
    switch ($operation) {
      case 'new-rootfolder':
        return $this->postNewRootFolder($dummy);

      case 'new-folder':
        return $this->postNewFolder($dummy);

      case 'new-file':
        return $this->postNewFile($dummy);

      case 'new-media':
        // TODO implement!
        throw new BadRequestHttpException(
          'Media entity creation not yet supported.');
    }
  }

  /**
   * Responds to a POST request to create a new root folder.
   *
   * No path headers are used. The new root folder is always created in
   * the user's own list of root folders (i.e. the "private" SCHEME).
   *
   * @param \Drupal\foldershare\FolderShareInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if arguments are bad.
   */
  private function postNewRootFolder(FolderShareInterface $dummy) {
    //
    // Check create access
    // -------------------
    // Confirm that the user can create root folders.
    $accessController = \Drupal::entityTypeManager()
      ->getAccessControlHandler(FolderShare::ENTITY_TYPE_ID);
    $access = $accessController->createAccess(
      NULL,
      NULL,
      ['parentId' => (-1)],
      TRUE);

    if ($access->isAllowed() === FALSE) {
      // No access. If a reason was not provided, use a default.
      $message = $access->getReason();
      if (empty($message) === TRUE) {
        $message = $this->getDefaultAccessDeniedMessage('create');
      }

      throw new AccessDeniedHttpException($message);
    }

    //
    // Create root folder
    // ------------------
    // Use the dummy entity's name and create a new root folder for the user.
    // Do not auto-rename to avoid name collisions. If the name collides,
    // report it to the user as an error.
    try {
      $folder = FolderShare::createRootFolder($dummy->getName(), FALSE);
    }
    catch (LockException $e) {
      throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
    }
    catch (ValidationException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
    catch (\Exception $e) {
      throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
    }

    //
    // Set fields
    // ----------
    // Loop through the dummy entity and copy changable fields into the
    // new folder. Ignore fields that cannot be edited.
    foreach ($dummy as $fieldName => $field) {
      // Ignore the "name" field handled above when we created the entity.
      if ($fieldName === 'name') {
        continue;
      }

      // Ignore empty fields.
      if ($field->isEmpty() === TRUE) {
        continue;
      }

      // Ignore fields that cannot be edited.
      if ($field->access('edit', NULL, FALSE) === FALSE) {
        continue;
      }

      // Set the entity's field.
      if (empty($field) === FALSE) {
        $folder->set($fieldName, $field);
      }
    }

    $folder->save();

    //
    // Log
    // ---
    // Note the creation in the log.
    $this->logger->notice(
      'Created entity %type root folder with ID %id.',
      [
        '%type' => $folder->getEntityTypeId(),
        '%id'   => $folder->id(),
      ]);

    //
    // URL
    // ---
    // Create the URL for the updated entity.
    $url = $folder->urlInfo(
      'canonical',
      ['absolute' => TRUE])->toString(TRUE);

    $headers = [
      'Location' => $url->getGeneratedUrl(),
    ];

    return new UncacheableResponse(NULL, Response::HTTP_CREATED, $headers);
  }

  /**
   * Responds to a POST request to create a new folder.
   *
   * The self::HEADER_DESTINATION_PATH header must contain the path to
   * a parent folder. If the header is missing, the dummy entity must
   * have a parent ID field set.
   *
   * @param \Drupal\foldershare\FolderShareInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if arguments are bad.
   */
  private function postNewFolder(FolderShareInterface $dummy) {
    //
    // Find the parent
    // ---------------
    // Check for a header destination path. If found, it must provide
    // the path to the parent folder, which must exist.
    $destinationPath = $this->getDestinationPath();

    if (empty($destinationPath) === FALSE) {
      try {
        $parentId = FolderShare::getEntityIdForPath($destinationPath);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }
    else {
      $parentId = $dummy->getParentFolderId();
    }

    if ($parentId === (-1)) {
      throw new BadRequestHttpException(t(
        "Malformed request to create a subfolder as a top-level folder."));
    }

    $parent = FolderShare::load($parentId);
    if ($parent === NULL) {
      throw new NotFoundHttpException(t(
        "The parent folder for the new subfolder could not be found."));
    }

    //
    // Check create access
    // -------------------
    // Confirm that the user can create folders in the parent folder.
    $accessController = \Drupal::entityTypeManager()
      ->getAccessControlHandler(FolderShare::ENTITY_TYPE_ID);
    $access = $accessController->createAccess(
      NULL,
      NULL,
      ['parentId' => $parentId],
      TRUE);

    if ($access->isAllowed() === FALSE) {
      // No access. If a reason was not provided, use a default.
      $message = $access->getReason();
      if (empty($message) === TRUE) {
        $message = $this->getDefaultAccessDeniedMessage('create');
      }

      throw new AccessDeniedHttpException($message);
    }

    //
    // Create folder
    // -------------
    // Use the dummy entity's name and create a new folder in the parent.
    // Do not auto-rename to avoid name collisions. If the name collides,
    // report it to the user as an error.
    try {
      $folder = $parent->createFolder($dummy->getName(), FALSE);
    }
    catch (LockException $e) {
      throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
    }
    catch (ValidationException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
    catch (\Exception $e) {
      throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
    }

    //
    // Set fields
    // ----------
    // Loop through the dummy entity and copy changable fields into the
    // new folder. Ignore fields that cannot be edited.
    foreach ($dummy as $fieldName => $field) {
      // Ignore the "name" field handled above when we created the entity.
      if ($fieldName === 'name') {
        continue;
      }

      // Ignore empty fields.
      if ($field->isEmpty() === TRUE) {
        continue;
      }

      // Ignore fields that cannot be edited.
      if ($field->access('edit', NULL, FALSE) === FALSE) {
        continue;
      }

      // Set the entity's field.
      if (empty($field) === FALSE) {
        $folder->set($fieldName, $field);
      }
    }

    $folder->save();

    //
    // Log
    // ---
    // Note the creation in the log.
    $this->logger->notice(
      'Created entity %type folder with ID %id.',
      [
        '%type' => $folder->getEntityTypeId(),
        '%id'   => $folder->id(),
      ]);

    //
    // URL
    // ---
    // Create the URL for the updated entity.
    $url = $folder->urlInfo(
      'canonical',
      ['absolute' => TRUE])->toString(TRUE);

    $headers = [
      'Location' => $url->getGeneratedUrl(),
    ];

    return new UncacheableResponse(NULL, Response::HTTP_CREATED, $headers);
  }

  /**
   * Responds to a POST request to create a new file.
   *
   * The self::HEADER_DESTINATION_PATH header must contain the path to
   * a parent folder. If the header is missing, the dummy entity must
   * have a parent ID field set.
   *
   * The dummy entity's 'name' field must have the name of the new file.
   *
   * @param \Drupal\foldershare\FolderShareInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if arguments are bad.
   */
  private function postNewFile(FolderShareInterface $dummy) {
    //
    // Find the parent
    // ---------------
    // Check for a header destination path. If found, it must provide
    // the path to the parent folder, which must exist.
    $destinationPath = $this->getDestinationPath();

    if (empty($destinationPath) === FALSE) {
      try {
        $parentId = FolderShare::getEntityIdForPath($destinationPath);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }
    else {
      $parentId = $dummy->getParentFolderId();
    }

    if ($parentId === (-1)) {
      throw new BadRequestHttpException(t(
        "Malformed request to create a subfolder as a top-level folder."));
    }

    $parent = FolderShare::load($parentId);
    if ($parent === NULL) {
      throw new NotFoundHttpException(t(
        "The parent folder for the new subfolder could not be found."));
    }

    $filename = $dummy->getName();
    if (empty($filename) === TRUE) {
      throw new NotFoundHttpException(t(
        "Malformed request has an empty new file name."));
    }

    //
    // Check create access
    // -------------------
    // Confirm that the user can create files in the parent folder.
    $accessController = \Drupal::entityTypeManager()
      ->getAccessControlHandler(FolderShare::ENTITY_TYPE_ID);
    $access = $accessController->createAccess(
      NULL,
      NULL,
      ['parentId' => $parentId],
      TRUE);

    if ($access->isAllowed() === FALSE) {
      // No access. If a reason was not provided, use a default.
      $message = $access->getReason();
      if (empty($message) === TRUE) {
        $message = $this->getDefaultAccessDeniedMessage('create');
      }

      throw new AccessDeniedHttpException($message);
    }
// TODO Cannot continue until Drupal 8.5 supports file posts.
throw new BadRequestHttpException('File entity creation not yet supported.');
/*
    //
    // Create file
    // -----------
    // Use the dummy entity's name and create a new folder in the parent.
    // Do not auto-rename to avoid name collisions. If the name collides,
    // report it to the user as an error.
    try {
      $file = $parent->addInputFile($filename, FALSE);
    }
    catch (LockException $e) {
      throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
    }
    catch (ValidationException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
    catch (\Exception $e) {
      throw new HttpException(
        Response::HTTP_INTERNAL_SERVER_ERROR,
        $e->getMessage());
    }

    //
    // Log
    // ---
    // Note the creation in the log.
    $this->logger->notice(
      'Uploaded file @name with new ID @id as child of @type folder.',
      [
        '@name' => $file->getName(),
        '@type' => $parent->getEntityTypeId(),
        '@id'   => $file->id(),
      ]);

    //
    // URL
    // ---
    // Create the URL for the updated entity.
    $url = $file->urlInfo(
      'canonical',
      ['absolute' => TRUE])->toString(TRUE);

    $headers = [
      'Location' => $url->getGeneratedUrl(),
    ];

    return new UncacheableResponse(NULL, Response::HTTP_CREATED, $headers);
*/
  }

  /*--------------------------------------------------------------------
   *
   * PATCH response.
   *
   *--------------------------------------------------------------------*/

  /**
   * Responds to PATCH requests.
   *
   * @param int $id
   *   The FolderShare entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function patch(int $id = NULL, EntityInterface $dummy = NULL) {
    //
    // Get operation
    // -------------
    // Use the HTTP header to get the PATCH operation, if any.
    $operation = $this->getAndValidatePatchOperation();

    //
    // Dispatch
    // --------
    // Handle each of the POST operations.
    switch ($operation) {
      case 'update-entity':
        return $this->patchEntity($id, $dummy);

      case 'update-sharing':
        // TODO implement!
        throw new BadRequestHttpException(
          'Sharing update not yet supported.');

      case 'archive':
        // TODO implement!
        throw new BadRequestHttpException(
          'Archive update not yet supported.');

      case 'unarchive':
        // TODO implement!
        throw new BadRequestHttpException(
          'Unarchive update not yet supported.');

      case 'copy-overwrite':
        return $this->patchCopy($id, $dummy, TRUE);

      case 'copy-no-overwrite':
        return $this->patchCopy($id, $dummy, FALSE);

      case 'move-overwrite':
        return $this->patchMove($id, $dummy, TRUE);

      case 'move-no-overwrite':
        return $this->patchMove($id, $dummy, FALSE);
    }
  }

  /**
   * Responds to PATCH requests to update a FolderShare entity.
   *
   * @param int $id
   *   The FolderShare entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  private function patchEntity(int $id = NULL, EntityInterface $dummy = NULL) {
    //
    // Validate dummy entity
    // ---------------------
    // A dummy entity must have been provided and it must have the proper
    // entity type.
    if ($dummy === NULL) {
      throw new BadRequestHttpException(t(
        "Malformed request is missing information about the item to update."));
    }

    if ($dummy->getEntityTypeId() !== FolderShare::ENTITY_TYPE_ID) {
      throw new BadRequestHttpException(t(
        "Malformed request has the wrong entity type for updating a file or folder."));
    }

    if (count($dummy->_restSubmittedFields) === 0) {
      throw new BadRequestHttpException(t(
        "Malformed request that has no entity content."));
    }

    //
    // Find the entity
    // ---------------
    // Check for a header source path. If found, it must provide
    // the path to an existing entity.
    $sourcePath = $this->getSourcePath();

    if (empty($sourcePath) === FALSE) {
      try {
        $id = FolderShare::getEntityIdForPath($sourcePath);
      }
      catch (NotFoundException $e) {
        throw new NotFoundHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    if ($id === (-1)) {
      throw new BadRequestHttpException(t(
        "Malformed request is missing the ID or path of the item to update."));
    }

    $entity = FolderShare::load($id);
    if ($entity === NULL) {
      throw new NotFoundHttpException(t(
        "The file or folder could not be found."));
    }

    //
    // Entity access control
    // ---------------------
    // Check if the current user has permisison to update the current entity.
    $access = $entity->access('update', NULL, TRUE);

    if ($access->isAllowed() === FALSE) {
      // No access. If a reason was not provided, use a default.
      $message = $access->getReason();
      if (empty($message) === TRUE) {
        $message = $this->getDefaultAccessDeniedMessage('update');
      }

      throw new AccessDeniedHttpException($message);
    }

    //
    // Update entity
    // -------------
    // Loop through the dummy entity's fields that were submitted in the
    // PATCH. For each one, check if the user is allowed to update the
    // field. If not, silently skip the field. Otherwise update the field's
    // value.
    //
    // Watch specifically for entity name changes since we need to handle
    // them specially.
    $oldName = $entity->getName();
    $newName = $entity->getName();
    foreach ($dummy->_restSubmittedFields as $fieldName) {
      if ($fieldName === 'name') {
        $newName = $dummy->getName();
      }
      elseif ($entity->get($fieldName)->access('edit') === FALSE) {
        throw new AccessDeniedHttpException(t(
          'Access denied for updating item field "@fieldname".',
          [
            '@fieldname' => $fieldName,
          ]));
      }
      else {
        // Copy new field values.
        $entity->set($fieldName, $dummy->get($fieldName)->getValue());
      }
    }

    $entity->save();

    // If a new name has been provided, rename the entity. This checks
    // for name legality and collisions with other names.
    if ($oldName !== $newName) {
      try {
        $entity->rename($newName);
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
    }

    //
    // Log
    // ---
    // Note the update in the log.
    $this->logger->notice(
      'Updated entity %type with ID %id.',
      [
        '%type' => $entity->getEntityTypeId(),
        '%id'   => $entity->id(),
      ]);

    return $this->formatEntityResponse($entity, TRUE);
  }

  /**
   * Responds to POST requests to copy a FolderShare entity.
   *
   * This method is modeled after the way the Linux/macOS/BSD "cp" command
   * operates. It supports copying an item and copying and renaming an item
   * at the same time.
   *
   * The source path must refer to an existing file or folder to be copied.
   *
   * The destination path may be one of:
   * - A "/" to refer to the top-level folder list.
   * - A path to an existing file or folder.
   * - A path to a non-existant item within an existing parent folder.
   *
   * If destination is "/", the source must refer to a folder since files
   * cannot be copied into "/". The copied folder will have the same name as
   * in the source path. If there is already an item with the same name in
   * "/", the copy will fail unless $overwrite is TRUE.
   *
   * If destination refers to a non-existant item, then the item referred to
   * by source will be copied into the destination's parent folder and
   * renamed to use the last name on destination. If the destination's
   * parent folder is "/", then source must refer to a folder since files
   * cannot be copied into "/".
   *
   * If destination refers to an existing folder, the file or folder referred
   * to by source will be copied into the destination folder and retain its
   * current name. If there is already an item with that name in the
   * destination folder, the copy will fail unless $overwrite is TRUE.
   *
   * If destination refers to an existing file, the copy will fail unless
   * $overwrite is TRUE. If overwrite is allowed, the item referred to by
   * source will be copied into the destination item's parent folder and
   * renamed to have th last name in destination. If destination's parent
   * folder is "/", then source must refer to a folder since files cannot be
   * copied into "/".
   *
   * In any case where an overwrite is required, if $overwrite is FALSE
   * the operation will fail. Otherwise the item to overwrite will be
   * deleted before the copy takes place.
   *
   * The user must have permission to view the item referred to by source.
   * They user must have permission to modify the folder into which the
   * item will be placed, and permission to create the new item there.
   * When copying a folder to "/", the user must have permission to create
   * a new top-level folder. And when overwriting an
   * existing item, the user must have permission to delete that item.
   *
   * @param int $id
   *   The FolderShare entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *   The dummy is ignored.
   * @param bool $overwrite
   *   When TRUE, allow the copy to overwrite a same-name entity at the
   *   destination.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an empty uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if there is no source or destination path,
   *   if either path is malformed, if items are locked, if the user
   *   does not have permission, or if there will be a collision but
   *   $overwrite is FALSE.
   */
  private function patchCopy(
    int $id = NULL,
    EntityInterface $dummy = NULL,
    bool $overwrite = TRUE) {

    //
    // Validation
    // ----------
    // Much of the following code is about validation, which we summarize here:
    //
    // 1. There must be a valid source entity specified by a source path
    //    in the header. The source entity must exist and be loaded.
    //
    // 2. The user must have update access on the source entity.
    //
    // 3. There must be a valid destination path. That path can refer to a
    //    destination in one of three ways:
    //    - The path is / and indicates a root folder list.
    //    - The path indicates an existing entity.
    //    - The path does not indicate an existing entity, but the parent
    //      path does.
    //
    // 4. If the destination is a parent path, then the name at the end
    //    of the original destination path is the proposed new name for the
    //    entity. The name cannot be empty. Later, during the copy, the name
    //    will be checked for legality.
    //
    // 5. If the destination is '/', the user must have root folder create
    //    access. Otherwise the user must have update access on the
    //    destination.
    //
    // 6. If the source is a file, the destination cannot be '/'.
    //
    // 7. If the source is a folder, the destination cannot be a file.
    //
    // 8. If the source collides with an item with the same name in the
    //    destination, $overwrite must be TRUE in order to delete it and
    //    then do the copy. The user also needs delete access to that entity.
    //
    // Find and validate source
    // ------------------------
    // Use the URL id or a header source path to get and load a source entity.
    // This will fail if:
    // - The source path is malformed.
    // - The source path is /.
    // - The source path refers to a non-existent entity.
    $sourceEntity = $this->getSourceAndValidate($id, TRUE);

    // Verify the user has access. This will fail if:
    // - The user does not have view access to the source entity.
    $this->validateAccess($sourceEntity, 'view');

    // Use the current entity name as the proposed new name.
    $destinationName = $sourceEntity->getName();

    //
    // Find the destination
    // --------------------
    // Check for a header destination path. It must exist and provide
    // the path to one of:
    // - /.
    // - An existing file or folder.
    // - An existing parent folder before the last name on the path.
    //
    // Get the destination path.
    $destinationPath = $this->getDestinationPath();
    if (empty($destinationPath) === TRUE) {
      throw new BadRequestHttpException(t(
        "Malformed request is missing a destination path."));
    }

    try {
      // Parse the path. This will fail if:
      // - The destination path is malformed.
      $parts = FolderShare::parseAndValidatePath($destinationPath);
    }
    catch (ValidationException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }

    $destinationId     = (-1);
    $destinationEntity = NULL;
    $destinationName   = '';

    // When the path is NOT '/', it either refers to an existing folder
    // or to a non-existing name within an existing parent folder.
    if ($parts['path'] !== '/') {
      // Try to get the entity. This will fail if:
      // - The destination path refers to a non-existent entity.
      try {
        $destinationId = FolderShare::getEntityIdForPath($destinationPath);
      }
      catch (NotFoundException $e) {
        // The path was parsed but it doesn't point to a valid entity.
        // Back out out folder in the path and try again.
        //
        // Break the path into folders.
        $folders = mb_split('/', $parts['path']);

        // Skip the leading '/'.
        array_shift($folders);

        // Pull out the last name on the path as the proposed name of the
        // new entity. This will fail:
        // - The destination name is empty.
        $destinationName = array_pop($folders);
        if (empty($destinationName) === TRUE) {
          throw new BadRequestHttpException(t(
            "The new name for the copy is empty."));
        }

        // If the folder list is now empty, then we had a path like "/fred".
        // So we're moving into "/" and no further entity loading is required.
        // Otherwise, rebuild the path and try to load the parent folder.
        if (count($folders) !== 0) {
          // Rebuild the path, including the original scheme and UID.
          $parentPath = $parts['scheme'] . '://' . $parts['uid'];
          foreach ($folders as $f) {
            $parentPath .= '/' . $f;
          }

          // Try again. This will fail if:
          // - The parent path refers to a non-existent entity.
          try {
            $destinationId = FolderShare::getEntityIdForPath($parentPath);
          }
          catch (NotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
          }
        }
      }
      catch (ValidationException $e) {
        throw new BadRequestHttpException($e->getMessage());
      }

      // Above, we found one of:
      // - A destination entity with $destinationId set and $destinationName
      //   left empty.
      //
      // - A parent destination entity with $destinationId and
      //   $destinationName set for as the name for the renamed item.
      //
      // - A / parent for the root list with $destinationId left as (-1) and
      //   $destinationName set for as the name for the renamed item.
      //
      // If $destinationId is set, load the entity and verify access.
      if ($destinationId !== (-1)) {
        $destinationEntity = FolderShare::load($destinationId);
        if ($destinationEntity === NULL) {
          throw new NotFoundHttpException(t(
            "The destination file or folder could not be found."));
        }

        // Verify the user has access. This will fail if:
        // - The user does not have update access to a destination entity.
        $this->validateAccess($destinationEntity, 'update');
      }
    }

    // When the path is '/', or if '/' is the parent of a path to a
    // non-existant entity, then verify that the user has access to the
    // root list and permission to create a folder there.
    if ($destinationId === (-1)) {
      // The source is being moved into the root list.  This will fail if:
      // - The root list is not the users own (i.e. private and with the
      //   users own UID).
      switch ($parts['scheme']) {
        case FolderShare::PRIVATE_SCHEME:
          $uid = (int) $parts['uid'];
          if ($uid !== (int) \Drupal::currentUser()->id()) {
            throw new AccessDeniedHttpException(t(
              "Access denied for copying an item into another user's top level folder list."));
          }
          break;

        case FolderShare::PUBLIC_SCHEME:
          throw new AccessDeniedHttpException(t(
            "Access denied for copying an item into the virtual public folder list.\nSet public sharing on the folder instead."));

        case FolderShare::SHARED_SCHEME:
          throw new AccessDeniedHttpException(t(
            "Access denied for copying an item into the virtual shared folder list."));
      }

      // Verify the user has root folder access. This will fail if:
      // - The user does not have create access to create root folders.
      $this->validateAccess(NULL, 'create');
    }

    //
    // Confirm source & destination legality
    // -------------------------------------
    // At this point we have one of these destination cases:
    // - The destination for the copy is a folder in $destinationEntity.
    //   If the item is being renamed, $destinationName is non-empty.
    //
    // - The destination for the copy is the root folder list and
    //   $destinationEntity is NULL. If the item is being renamed,
    //   $destinationName is non-empty.
    //
    // Verify that the source can be added to the destination. This
    // will fail if:
    // - The source is a file but the destination is /.
    // - The source is a folder but the destination is a file.
    // - The source and destination are files but overwrite is not allowed.
    if ($destinationEntity === NULL) {
      // The destination is the root folder list.
      if ($sourceEntity->isFolderOrRootFolder() === FALSE) {
        throw new BadRequestHttpException(t(
          'Items that are not folders cannot be copied to the top-level folder list.'));
      }
    }
    elseif ($destinationEntity->isFolderOrRootFolder() === FALSE) {
      // The destination is a file.
      if ($sourceEntity->isFolderOrRootFolder() === TRUE) {
        throw new BadRequestHttpException(t(
          "Folders cannot be copied into files."));
      }
      elseif ($overwrite === FALSE) {
        throw new BadRequestHttpException(t(
          "A file with the destination name already exists and cannot be overwritten."));
      }
    }

    //
    // Check for collision
    // -------------------
    // A collision occurs if an item in the destination already exists with
    // the same name as the source. This will fail if:
    // - There is a name collision and overwrite is not allowed.
    $collisionEntity = NULL;

    if (empty($destinationName) === TRUE) {
      $checkName = $sourceEntity->getName();
    }
    else {
      $checkName = $destinationName;
    }

    if ($destinationEntity === NULL) {
      // When moving to '/', check if there is a root folder with the
      // proposed name already.
      $uid = \Drupal::currentUser()->id();

      $id = FolderShare::findRootFolderIdByName($checkName, $uid);
      if ($id !== (-1)) {
        // A root folder with the proposed name exists.
        $collisionEntity = FolderShare::load($id);
      }
    }
    elseif ($destinationEntity->isFolderOrRootFolder() === TRUE) {
      // When moving a file to a folder, check the list of the folder's names.
      $id = FolderShare::findFileOrFolderIdByName($checkName, $destinationId);
      if ($id !== (-1)) {
        // A folder with the proposed name exists.
        $collisionEntity = FolderShare::load($id);
      }
    }
    else {
      // When moving a file or folder to a file, the destination file is
      // a collision.
      $collisionEntity = $destinationEntity;
    }

    //
    // Execute the collision delete
    // ----------------------------
    // If $collisionEntity is not NULL, then we have a collision we need to
    // deal with by deleting the entity before doing the copy. But if
    // $overwrite is FALSE, report an error instead.
    if ($collisionEntity !== NULL) {
      if ($overwrite === FALSE) {
        throw new BadRequestHttpException(t(
          "An item with the destination name already exists and cannot be overwritten."));
      }

      // Verify the user can delete the entity. This will fail if:
      // - The user does not have delete access to the collision entity.
      $this->validateAccess($collisionEntity, 'delete');

      // Delete the entity. This will fail if:
      // - Something is locked by another process.
      try {
        $collisionEntity->delete();
      }
      catch (LockException $e) {
        throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
      }
      catch (ValidationException $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
      }
    }

    //
    // Execute the copy
    // ----------------
    // Copy the source to the destination folder, or the root folder list.
    // Provide the new name for the entity, if one was provided.
    //
    // This can fail if:
    // - Something is locked by another process.
    // - There is a name collision (another process added something after we
    //   deleted the collision noted above). This is very unlikely.
    try {
      if ($destinationEntity !== NULL) {
        $copy = $sourceEntity->copyToFolder($destinationEntity, FALSE, $destinationName);
      }
      else {
        $copy = $sourceEntity->copyToRoot(FALSE, $destinationName);
      }
    }
    catch (LockException $e) {
      throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
    }
    catch (ValidationException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
    catch (\Exception $e) {
      throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
    }

    //
    // Log
    // ---
    // Note the update in the log.
    $this->logger->notice(
      'Copied entity %type with ID %id.',
      [
        '%type' => $sourceEntity->getEntityTypeId(),
        '%id'   => $sourceEntity->id(),
      ]);

    //
    // URL
    // ---
    // Create the URL for the updated entity.
    $url = $copy->urlInfo(
      'canonical',
      ['absolute' => TRUE])->toString(TRUE);

    $headers = [
      'Location' => $url->getGeneratedUrl(),
    ];

    return new UncacheableResponse(NULL, Response::HTTP_CREATED, $headers);
  }

  /**
   * Responds to PATCH requests to move a FolderShare entity.
   *
   * This method is modeled after the way the Linux/macOS/BSD "mv" command
   * operates. It supports moving an item, renaming an item in place, or
   * moving and renaming at the same time.
   *
   * The source path must refer to an existing file or folder to be moved
   * and/or renamed.
   *
   * The destination path may be one of:
   * - A "/" to refer to the top-level folder list.
   * - A path to an existing file or folder.
   * - A path to a non-existant item within an existing parent folder.
   *
   * If destination is "/", the source must refer to a folder since files
   * cannot be moved into "/". The moved folder will have the same name as
   * in the source path. If there is already an item with the same name in
   * "/", the move will fail unless $overwrite is TRUE.
   *
   * If destination refers to a non-existant item, then the item referred to
   * by source will be moved into the destination's parent folder and
   * renamed to use the last name on destination. If the destination's
   * parent folder is "/", then source must refer to a folder since files
   * cannot be moved into "/".
   *
   * If destination refers to an existing folder, the file or folder referred
   * to by source will be moved into the destination folder and retain its
   * current name. If there is already an item with that name in the
   * destination folder, the move will fail unless $overwrite is TRUE.
   *
   * If destination refers to an existing file, the move will fail unless
   * $overwrite is TRUE. If overwrite is allowed, the item referred to by
   * source will be moved into the destination item's parent folder and
   * renamed to have th last name in destination. If destination's parent
   * folder is "/", then source must refer to a folder since files cannot be
   * moved into "/".
   *
   * In any case where an overwrite is required, if $overwrite is FALSE
   * the operation will fail. Otherwise the item to overwrite will be
   * deleted before the move and/or rename takes place.
   *
   * The user must have permission to modify the item referred to by source.
   * They user must have permission to modify the folder into which the
   * item will be placed. When moving a folder to "/", the user must have
   * permission to create a new top-level folder. And when overwriting an
   * existing item, the user must have permission to delete that item.
   *
   * @param int $id
   *   The FolderShare entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $dummy
   *   The dummy entity created from incoming unserialized data.
   *   The dummy is ignored.
   * @param bool $overwrite
   *   When TRUE, allow the move to overwrite a same-name entity at the
   *   destination.
   *
   * @return \Drupal\foldershare\Plugin\rest\resource\UncacheableResponse
   *   Returns an empty uncacheable response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws an exception if there is no source or destination path,
   *   if either path is malformed, if items are locked, if the user
   *   does not have permission, or if there will be a collision but
   *   $overwrite is FALSE.
   *
   * @internal
   * At first glance, "move" and "rename" seem like separate operation.
   * Combining them into the same one seems to just complicate things.
   * However, it is in fact necessary.
   *
   * Consider the case where file A exists in folder AA among other files,
   * and it needs to be moved into folder BB and renamed as B. If move and
   * rename are separate steps, the rename must happen before or after the
   * move:
   * - If rename comes before the move, then A must be renamed to B before
   *   B is moved to folder BB. But this requires room in folder AA for a
   *   file named B, even though the file isn't going to stay there.
   * - If rename comes after the move, then A must be moved to folder BB
   *   while still named A, then renamed as B. But this requires room in
   *   folder BB for a file named A, even though the file is about to change
   *   names.
   *
   * With rename and move as separate operations, either the starting folder
   * or the ending folder must have room for both the original name AND the
   * new name. The name space footprint of the file is the union of both
   * names, instead of just one name or the other.
   *
   * Doubling the name space footprint of the file invites spurious name
   * space collisions. It becomes possible that file A cannot be renamed to
   * B in folder AA before the move, and it cannot be moved to BB as A
   * before being renamed to be B. Both cases could get a name space
   * collision. The user would be forced to pick a bogus third name C that
   * doesn't collide with names in AA or BB. That bogus name C would only
   * be used long enough to get the file moved. What a mess.
   *
   * So, "move" and "rename" operations must be combinable so that the
   * name space footprint stays at one name and no bogus intermediate name
   * is needed.
   */
  private function patchMove(
    int $id = NULL,
    EntityInterface $dummy = NULL,
    bool $overwrite = TRUE) {

    //
    // Validation
    // ----------
    // Much of the following code is about validation, which we summarize here:
    //
    // 1. There must be a valid source entity. The source may be specified by
    //    an entity ID in the URL or via a path in a source header. The source
    //    entity must exist and be loaded.
    //
    // 2. The user must have update access on the source entity.
    //
    // 3. There must be a valid destination path. That path can refer to a
    //    destination in one of three ways:
    //    - The path is / and indicates a root folder list.
    //    - The path indicates an existing entity.
    //    - The path does not indicate an existing entity, but the parent
    //      path does.
    //
    // 4. If the destination is a parent path, then the name at the end
    //    of the original destination path is the proposed new name for the
    //    entity. The name cannot be empty. Later, during the move, the name
    //    will checked for legality.
    //
    // 5. If the destination is '/', the user must have root folder create
    //    access. Otherwise the user must have update access on the
    //    destination.
    //
    // 6. If the source is a file, the destination cannot be '/'.
    //
    // 7. If the source is a folder, the destination cannot be a file.
    //
    // 8. If the source collides with an item with the same name in the
    //    destination, $overwrite must be TRUE in order to delete it and
    //    then do the move. The user also needs delete access to that entity.
    //
    // Find and validate source
    // ------------------------
    // Use the URL id or a header source path to get and load a source entity.
    // This will fail if:
    // - The source path is malformed.
    // - The source path is /.
    // - The source path refers to a non-existent entity.
    $sourceEntity = $this->getSourceAndValidate($id, TRUE);

    // Verify the user has access. This will fail if:
    // - The user does not have update access to the source entity.
    $this->validateAccess($sourceEntity, 'update');

    // Use the current entity name as the proposed new name.
    $destinationName = $sourceEntity->getName();

    //
    // Find the destination
    // --------------------
    // Check for a header destination path. It must exist and provide
    // the path to one of:
    // - /.
    // - An existing file or folder.
    // - An existing parent folder before the last name on the path.
    //
    // Get the destination path.
    $destinationPath = $this->getDestinationPath();
    if (empty($destinationPath) === TRUE) {
      throw new BadRequestHttpException(t(
        "Malformed request - the path to the move destination is empty."));
    }

    try {
      // Parse the path. This will fail if:
      // - The destination path is malformed.
      $parts = FolderShare::parseAndValidatePath($destinationPath);
    }
    catch (ValidationException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }

    $destinationId     = (-1);
    $destinationEntity = NULL;
    $destinationName   = '';

    // When the path is NOT '/', it either refers to an existing folder
    // or to a non-existing name within an existing parent folder.
    if ($parts['path'] !== '/') {
      // Try to get the entity. This will fail if:
      // - The destination path refers to a non-existent entity.
      try {
        $destinationId = FolderShare::getEntityIdForPath($destinationPath);
      }
      catch (NotFoundException $e) {
        // The path was parsed but it doesn't point to a valid entity.
        // Back out one folder in the path and try again.
        //
        // Break the path into folders.
        $folders = mb_split('/', $parts['path']);

        // Skip the leading '/'.
        array_shift($folders);

        // Pull out the last name on the path as the proposed name of the
        // new entity. This will fail:
        // - The destination name is empty.
        $destinationName = array_pop($folders);
        if (empty($destinationName) === TRUE) {
          throw new BadRequestHttpException(t(
            "The new name for the move is empty."));
        }

        // If the folder list is now empty, then we had a path like "/fred".
        // So we're moving into "/" and no further entity loading is required.
        // Otherwise, rebuild the path and try to load the parent folder.
        if (count($folders) !== 0) {
          // Rebuild the path, including the original scheme and UID.
          $parentPath = $parts['scheme'] . '://' . $parts['uid'];
          foreach ($folders as $f) {
            $parentPath .= '/' . $f;
          }

          // Try again. This will fail if:
          // - The parent path refers to a non-existent entity.
          try {
            $destinationId = FolderShare::getEntityIdForPath($parentPath);
          }
          catch (NotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
          }
        }
      }
      catch (ValidationException $e) {
        throw new BadRequestHttpException($e->getMessage());
      }

      // Above, we found one of:
      // - A destination entity with $destinationId set and $destinationName
      //   left empty.
      //
      // - A parent destination entity with $destinationId and
      //   $destinationName set as the name for the moved item.
      //
      // - A / parent for the root list with $destinationId left as (-1) and
      //   $destinationName set for as the name for the renamed item.
      //
      // If $destinationId is set, load the entity and verify access.
      if ($destinationId !== (-1)) {
        $destinationEntity = FolderShare::load($destinationId);
        if ($destinationEntity === NULL) {
          // This should not be possible since we already validated the ID.
          throw new NotFoundHttpException(t(
            "The destination file or folder could not be found."));
        }

        // Verify the user has access. This will fail if:
        // - The user does not have update access to a destination entity.
        $this->validateAccess($destinationEntity, 'update');
      }
    }

    // When the path is '/', or if '/' is the parent of a path to a
    // non-existant entity, then verify that the user has access to the
    // root list and permission to create a folder there.
    if ($destinationId === (-1)) {
      // The source is being moved into the root list.  This will fail if:
      // - The root list is not the users own (i.e. private and with the
      //   users own UID).
      switch ($parts['scheme']) {
        case FolderShare::PRIVATE_SCHEME:
          $uid = (int) $parts['uid'];
          if ($uid !== (int) \Drupal::currentUser()->id()) {
            throw new AccessDeniedHttpException(t(
              "Access denied for moving an item into another user's top level folder list."));
          }
          break;

        case FolderShare::PUBLIC_SCHEME:
          throw new AccessDeniedHttpException(t(
            "Access denied for moving an item into the virtual public folder list.\nSet public sharing on the folder instead."));

        case FolderShare::SHARED_SCHEME:
          throw new AccessDeniedHttpException(t(
            "Access denied for moving an item into the virtual shared folder list."));
      }

      // Verify the user has root folder access. This will fail if:
      // - The user does not have create access to create root folders.
      $this->validateAccess(NULL, 'create');
    }

    //
    // Confirm source & destination legality
    // -------------------------------------
    // At this point we have one of these destination cases:
    // - The destination for the move is a folder in $destinationEntity.
    //   If the item is being renamed, $destinationName is non-empty.
    //
    // - The destination for the move is the root folder list and
    //   $destinationEntity is NULL. If the item is being renamed,
    //   $destinationName is non-empty.
    //
    // Verify that the source can be added to the destination. This
    // will fail if:
    // - The source is a file but the destination is /.
    // - The source is a folder but the destination is a file.
    // - The source and destination are files but overwrite is not allowed.
    if ($destinationEntity === NULL) {
      // The destination is the root folder list.
      if ($sourceEntity->isFolderOrRootFolder() === FALSE) {
        throw new BadRequestHttpException(t(
          'Items that are not folders cannot be moved to the top-level folder list.'));
      }
    }
    elseif ($destinationEntity->isFolderOrRootFolder() === FALSE) {
      // The destination is a file.
      if ($sourceEntity->isFolderOrRootFolder() === TRUE) {
        throw new BadRequestHttpException(t(
          "Folders cannot be moved into files."));
      }
      elseif ($overwrite === FALSE) {
        throw new BadRequestHttpException(t(
          "A file with the destination name already exists and cannot be overwritten."));
      }
    }

    //
    // Check for collision
    // -------------------
    // A collision occurs if an item in the destination already exists with
    // the same name as the source. This will fail if:
    // - There is a name collision and overwrite is not allowed.
    $collisionEntity = NULL;

    if (empty($destinationName) === TRUE) {
      $checkName = $sourceEntity->getName();
    }
    else {
      $checkName = $destinationName;
    }

    if ($destinationEntity === NULL) {
      // When moving to '/', check if there is a root folder with the
      // proposed name already.
      $uid = \Drupal::currentUser()->id();

      $id = FolderShare::findRootFolderIdByName($checkName, $uid);
      if ($id !== (-1)) {
        // A root folder with the proposed name exists.
        $collisionEntity = FolderShare::load($id);
      }
    }
    elseif ($destinationEntity->isFolderOrRootFolder() === TRUE) {
      // When moving a file to a folder, check the list of the folder's names.
      $id = FolderShare::findFileOrFolderIdByName($checkName, $destinationId);
      if ($id !== (-1)) {
        // A folder with the proposed name exists.
        $collisionEntity = FolderShare::load($id);
      }
    }
    else {
      // When moving a file or folder to a file, the destination file is
      // a collision.
      $collisionEntity = $destinationEntity;
    }

    //
    // Execute the collision delete
    // ----------------------------
    // If $collisionEntity is not NULL, then we have a collision we need to
    // deal with by deleting the entity before doing the move. But if
    // $overwrite is FALSE, report an error instead.
    if ($collisionEntity !== NULL) {
      if ($overwrite === FALSE) {
        throw new BadRequestHttpException(t(
          "An item with the destination name already exists and cannot be overwritten."));
      }

      // Verify the user can delete the entity. This will fail if:
      // - The user does not have delete access to the collision entity.
      $this->validateAccess($collisionEntity, 'delete');

      // Delete the entity. This will fail if:
      // - Something is locked by another process.
      try {
        $collisionEntity->delete();
      }
      catch (LockException $e) {
        throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
      }
      catch (ValidationException $e) {
        throw new BadRequestHttpException($e->getMessage());
      }
      catch (\Exception $e) {
        throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
      }
    }

    //
    // Execute the move
    // ----------------
    // Move the source to the destination folder, or the root folder list.
    // Provide the new name for the entity, if one was provided.
    //
    // This can fail if:
    // - Something is locked by another process.
    // - There is a name collision (another process added something after we
    //   deleted the collision noted above). This is very unlikely.
    try {
      if ($destinationEntity !== NULL) {
        $sourceEntity->moveToFolder($destinationEntity, $destinationName);
      }
      else {
        $sourceEntity->moveToRoot($destinationName);
      }
    }
    catch (LockException $e) {
      throw new HttpException(Response::HTTP_LOCKED, $e->getMessage());
    }
    catch (ValidationException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
    catch (\Exception $e) {
      throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
    }

    //
    // Log
    // ---
    // Note the update in the log.
    $this->logger->notice(
      'Moved entity %type with ID %id.',
      [
        '%type' => $sourceEntity->getEntityTypeId(),
        '%id'   => $sourceEntity->id(),
      ]);

    return $this->formatEntityResponse($sourceEntity, TRUE);
  }

}
