<?php

namespace Drupal\foldershare\Entity;

use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Url;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;

/**
 * Provides URI-to-URL and URI-to-local path mappings for FolderShare files.
 *
 * FolderShare entities may describe files or folders. For files, the
 * entity wraps a Drupal core File entity, which in turn manages a file
 * stored on the local file system.
 *
 * FolderShare manages two versions of the storage directory tree and their
 * associated directory path and file name:
 *
 * - The public user-visible version of the tree contains nested folders with
 *   user-chosen folder and file names. This version is shown in the
 *   user interface.
 *
 * - The private system-visible version of the tree contains nested local
 *   directories with internally-generated numeric directory and file names.
 *   This version is never shown to the user.
 *
 * Every public user-visible file has a corresponding private system-visible
 * file that can be downloaded, deleted, etc.
 *
 * Public user-visible folders, however, exist entirely within the database
 * via entity references to group together files and other folders.
 *
 * The private system-visible directories are strictly for file management
 * and do not have a one-to-one correspondance with user-visible folders.
 *
 * There are three types of path for these two directory tree versions.
 *
 * - A Drupal URI contains a scheme name followed by path-like arguments
 *   that name a file. URIs are typically only visible within Drupal and
 *   module code and are not shown to a user.
 *
 * - An external URL contains an "http" or "https" scheme followed by
 *   path-like arguments that name a file. URLs are visible to browsers
 *   and users.
 *
 * - An internal real path contains an absolute file path to a stored file
 *   that can be opened, read, written, or deleted. Real paths are never
 *   shown to the user.
 *
 * While typically the URI, URL, and real file path share some elements
 * (such as directory or path names), this is not required. This stream
 * wrapper uses three separate forms:
 *
 * - A URI for this stream wrapper has the form: "foldershare://ID/FILENAME"
 *   where:
 *   - ID is the File entity ID of a file.
 *   - FILENAME is the user-visible name of the file.
 *
 * - An external URL for this stream wrapper has the form:
 *   "http://ROUTE/ID" where ROUTE is the route to a download handler
 *   and ID is the File entity ID of the file to access. The download
 *   handler does access control before returning the file's bytes.
 *
 * - An internal real path for this stream wrapper has the form:
 *   "/BASEPATH/SUBPATH/DIRNAMES/LOCALFILENAME" where:
 *   - BASEPATH is the public or private file system path for the site.
 *   - SUBPATH is the name of a subdirectory for uploaded files.
 *   - DIRNAMES is a series of numeric directory names for a stored file.
 *   - LOCALFILENAME is a numeric name for the file, without an extension.
 *
 * This stream wrapper handles mappings to/from each of these types of path.
 * Principal methods include:
 *
 * - setUri() to set the URI to handle.
 * - getUri() to get the URI being handled.
 *
 * - realpath() to convert the URI to an absolute real local file path.
 * - getExternalUrl() to convert the URI to an external URL.
 *
 * <B>Wrapper name</B>
 * The stream wrapper is registered in the FolderShare module's YML services
 * file and given the stream name "foldershare".
 *
 * <B>Wrapper purpose</B>
 * The public folder path and local stored directory path are different
 * for a number of reasons to better support security, performance, and
 * scalability:
 *
 * * File and folder names may be changed by the user. By keeping those names
 *   strictly as artifacts in a database, the underlying stored files do not
 *   have to be moved, which saves file system accesses.
 *
 * * Files and folders may be moved, which changes the path to them. By keeping
 *   this hierarchy strictly in the database, the actual stored path to a
 *   file need never change. This saves on file system accesses.
 *
 * * File and folder names may each be up to 255 characters long and use
 *   Unicode (and even Emoji). The underlying file system may not allow this
 *   length of names and paths, or this character set. By keeping the
 *   user-visible names strictly in the database, the limitations of the
 *   underlying file system are avoided.
 *
 * * Some files will be accessed much more frequently than others, but some
 *   file systems slow down if there are a large number of files in the same
 *   directory. By keeping file and folder paths strictly as a database
 *   artifact, the files can be stored in a directory tree with a fixed low
 *   maximum file count per directory, and thereby avoid file system slowdowns.
 *
 * * Some folders will have many more files than other folders. As above,
 *   some file systems slow down with very large directories. By keeping file
 *   and folder hierarchies in the database only, files can be stored any
 *   way we like to distribute load and avoid slowdowns.
 *
 * * Some files are treated specially by a web server, such as ".php" files.
 *   Users need to be able to upload and download these files, and site
 *   administrators need to not be concerned about this. By naming stored
 *   files without an extension, the web server never sees a special file.
 *   The user-visible file name is strictly in the database.
 *
 * For all of these reasons, at least, FolderShare maintains database entries
 * that give user-visible file names and the folder hierarchy, but on disk
 * the files and directories are synthetic and chosen to use short names,
 * short paths, ASCII characters, and a load-balanced directory structure.
 *
 * The local stored files and directory names should not be visible to the
 * user. This means we need a mapping from user-visible file and folder
 * names to actual stored names. This stream wrapper accomplishes this.
 *
 * Additionally, some parts of Drupal look at the stored file URI for a
 * File entity and expect to find a valid file name on the end that matches
 * the user-visible file name. This prevents us from storing our local file
 * paths in the File object URI. Instead, we need to store the user-visible
 * name in that URI, and use this stream wrapper to map that to the local
 * file path.
 *
 * @internal
 * FolderShare stream paths are of the form "foldershare://ID/USERFILENAME".
 *
 * - ID is the numeric File entity ID for the file.
 *
 * - FILENAME is the user-visible file name, with an extension, for the file.
 *
 * Local file system paths are generated from stream paths and have the
 * form "/BASEPATH/SUBPATH/DIRNAMES/LOCALFILENAME".
 *
 * - BASEPATH is the web site's base path to either the public or private
 *   file system, based upon the module's site settings.
 *
 * - SUBPATH is the subdirectory name for the module's files, based upon
 *   the module's site settings.
 *
 * - DIRNAMES are a series of directory names derived from the entity ID
 *   (see below).
 *
 * - LOCALFILENAME is a file name, without an extension, derived from the
 *   entity ID (see below).
 *
 * Each DIRNAME and the final LOCALFILENAME are entirely numeric and work
 * together to build a File entity ID. Each DIRNAME provides a sequence
 * of zero-padded N digits, while LOCALFILENAME provides the last zero-padded
 * N digits. Together N+N+...+N = 20 digits, which is sufficient to
 * represent a 64-bit entity ID in base 10.
 *
 * The number of digits per directory name is set by the
 * Constants::DIGITS_PER_SERVER_DIRECTORY_NAME constant.
 *
 * For example:  For Constants::DIGITS_PER_SERVER_DIRECTORY_NAME = 4
 * (10,000 files per directory), the File entity 123,456 has this path:
 * "/BASEPATH/SUBPATH/0000/0000/0000/0012/3456" where BASEPATH and SUBPATH
 * are per the site's module settings.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShare
 */
