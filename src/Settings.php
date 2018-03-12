<?php

namespace Drupal\foldershare;

use Drupal\foldershare\Constants;

/**
 * Defines functions to get/set the module's configuration.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * The module's settings (configuration) schema is defined in
 * config/schema/MODULE.settings.yml, with install-time defaults set
 * in config/install/MODULE.settings.yml. The defaults provided
 * in this module match those in the YML files.
 *
 * Configuration settings include:
 *
 * - *_term: module terminology.
 * - file_scheme: public or private files.
 * - file_directory: where files go on server.
 * - file_allowed_extensions: allowed file extensions.
 * - file_restrict_extensions: enable of extension restrictions.
 *
 * @internal
 * Get/set of a module configuration setting requires two strings: (1) the
 * name of the module's configuration, and (2) the name of the setting.
 * When used frequently in module code, these strings invite typos that
 * can cause the wrong setting to be set or retrieved.
 *
 * This class centralizes get/set for settings and turns all settings
 * accesses into class method calls. The PHP parser can then catch typos
 * in method calls and report them as errors.
 *
 * This class also centralizes and makes accessible the default values
 * for all settings.
 * @endinternal
 *
 * @ingroup foldershare
 */
class Settings {

  /*---------------------------------------------------------------------
   *
   * File storage.
   *
   * These functions define defaults and get/set module file storage
   * settings.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the default public/private file storage scheme.
   *
   * Legal values are:
   *
   * - 'public' = store files in the site's public file system.
   * - 'private' = store files in the site's private file system.
   *
   * @return string
   *   The default file storage scheme as either 'public' or 'private'.
   */
  public static function getFileSchemeDefault() {
    // Try to use the Core File module's default choice, if it is
    // one of 'public' or 'private'. If not recognized, revert to 'public'.
    switch (file_default_scheme()) {
      default:
      case 'public':
        return 'public';

      case 'private':
        return 'private';
    }
  }