class FolderShareStream extends LocalStream {

  /*---------------------------------------------------------------------
   *
   * Class characteristics.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    // The wrapper describes files with these attributes:
    // - LOCAL = stored on the local file system.
    // - READ = readable.
    // - WRITE = writable.
    // - VISIBLE = web accessible.
    //
    // StreamWrapperInterface defines:
    // - LOCAL_NORMAL = (LOCAL | READ | WRITE | VISIBLE).
    return StreamWrapperInterface::LOCAL_NORMAL;
  }

  /*---------------------------------------------------------------------
   *
   * Stream characteristics.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getName() {
    // This is the name used in the user interface.
    return t('FolderShare files');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    // This is the longer description used in the user interface.
    return t('Files managed and served by the FolderShare module.');
  }

  /*---------------------------------------------------------------------
   *
   * Path building.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the base path for the "foldershare://" stream.
   *
   * The returned path is of the form BASEPATH/SUBPATH where BASEPATH is
   * the base path for either public or private files and SUBPATH is a
   * subdirectory within the base path. The public/private choice and
   * the SUBPATH are chosen by the site administrator in the module's settings.
   *
   * @return string
   *   Returns the base path for the "foldershare://" stream.
   */
  private static function basePath() {
    // Get the public or private base path, depending upon which file system
    // has been configured for use with this module.
    switch (Settings::getFileScheme()) {
      case 'public':
        $basePath = PublicStream::basePath();
        break;

      case 'private':
        $basePath = PrivateStream::basePath();
        break;

      default:
        // Unknown and not supported.
        return FALSE;
    }

    // Use module settings for the subdirectory path into which the module
    // places uploaded files.
    $subPath = Settings::getFileDirectory();

    // Return a base path.
    return $basePath . '/' . $subPath;
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    // Return the base path. This is the top level directory into which
    // uploaded files are stored. This *does not* include the DIRNAMEs part
    // of the path, which depends upon the entity ID in the URI.
    return self::basePath();
  }