  /**
   * Returns the module's setting for the public/private file storage scheme.
   *
   * Legal values are:
   *
   * - 'public' = store files in the site's public file system.
   * - 'private' = store files in the site's private file system.
   *
   * @return string
   *   The file storage scheme as either 'public' or 'private'.
   */
  public static function getFileScheme() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('file_scheme') === NULL) {
      return self::getFileSchemeDefault();
    }

    $scheme = $config->get('file_scheme');
    switch ($scheme) {
      case 'public':
      case 'private':
        return $scheme;
    }

    return self::getFileSchemeDefault();
  }

  /**
   * Sets the module's setting for the public/private file storage scheme.
   *
   * Legal values are:
   *
   * - 'public' = store files in the site's public file system.
   * - 'private '= store files in the site's private file system.
   *
   * Unrecognized values are silently ignored.
   */
  public static function setFileScheme(string $scheme) {
    switch ($scheme) {
      case 'public':
      case 'private':
        break;

      default:
        return;
    }

    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
    $config->set('file_scheme', $scheme);
    $config->save(TRUE);
  }

  /**
   * Returns the default subdirectory name for folder files.
   *
   * Subdirectory names are within the site's chosen public
   * or private directory.
   *
   * @return string
   *   The default file subdirectory name.
   */
  public static function getFileDirectoryDefault() {
    return Constants::MODULE;
  }

  /**
   * Returns the module's setting for the subdirectory name for folder files.
   *
   * Subdirectory names are within the site's chosen public
   * or private directory.
   *
   * @return string
   *   The file subdirectory name.
   */
  public static function getFileDirectory() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('file_directory') === NULL) {
      return self::getFileDirectoryDefault();
    }

    $dir = $config->get('file_directory');
    if (empty($dir) === TRUE) {
      return self::getFileDirectoryDefault();
    }

    return $dir;
  }

  /**
   * Sets the module's setting for the subdirectory name for folder files.
   *
   * Subdirectory names are within the site's chosen public
   * or private directory.
   *
   * An empty directory name is legal, but discouraged, since it
   * places all of the module's files directly within the site's
   * primary public or private files directory.
   *
   * Callers should call this class's validateFileDirectory()
   * first to confirm that a proposed directory name is valid
   * and refers to a directory that exists or can be created
   * and written to.
   *
   * @param string $dir
   *   The file subdirectory name.
   *
   * @see validateFileDirectory
   */
  public static function setFileDirectory(string $dir) {
    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
    $config->set('file_directory', $dir);
    $config->save(TRUE);
  }

  /**
   * Validates a proposed directory path to for folder files.
   *
   * The given real path must be an absolute path to the proposed directory.
   * Any use of 'public', 'private', etc., must already have been mapped
   * to a real path.
   *
   * If the path points to something that exists, that something must be
   * a directory.
   *
   * If the directory exists, it must be readable and writable.
   *
   * If the directory does not exist, it must be possible to create the
   * directory and read and write into it.
   *
   * @param string $realPath
   *   The proposed file directory path.
   *
   * @return string
   *   A translated error message if there is a problem, or an
   *   empty string on success.
   */
  public static function validateFileDirectory(string $realPath) {
    if (empty($realPath) === TRUE) {
      return t('The selected directory path is empty.');
    }

    //
    // Find existing parent
    // --------------------
    // Walk backwards up the path until we find a parent that exists.
    $pathExists = $realPath;
    while (file_exists($pathExists) === FALSE) {
      // Get the parent path.
      $pathExists = pathinfo($pathExists, PATHINFO_DIRNAME);

      // If the path is empty, stop.  This should not happen since
      // the given real path should have been built to start at
      // the top-level directory for public or private file storage,
      // which must already exist.
      if (empty($pathExists) === TRUE) {
        return t('The web server file system might not be configured properly because the entire selected directory path does not exist.');
      }
    }

    //
    // Existing directory
    // ------------------
    // Is it a directory, readable, and writable?
    //
    // If we've looped upwards to find an existing parent above, that
    // parent must have these permissions. If it is writable, it should
    // support creating a new subdirectory as well.
    if (is_dir($pathExists) === FALSE) {
      return t('The selected directory name is already in use by a file of the same name.');
    }

    if (is_readable($pathExists) === FALSE) {
      return t("The selected directory does not provide the web server with permission to read it's contents.");
    }

    if (is_writable($pathExists) === FALSE) {
      return t("The selected directory does not provide the web server with permission to write it's contents.");
    }

    return '';
  }

  /*---------------------------------------------------------------------
   *
   * File restrictions.
   *
   * These functions define defaults and get/set module file name
   * extension restrictions and the supported extensions list.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the default setting flagging file name extension restrictions.
   *
   * Legal values are TRUE or FALSE.
   *
   * @return bool
   *   True if file name extensions are restricted, and false otherwise.
   */
  public static function getFileRestrictExtensionsDefault() {
    return FALSE;
  }

  /**
   * Returns the module's setting flagging file name extension restrictions.
   *
   * Legal values are TRUE or FALSE.
   *
   * @return bool
   *   True if file name extensions are restricted, and false otherwise.
   */
  public static function getFileRestrictExtensions() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('file_restrict_extensions') === NULL) {
      return self::getFileRestrictExtensionsDefault();
    }

    $value = $config->get('file_restrict_extensions');
    if ($value === FALSE) {
      return FALSE;
    }

    if ($value === TRUE) {
      return TRUE;
    }

    return self::getFileRestrictExtensionsDefault();
  }

  /**
   * Sets the module's setting flagging file name extension restrictions.
   *
   * Legal values are TRUE or FALSE.
   *
   * Unrecognized values are silently ignored.
   *
   * @param bool $value
   *   True if file name extensions are restricted, and false otherwise.
   */
  public static function setFileRestrictExtensions(bool $value) {
    if ($value !== FALSE && $value !== TRUE) {
      return;
    }

    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
    $config->set('file_restrict_extensions', $value);
    $config->save(TRUE);
  }

  /**
   * Returns the default list of allowed file name extensions.
   *
   * This list is intentionally broad and includes a large set of
   * well-known extensions for text, web, image, video, audio,
   * graphics, data, archive, office, and programming documents.
   *
   * @return string
   *   A string containing a space or comma-separated list of file
   *   extensions (without the leading dot).
   */
  public static function getFileAllowedExtensionsDefault() {
    //
    // Microsoft office
    // ----------------
    // File extensions used by MS Word, Excel, PowerPoint, and Access.
    $microsoftOfficeLegacyExtensions = [
      // Word.
      'doc', 'dot', 'wbk',
      // Excel.
      'xls', 'xlt', 'xlm',
      // Powerpoint.
      'ppt', 'pot', 'pps',
      // Access.
      'ade', 'adp', 'mdb', 'cdb', 'mda', 'mdn', 'mdt', 'mdf',
      'mde', 'ldb',
    ];
    $microsoftOfficeExtensions = [
      // Word.
      'docx', 'docm', 'dotx', 'dotm', 'docb',
      // Excel.
      'xlsx', 'xlsm', 'xltx', 'xltm',
      // Powerpoint.
      'pptx', 'pptm', 'potx', 'potm', 'ppam', 'ppsx', 'ppsm',
      'sldx', 'sldm',
      // Access.
      'adn', 'accdb', 'accdr', 'accdt', 'accda', 'mdw', 'accde',
      'mam', 'maq', 'mar', 'mat', 'maf', 'laccdb',
    ];

    //
    // Other office
    // ------------
    // There are many more current and legacy office applications,
    // but they are much less common that Microsoft Office.
    //
    // Apple's office applications (e.g. Numbers, Pages, Keynote)
    // actually store their content in a special folder, not a file.
    // So uploading them requires creating an archive (e.g. ZIP, TAR).
    // This is outside the scope of this module.
    //
    //
    // Plain and formatted text
    // ------------------------
    // File extensions used generically by a large number of tools.
    $textExtensions = [
      // Plain text.
      'readme', 'txt', 'text', '1st',
      // Formatted text.
      'man', 'rtf', 'tex', 'ltx', 'pdf', 'md',
    ];

    //
    // Web page content
    // ----------------
    // File extensions to build or present web content.
    $webExtensions = [
      // Styles.
      'css', 'less', 'sass', 'scss', 'xsl', 'xsd',
      // Content.
      'htm', 'html', 'rss', 'dtd',
    ];

    //
    // Images
    // ------
    // File extensions for images.
    $imageExtensions = [
      // JPEG and JPEG2000.
      'jpg', 'jpeg', 'jp2', 'j2k', 'jpf', 'jpx', 'jpm',
      // Windows.
      'bmp',
      // Everybody else.
      'png', 'gif', 'tif', 'tiff', 'fits', 'tga', 'ras',
      'ico', 'ppm', 'pgm', 'pbm', 'pnm', 'webp',
    ];

    //
    // Video
    // -----
    // File extensions for videos.
    $videoExtensions = [
      // MPEG.
      'mp4', 'm4v', 'mpg', 'mpv', 'mpeg',
      // Windows.
      'avi', 'wmv',
      // Mac.
      'mov', 'qt',
      // Everybody else.
      'mj2', 'mkv', 'webm',
    ];

    //
    // Audio
    // -----
    // File extensions for audio.
    $audioExtensions = [
      // MPEG.
      'mp3', 'm3u', 'm4a', 'm4p',
      // Windows.
      'wma', 'wav',
      // Everybody else.
      'aiff', 'flac', 'au', 'aac', 'ogg', 'oga', 'mogg',
    ];

    //
    // Graphics (drawing, geometry)
    // ----------------------------
    // File extnesions sued for drawings.
    $graphicsExtensions = [
      // Web.
      'svg',
      // Printing.
      'ps', 'eps',
      // Old standards.
      'cgm',
      // Vendor neutral.
      'dae', 'stl',
      // Vendor specific.
      'dxf', 'blend', '3ds',
    ];

    //
    // Drupal and related
    // ------------------
    // File extensions particular to Drupal.
    $drupalExtensions = [
      'yaml', 'yml', 'twig', 'module', 'install', 'info',
    ];

    //
    // Generic data
    // ------------
    // File extensions used for data, whether text or binary.
    $dataExtensions = [
      // Unspecified format.
      'asc', 'ascii', 'dat', 'data',
      // Comma- and tab-delimited.
      'csv', 'tsv',
      // Science formats.
      'hdf', 'hdf5', 'h5', 'nc', 'fits', 'daq', 'fig',
      // Matlab.
      'mat', 'mn',
      // Web.
      'json', 'xml', 'rdf',
    ];

    //
    // Archive and compression
    // -----------------------
    // File extensions used for archives.
    $archiveExtensions = [
      // Primarily Linux and MacOS.
      'a', 'cpio', 'tar', 'tgz', 'tbz2', 'shar', 'gz', 'z', 'bz2',
      // Windows.
      'cab',
      // MacOS.
      'dmg',
      // General.
      'iso', 'jar', 'rar', 'sit', 'sitx', 'phar', 'zip',
    ];

    //
    // Developer tools and scripts
    // ---------------------------
    // File extensions for software development tools.
    $developerExtensions = [
      'make', 'cmake', 'proj', 'ini', 'config',
      'sh', 'csh', 'bash', 'bat',
    ];

    //
    // Programming languages
    // ---------------------
    // File extensions used for code in assorted programming languages.
    $programmingLanguageExtensions = [
      // C, C++, C#.
      'c', 'c++', 'cp', 'cpp', 'cxx', 'cs', 'csx',
      'h', 'hpp', 'inc', 'include',
      // Everybody else.
      'f', 'p', 'pl', 'prl', 'perl', 'pm', 'py', 'python', 'pyc',
      'php', 'java', 'js', 'jsp', 'asp', 'aspx',
      'swift', 'r', 'm', 'mlv',
      'cgi',
    ];

    //
    // Merge and natural sort
    // ----------------------
    // Merge all of the above extensions into a single large array,
    // then sort them alphabetically.
    $merged = array_merge(
      $microsoftOfficeLegacyExtensions,
      $microsoftOfficeExtensions,
      $textExtensions,
      $webExtensions,
      $imageExtensions,
      $videoExtensions,
      $audioExtensions,
      $graphicsExtensions,
      $drupalExtensions,
      $dataExtensions,
      $archiveExtensions,
      $developerExtensions,
      $programmingLanguageExtensions);
    natsort($merged);

    // Return as a giant space-separated string.
    return implode(' ', $merged);
  }

  /**
   * Returns the module's setting listing allowed file name extensions.
   *
   * The return list is a single string with space-separated dot-free
   * file name extensions allowed for file uploads and renames.
   *
   * @return string
   *   A string containing a space or comma-separated list of file
   *   extensions (without the leading dot).
   */
  public static function getFileAllowedExtensions() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('file_allowed_extensions') === NULL) {
      return self::getFileAllowedExtensionsDefault();
    }

    return $config->get('file_allowed_extensions');
  }

  /**
   * Sets the module's setting listing allowed file name extensions.
   *
   * The given list must be a single string with space-separated dot-free
   * file name extensions allowed for file uploads and renames.
   *
   * Non-string values are silently ignored.
   *
   * @param string $ext
   *   A string containing a space or comma-separated list of file
   *   extensions (without the leading dot).
   */
  public static function setFileAllowedExtensions(string $ext) {
    if (is_string($ext) === FALSE) {
      return;
    }

    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
    $config->set('file_allowed_extensions', $ext);
    $config->save(TRUE);
  }

  /*---------------------------------------------------------------------
   *
   * Sharing.
   *
   * These functions define defaults and get/set for sharing.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns the default sharing enable.
   *
   * @return bool
   *   Returns the default on/off state for sharing.
   */
  public static function getSharingAllowedDefault() {
    return TRUE;
  }

  /**
   * Returns the default sharing enable for anonymous users.
   *
   * @return bool
   *   Returns the default on/off state for sharing.
   */
  public static function getSharingAllowedWithAnonymousDefault() {
    return TRUE;
  }

  /**
   * Returns the module's setting for allowing sharing.
   *
   * When TRUE, the module allows users to share their content with
   * other selected users. When FALSE, sharing is disabled, regardless
   * of any sharing grants set up for specific content.
   *
   * @return bool
   *   Returns whether sharing is currently enabled or disabled.
   *
   * @see ::setSharingAllowed()
   */
  public static function getSharingAllowed() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('sharing_allowed') === NULL) {
      return self::getSharingAllowedDefault();
    }

    return $config->get('sharing_allowed');
  }

  /**
   * Returns the module's setting for allowing sharing with anonymous users.
   *
   * When TRUE, and when content sharing is also TRUE, the module allows
   * users to share their content with the "anonymous" user - any user
   * of the site that has not logged in. When FALSE, sharing with anonymous
   * is disabled, regardless of any sharing grants set up for specific
   * content.
   *
   * @return bool
   *   Returns whether sharing with anonymous is currently enabled or disabled.
   *
   * @see ::getSharingAllowed()
   * @see ::setSharingAllowed()
   * @see ::setSharingAllowedWithAnonymous()
   */
  public static function getSharingAllowedWithAnonymous() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('sharing_allowed_with_anonymous') === NULL) {
      return self::getSharingAllowedWithAnonymousDefault();
    }

    return $config->get('sharing_allowed_with_anonymous');
  }

  /**
   * Sets the module's setting for allowing sharing.
   *
   * When TRUE, the module allows users to share their content with
   * other selected users. When FALSE, sharing is disabled, regardless
   * of any sharing grants set up for specific content.
   *
   * @param bool $enabled
   *   TRUE to enable sharing, FALSE to disable.
   */
  public static function setSharingAllowed(bool $enabled) {
    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
    if ($config->get('sharing_allowed') !== NULL &&
        $config->get('sharing_allowed') === $enabled) {
      // No change.
      return;
    }

    $config->set('sharing_allowed', $enabled);
    $config->save(TRUE);
  }

  /**
   * Sets the module's setting for allowing sharing with anonymous users.
   *
   * When TRUE, and when content sharing is also TRUE, the module allows
   * users to share their content with the "anonymous" user - any user
   * of the site that has not logged in. When FALSE, sharing with anonymous
   * is disabled, regardless of any sharing grants set up for specific
   * content.
   *
   * @param bool $enabled
   *   TRUE to enable sharing with anonymous, FALSE to disable.
   */
  public static function setSharingAllowedWithAnonymous(bool $enabled) {
    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
    if ($config->get('sharing_allowed_with_anonymous') !== NULL &&
        $config->get('sharing_allowed_with_anonymous') === $enabled) {
      // No change.
      return;
    }

    $config->set('sharing_allowed_with_anonymous', $enabled);
    $config->save(TRUE);
  }

  /*---------------------------------------------------------------------
   *
   * Max file size.
   *
   * These functions define defaults and get/set for max upload sizes.
   *
   *---------------------------------------------------------------------*/

  /**
   * Converts a PHP .ini file size shorthand to an integer.
   *
   * In a php.ini file, settings that take a size in bytes support a
   * few shorthands as single letters adjacent to the number:
   * - K = kilobytes.
   * - M = megabytes.
   * - G = gigabytes.
   *
   * This function looks for those letters and multiplies the integer
   * part to get a size in bytes.
   *
   * @param string $value
   *   The php.ini size file, optionally with K, M, or G shorthand letters
   *   at the end for kilobytes, megabytes, or gigabytes.
   *
   * @return int
   *   The integer size in bytes.
   */
  private static function phpIniToInteger(string $value) {
    // Extract the number and letter, if any.
    if (preg_match('/(\d+)(\w+)/', $value, $matches) === 1) {
      switch (strtolower($matches[2])) {
        case 'k':
          return ((int) $matches[1] * 1024);

        case 'm':
          return ((int) $matches[1] * 1024 * 1024);

        case 'g':
          return ((int) $matches[1] * 1024 * 1024 * 1024);
      }
    }

    return (int) $value;
  }

  /**
   * Returns the PHP configured max upload file size.
   *
   * @return int
   *   Returns the PHP maximum file upload size.
   */
  public static function getPHPUploadMaximumFileSize() {
    // Two PHP .ini values limit the maximum upload file size. If either
    // one is not defined, use the documented default.  Then return the
    // lower of these limits.
    $value = ini_get('upload_max_filesize');
    if ($value === FALSE) {
      $phpMaxFileSize = (2 * 1024 * 1024);
    }
    else {
      $phpMaxFileSize = self::phpIniToInteger($value);
    }

    $value = ini_get('post_max_size');
    if ($value === FALSE) {
      $phpMaxFileSize = (8 * 1024 * 1024);
    }
    else {
      $phpMaxPostSize = self::phpIniToInteger($value);
    }

    return ($phpMaxFileSize < $phpMaxPostSize) ?
      $phpMaxFileSize : $phpMaxPostSize;
  }

  /**
   * Returns the PHP configured max upload number.
   *
   * @return int
   *   Returns the PHP maximum number of files uploaded at once.
   */
  public static function getPHPUploadMaximumFileNumber() {
    // One PHP .ini value sets the limit. If it is not defined, use
    // the documented default.
    $value = ini_get('max_file_uploads');
    if ($value === FALSE) {
      return 20;
    }

    return (int) $value;
  }

  /**
   * Returns the default max upload file size.
   *
   * @return int
   *   Returns the default maximum file upload size.
   */
  public static function getUploadMaximumFileSizeDefault() {
    // Default to PHP's settings.
    return self::getPHPUploadMaximumFileSize();
  }

  /**
   * Returns the default max upload number.
   *
   * @return int
   *   Returns the default maximum number of files uploaded at once.
   */
  public static function getUploadMaximumFileNumberDefault() {
    // Default to PHP's settings.
    return self::getPHPUploadMaximumFileNumber();
  }

  /**
   * Returns the module's setting for the max upload file size.
   *
   * @return int
   *   Returns the maximum file upload size.
   */
  public static function getUploadMaximumFileSize() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('upload_max_file_size') === NULL) {
      return self::getUploadMaximumFileSizeDefault();
    }

    return (int) $config->get('upload_max_file_size');
  }

  /**
   * Returns the module's setting for the max upload number.
   *
   * @return int
   *   Returns the maximum number of files uploaded at once.
   */
  public static function getUploadMaximumFileNumber() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('upload_max_file_number') === NULL) {
      return self::getUploadMaximumFileNumberDefault();
    }

    return (int) $config->get('upload_max_file_number');
  }

  /**
   * Sets the module's setting for the max upload file size.
   *
   * @param int $size
   *   The maximum file size. Negative or zero values are ignored.
   */
  public static function setUploadMaximumFileSize(int $size) {
    if ($size > 0) {
      $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
      $config->set('upload_max_file_size', $size);
      $config->save(TRUE);
    }
  }

  /**
   * Sets the module's setting for the max upload number.
   *
   * @param int $number
   *   The maximum number of files to upload at once. Negative or
   *   zero values are ignored.
   */
  public static function setUploadMaximumFileNumber(int $number) {
    if ($number > 0) {
      $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
      $config->set('upload_max_file_number', $number);
      $config->save(TRUE);
    }
  }

  /*---------------------------------------------------------------------
   *
   * Menus and toolbars.
   *
   * These functions define defaults for commands listed in menus and
   * toolbars.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns a list of all available plugin command definitions.
   *
   * The returned list includes all currently installed plugin commands,
   * regardless of the module source for the commands. Depending upon
   * module settings, some of these commands are allowed on the command
   * menu, while others are disabled from the user interface.
   *
   * @return array
   *   Returns an array of plugin command definitions.  Array keys are
   *   command keys, and array values are command definitions.
   *
   * @see ::getAllowedCommandDefinitions()
   */
  public static function getAllCommandDefinitions() {
    // Get the command plugin manager.
    $container = \Drupal::getContainer();
    $manager = $container->get('foldershare.plugin.manager.foldersharecommand');
    if ($manager === NULL) {
      // No manager? Return nothing.
      return [];
    }

    // Get all the command definitions. The returned array has array keys
    // as plugin IDs, and array values as definitions.
    return $manager->getDefinitions();
  }

  /**
   * Returns a list of allowed plugin command definitions.
   *
   * The returned list includes all currently installed plugin commands,
   * regardless of the module source for the commands. This list is then
   * filtered to only include those commands that are currently allowed,
   * based upon module settings for command menu restrictions and content.
   *
   * @return array
   *   Returns an array of plugin command definitions.  Array keys are
   *   command keys, and array values are command definitions.
   */
  public static function getAllowedCommandDefinitions() {
    // Get a list of all installed commands.
    $defs = self::getAllCommandDefinitions();

    // If the command menu is not restricted, return the entire list.
    if (self::getCommandMenuRestrict() === FALSE) {
      return $defs;
    }

    // Otherwise the menu is restricted. Get the current list of allowed
    // command IDs.
    $ids = self::getCommandMenuAllowed();

    // Cull the list of definitions to only those allowed on the command
    // menu.
    $allowed = [];
    foreach ($defs as $id => $def) {
      if (in_array($id, $ids) === TRUE) {
        $allowed[$id] = $def;
      }
    }

    return $allowed;
  }

  /**
   * Returns the default setting flagging command menu restrictions.
   *
   * @return bool
   *   TRUE if the command menu is restricted, and FALSE otherwise.
   */
  public static function getCommandMenuRestrictDefault() {
    return FALSE;
  }

  /**
   * Returns the module's setting flagging command menu restrictions.
   *
   * When FALSE, all available plugin commands are allowed on the user
   * interface's command menu. When TRUE, this list is restricted to only
   * the commands selected by the site administrator.
   *
   * @return bool
   *   TRUE if the command menu is restricted, and FALSE otherwise.
   *
   * @see ::getCommandMenuRestrictDefault()
   * @see ::setCommandMenuRestrict()
   */
  public static function getCommandMenuRestrict() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('command_menu_restrict') === NULL) {
      return self::getCommandMenuRestrictDefault();
    }

    $value = $config->get('command_menu_restrict');
    if ($value === FALSE) {
      return FALSE;
    }

    if ($value === TRUE) {
      return TRUE;
    }

    return self::getCommandMenuRestrictDefault();
  }

  /**
   * Sets the module's setting flagging command menu restrictions.
   *
   * When FALSE, all available plugin commands are allowed on the user
   * interface's command menu. When TRUE, this list is restricted to only
   * the commands selected by the site administrator.
   *
   * @param bool $value
   *   TRUE if the command menu is restricted, and FALSE otherwise.
   *
   * @see ::getCommandMenuRestrict()
   */
  public static function setCommandMenuRestrict(bool $value) {
    if ($value !== FALSE && $value !== TRUE) {
      return;
    }

    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);
    $config->set('command_menu_restrict', $value);
    $config->save(TRUE);
  }

  /**
   * Returns the default list of allowed plugin commands for menus.
   *
   * The returned list is an array of command plugin IDs. By default,
   * this list includes all command plugins currently installed.
   *
   * @return array
   *   Returns an array of plugin command IDs.
   */
  public static function getCommandMenuAllowedDefault() {
    return array_keys(self::getAllCommandDefinitions());
  }

  /**
   * Returns the list of allowed plugin commands for menus.
   *
   * The returned list is an array of command plugin IDs. If modules with
   * commands have been uninstalled since this list was last set, then the
   * list may include IDs for commands that are no longer available.
   *
   * @return array
   *   Returns an array of plugin command IDs.
   */
  public static function getCommandMenuAllowed() {
    $config = \Drupal::config(Constants::SETTINGS);
    if ($config->get('command_menu_allowed') === NULL) {
      // Nothing set yet. Revert to default.
      return self::getCommandMenuAllowedDefault();
    }

    $ids = $config->get('command_menu_allowed');
    if (is_array($ids) === TRUE) {
      return $ids;
    }

    // The stored value is bogus. Reset it to the default.
    $ids = self::getCommandMenuAllowedDefault();
    self::setCommandMenuAllowed($ids);
    return $ids;
  }

  /**
   * Sets the allowed list of plugin commands for menus.
   *
   * The given list is an array of command plugin IDs.
   *
   * @param array $ids
   *   An array of plugin command IDs.
   */
  public static function setCommandMenuAllowed(array $ids) {
    $config = \Drupal::configFactory()->getEditable(Constants::SETTINGS);

    if (empty($ids) === TRUE || is_array($ids) === FALSE) {
      // Set the menu to be an empty list.
      $config->set('command_menu_allowed', []);
      $config->save(TRUE);
    }
    else {
      $config->set('command_menu_allowed', $ids);
      $config->save(TRUE);
    }
  }

}