  /**
   * Parses a URI and returns the embedded File entity ID.
   *
   * URI's for this stream are expected to have the form
   * "foldershare://ID/FILENAME", where ID is the File entity ID,
   * and FILENAME is a user-visible file name.
   *
   * The entity ID is all we need in order to build the absolute local
   * path to the file or create an external URL for the file. This method
   * parses the URI and returns that ID, or FALSE if the URI could not
   * be parsed.
   *
   * The FILENAME part of the URI, and anything else that follows it, is
   * ignored.
   *
   * @param string $uri
   *   (optional, default = NULL) The URI to parse. If NULL, defaults to
   *   the URI set using setUri().
   *
   * @return int|bool
   *   Returns an integer File entity ID, or FALSE if the URI could
   *   not be parsed to yield an entity ID.
   */
  private function getEntityId($uri = NULL) {
    // Use the stored URI if no specific URI is given.
    if (isset($uri) === FALSE) {
      $uri = $this->getUri();
    }

    // Parse the URI.
    list(, $target) = explode('://', $uri, 2);
    $parts = explode('/', $target);

    if (count($parts) !== 2) {
      // Malformed URI. There must be only the ID and FILENAME parts, and
      // neither may contain an embedded slash.
      return FALSE;
    }

    return intval($parts[0]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getTarget($uri = NULL) {
    // This method is called by the parent class's implementation of
    // public methods including:
    // - dirname().
    // - mkdir().
    //
    // In both cases, the returned target path includes subdirector names
    // and the name of a file within the directory path for the stream's
    // files.
    //
    // This is therefore where we use the entity ID to create the DIRNAMES
    // and LOCALFILENAME portions of the path to a local file.
    $entityId = $this->getEntityId($uri);
    if ($entityId === FALSE) {
      // The entity ID could not be parsed from the current URI.
      return FALSE;
    }

    // Use the entity ID to construct a directory path of the form
    // DIR/DIR/.../DIR/LOCALFILENAME where the number of digits for each
    // DIR is DIGITS_PER_SERVER_DIRECTORY_NAME.  The maximum number of
    // digits for a 64-bit entity ID is 20.
    $digits = sprintf('%020d', $entityId);
    $path = '';
    for ($pos = 0; $pos < 20;) {
      $path .= '/';
      for ($i = 0; $i < Constants::DIGITS_PER_SERVER_DIRECTORY_NAME; ++$i) {
        $path .= $digits[$pos++];
      }
    }

    // Remove erroneous leading or trailing forward-slashes or backslashes.
    return trim($path, '\/');
  }

  /**
   * {@inheritdoc}
   */
  protected function getLocalPath($uri = NULL) {
    // This method is called by the parent class's implementation of
    // public methods including:
    // - stream_open().
    // - stream_metadata().
    // - unlink().
    // - rename().
    // - mkdir().
    // - rmdir().
    // - url_stat().
    // - dir_opendir().
    //
    // and most importantly:
    // - realpath(), which returns the absolute file path to the file.
    //
    // The returned local path is therefore a real absolute path to a file,
    // or to where a file can be placed (the parent directories must exist).
    //
    // Build the path to the file and see if everything exists.
    $target = $this->getTarget($uri);
    $path = $this->getDirectoryPath() . '/' . $target;
    $fullRealPath = realpath($path);

    if ($fullRealPath !== FALSE) {
      // The directories and file all exist.
      return $fullRealPath;
    }

    // The file does not exist or one or more of the parent directories
    // do not exist. Try to create the parent directories.
    $dirPath = dirname($path);
    $dirRealPath = realpath($dirPath);
    if ($dirRealPath === FALSE) {
      // Parent directories don't exist.
      if (file_prepare_directory(
        $dirPath,
        (FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) === FALSE) {
        // The parent directories could not be created.
        return FALSE;
      }

      // Ensure the directory has an .htaccess file that locks it down.
      file_save_htaccess($dirRealPath, TRUE, FALSE);

      $dirRealPath = realpath($dirPath);
      if ($dirRealPath === FALSE) {
        // Still failed. Something is wrong with the parent directories.
        return FALSE;
      }
    }

    // The parent directories now all exist, though the file does not.
    // Just append the file's name to the end of the directory path.
    return $dirRealPath . '/' . basename($target);
  }

  /*---------------------------------------------------------------------
   *
   * External URL building.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    // Return an external URL that may be used to get the file. This must
    // support FolderShare's access controls, so we send this to a download
    // route which requires a File entity ID. The ID is looked up to get
    // the corresponding FolderShare entity ID, then access controls checked.
    //
    // Parse the URI to get the entity ID.
    $entityId = $this->getEntityId();
    if ($entityId === FALSE) {
      // The entity ID could not be parsed from the current URI.
      return FALSE;
    }

    // And return a URL based upon the download route.
    $url = Url::fromRoute(
      Constants::ROUTE_DOWNLOADFILE,
      [
        'file' => $entityId,
      ],
      [
        'absolute' => TRUE,
      ]);
    return $url->toString();
  }

}
