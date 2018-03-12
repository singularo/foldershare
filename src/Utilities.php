<?php

namespace Drupal\foldershare;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\ExtensionInstallStorage;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Defines utility functions used throughout the module.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This class defines a variety of utility functions in several groups:
 * - Create links from routes or for help or Drupal documentation pages.
 * - Create standardized content.
 * - Create standard terminology text.
 * - Revert configurations.
 * - Help search indexing.
 *
 * @ingroup foldershare
 */
class Utilities {

  /*--------------------------------------------------------------------
   *
   * Link functions.
   *
   * These functions assist in creating formatted links to other pages
   * at this site, or elsewhere on the web.
   *
   *-------------------------------------------------------------------*/

  /**
   * Returns an HTML anchor to a routed page.
   *
   * If no title is given, the route's title is used.
   *
   * If the route cannot be found, plain text is returned that contains
   * the title (if given) or the route name (if no title given).
   *
   * @param string $routeName
   *   The name of a route for an enabled module.
   * @param string $target
   *   (optional) The name of a page fragment target (i.e. a "#name",
   *   sans '#').
   * @param string $title
   *   (optional) The title for the link to the route. If empty, the route's
   *   title is used.
   *
   * @return string
   *   The HTML markup for a link to the module's page.
   */
  public static function createRouteLink(
    string $routeName,
    string $target = '',
    string $title = '') {

    // Set up the options array, if a target was provided.
    $options = [];
    if (empty($target) === FALSE) {
      $options['fragment'] = $target;
    }

    // Get the route's title, if no title was provided.
    if (empty($title) === TRUE) {
      $p = \Drupal::service('router.route_provider');
      if ($p === NULL) {
        $title = $routeName;
      }
      else {
        $title = $p->getRouteByName($routeName)->getDefault('_title');
      }
    }
    else {
      $title = $title;
    }

    // Create the link.
    $lnk = Link::createFromRoute($title, $routeName, [], $options);
    return ($lnk === NULL) ? $title : $lnk->toString();
  }

  /**
   * Returns an HTML anchor to a routed help page.
   *
   * If no title is given, the route's title is used.
   *
   * If the route cannot be found, plain text is returned that contains
   * the title (if given) or the route name (if no title given).
   *
   * @param string $moduleName
   *   (optional) The name of a module target.
   * @param string $title
   *   (optional) The title for the link to the route. If empty, the route's
   *   title is used.
   *
   * @return string
   *   The HTML markup for a link to the module's page.
   */
  public static function createHelpLink(
    string $moduleName,
    string $title = '') {

    // If the help module is not installed, just return the title.
    $mh = \Drupal::service('module_handler');
    if ($mh->moduleExists('help') === FALSE) {
      if (empty($title) === FALSE) {
        return Html::escape($title);
      }

      return Html::escape($moduleName);
    }

    // Set up the options array, if a moduleName was provided.
    $options = [];
    if (empty($moduleName) === FALSE) {
      $options['name'] = $moduleName;
    }

    // Get the route's title, if no title was provided.
    $routeName = Constants::ROUTE_HELP;
    if (empty($title) === TRUE) {
      $p = \Drupal::service('router.route_provider');
      if ($p === NULL) {
        $title = Html::escape($routeName);
      }
      else {
        $title = $p->getRouteByName($routeName)->getDefault('_title');
      }
    }

    // Create the link.
    $lnk = Link::createFromRoute($title, $routeName, $options);
    return ($lnk === NULL) ? $title : $lnk->toString();
  }

  /**
   * Returns an HTML anchor to a URL.
   *
   * @param string $path
   *   The text URL path.
   * @param string $title
   *   (optional) The title for the link to the page. If empty, the module
   *   name is used.
   *
   * @return string
   *   The HTML markup for a link to the module's documentation.
   */
  public static function createUrlLink(string $path, string $title = '') {
    if (empty($title) === TRUE) {
      $title = Html::escape($path);
    }
    else {
      $title = Html::escape($title);
    }

    $lnk = Link::fromTextAndUrl($title, Url::fromUri($path));

    return ($lnk === NULL) ? $title : $lnk->toString();
  }

  /**
   * Returns an HTML anchor to a Drupal.org module documentation page.
   *
   * If the link cannot be created, plain text is returned that contains
   * the given name only.
   *
   * @param string $moduleName
   *   The name of the module at Drupal.org.
   * @param string $title
   *   (optional) The title for the link to the page. If empty, the module
   *   name is used.
   *
   * @return string
   *   The HTML markup for a link to the module's documentation.
   */
  public static function createDocLink(string $moduleName, string $title = '') {
    if (empty($title) === TRUE) {
      $title = Html::escape($moduleName);
    }
    else {
      $title = Html::escape($title);
    }

    $lnk = Link::fromTextAndUrl(
      $title,
      Url::fromUri(Constants::URL_DRUPAL_CORE_MODULE_DOC . $moduleName));

    return ($lnk === NULL) ? $title : $lnk->toString();
  }

  /**
   * Returns an HTML anchor to the SDSC home page.
   *
   * @return string
   *   The HTML markup for a link to the site.
   */
  public static function createSDSCLink() {
    return self::createUrlLink(
      Constants::URL_SDSC_HOME,
      'San Diego Supercomputer Center (SDSC)');
  }

  /**
   * Returns an HTML anchor to the UCSD home page.
   *
   * @return string
   *   The HTML markup for a link to the site.
   */
  public static function createUCSDLink() {
    return self::createUrlLink(
      Constants::URL_UCSD_HOME,
      'University of California at San Diego (UCSD)');
  }

  /**
   * Returns an HTML anchor to the NSF home page.
   *
   * @return string
   *   The HTML markup for a link to the site.
   */
  public static function createNSFLink() {
    return self::createUrlLink(
      Constants::URL_NSF_HOME,
      'National Science Foundation (NSF)');
  }

  /*--------------------------------------------------------------------
   *
   * Content.
   *
   * These functions create content pieces that may be included on
   * other pages.
   *
   *-------------------------------------------------------------------*/

  /**
   * Creates a folder path with links to ancestor folders.
   *
   * The path to the given item is assembled as an ordered list of HTML
   * links, starting with a link to the root folder list and ending with
   * the parent of the given folder. The folder itself is not included
   * in the path.
   *
   * Links on the path are separated by the given string, which defaults
   * to '/'.
   *
   * If the item is NULL or a root folder, the returned path only contains
   * the link to the root folder list.
   *
   * @param \Drupal\foldershare\FolderShareInterface $item
   *   (optional) The item to create the path for, or NULL to create a path
   *   for to the root folder list.
   * @param string $separator
   *   (optional, default '/') The text to place between links on the path.
   * @param bool $includeFolder
   *   (optional, default FALSE) When TRUE, include the folder itself at
   *   the end of the path.
   * @param bool $createLinks
   *   (optional, default TRUE) When TRUE, create a link to each folder on
   *   the path.
   *
   * @return string
   *   The HTML for a series of links to the item's ancestors.
   */
  public static function createFolderPath(
    FolderShareInterface $item = NULL,
    string $separator = ' / ',
    bool $includeFolder = TRUE,
    bool $createLinks = TRUE) {
    //
    // Get a link to the root folder list.
    if ($createLinks === TRUE) {
      $rootList = Utilities::createRouteLink(
        Constants::ROUTE_PERSONAL_ROOT_LIST);
    }
    else {
      $p = \Drupal::service('router.route_provider');
      if ($p === NULL) {
        $rootList = t('Folders');
      }
      else {
        $rootList = t($p->getRouteByName(Constants::ROUTE_PERSONAL_ROOT_LIST)->getDefault('_title'));
      }
    }

    //
    // Root list only
    // --------------
    // When there is no item given, only show a link to the root list.
    if ($item === NULL) {
      return $rootList;
    }

    //
    // Root folder only
    // ----------------
    // When the given item is a root folder, show the root list and
    // optionally the root folder.
    if ($item->isRootFolder() === TRUE) {
      if ($includeFolder === FALSE) {
        return $rootList;
      }

      // Fall thru and let the code below handle the root folder alone.
    }

    //
    // Subfolders
    // ----------
    // For other items, show a chain of links starting with
    // the root list, then root folder, then ancestors down to (but not
    // including) the item itself.
    $links = [];
    $folders = [];

    if ($item->isRootFolder() === FALSE) {
      // Get folder IDs of ancestors of this item.  The list does not
      // include this item.  The first entry in the list is this
      // folder's parent.
      $ancestorIds = $item->getAncestorFolderIds();

      // Reverse the folder ID list so that the first one is the root folder.
      $folderIds = array_reverse($ancestorIds);

      // Load all the folders.
      $folders = FolderShare::loadMultiple($folderIds);

      // Add the item itself, if needed.
      if ($includeFolder === TRUE) {
        $folders[] = $item;
      }
    }
    else {
      $folders[] = $item;
    }

    // Get the current user.
    $account = \Drupal::currentUser();

    // Loop through the folders, starting at the root. For each one,
    // create a link to the folder's page.
    foreach ($folders as $id => $f) {
      if ($createLinks === FALSE || $item->id() === $id ||
          $f->access('view', $account) === FALSE) {
        // Either we aren't creating links, or the item in the list is the
        // current folder, or the user doesn't have view access.  In any
        // case, just show the item as plain text, not a link.
        //
        // The folder name needs to be escaped manually here.
        $links[] = Html::escape($item->getName());
      }
      else {
        // Create a link to the folder.
        //
        // No need to HTML escape the view title here. This is done
        // automatically by Link.
        $links[] = Link::createFromRoute(
          $f->getName(),
          Constants::ROUTE_FOLDERSHARE,
          [Constants::ROUTE_FOLDERSHARE_ID => $id])->toString();
      }
    }

    // Create the markup.
    return $rootList . $separator . implode($separator, $links);
  }

  /**
   * Returns a standard module footer that includes relevant logos and links.
   *
   * Module pages shown to administrators may include a footer that
   * provides logos and links to brand the module.
   *
   * @return array
   *   The returned form/render element array creates the module footer.
   */
  public static function createModuleFooter() {
    // Get the path to icons.
    $moduleName = \Drupal::moduleHandler()->getName(Constants::MODULE);
    $md         = Constants::MODULE;
    $path       = '/' . drupal_get_path('module', $md) . '/images';

    // Create HTML for a footer.
    $logoHtml = '<div class="' . $md . '-admin-footer-icons">';
    $logoHtml .= '<a href="' . Constants::URL_UCSD_HOME .
      '"><img src="' . $path . '/logo-ucsd.101x72.png"></a>';
    $logoHtml .= '<a href="' . Constants::URL_SDSC_HOME .
      '"><img src="' . $path . '/logo-sdsc.72x72.png"></a>';
    $logoHtml .= '<a href="' . Constants::URL_NSF_HOME .
      '"><img src="' . $path . '/logo-nsf.72x72.png"></a>';
    $logoHtml .= '</div>';

    return [
      '#type'   => 'item',
      '#weight' => 10000,
      '#prefix' => '<div class="' . $md . '-admin-footer">',
      '#suffix' => '</div>',
      '#markup' => '<p>' . t(
        'The @module module was developed by the @sdsc at the @ucsd with funding from the @nsf.',
        [
          '@module' => $moduleName,
          '@sdsc'   => self::createSDSCLink(),
          '@ucsd'   => self::createUCSDLink(),
          '@nsf'    => self::createNSFLink(),
        ]) . '</p>' . $logoHtml,
    ];
  }

  /*--------------------------------------------------------------------
   *
   * Terminology.
   *
   * These functions return standardized terminology text.
   *
   *-------------------------------------------------------------------*/

  /**
   * Returns a mapping from a FolderShare entity kind to a singular term.
   *
   * Standard entity kinds are mapped to their corresponding terms,
   * as set on the module's settings page.
   *
   * @param string $kind
   *   The kind of a FolderShare entity.
   * @param int $caseMode
   *   (optional) The mix of case for the returned value. One of
   *   MB_CASE_UPPER, MB_CASE_LOWER, or MB_CASE_TITLE (default).
   *
   * @return string
   *   The user-visible term for the singular kind.
   */
  public static function mapKindToTerm(
    string $kind,
    int $caseMode = MB_CASE_TITLE) {

    $term = '';
    switch ($kind) {
      case FolderShare::FILE_KIND:
        $term = t('file');
        break;

      case FolderShare::IMAGE_KIND:
        $term = t('image');
        break;

      case FolderShare::MEDIA_KIND:
        $term = t('media');
        break;

      case FolderShare::FOLDER_KIND:
        $term = t('folder');
        break;

      case FolderShare::ROOT_FOLDER_KIND:
        $term = t('top-level folder');
        break;

      default:
        $term = t('item');
        break;
    }

    return mb_convert_case($term, $caseMode);
  }

  /**
   * Returns a mapping from a FolderShare entity kind to a plural term.
   *
   * Standard entity kinds are mapped to their corresponding terms,
   * as set on the module's settings page.
   *
   * @param string $kind
   *   The kind of a FolderShare entity.
   * @param int $caseMode
   *   (optional) The mix of case for the returned value. One of
   *   MB_CASE_UPPER, MB_CASE_LOWER, or MB_CASE_TITLE (default).
   *
   * @return string
   *   The user-visible term for the plural kind.
   */
  public static function mapKindToTerms(
    string $kind,
    int $caseMode = MB_CASE_TITLE) {

    $term = '';
    switch ($kind) {
      case FolderShare::FILE_KIND:
        $term = t('files');
        break;

      case FolderShare::IMAGE_KIND:
        $term = t('images');
        break;

      case FolderShare::MEDIA_KIND:
        $term = t('media');
        break;

      case FolderShare::FOLDER_KIND:
        $term = t('folders');
        break;

      case FolderShare::ROOT_FOLDER_KIND:
        $term = t('top-level folders');
        break;

      default:
        $term = t('items');
        break;
    }

    return mb_convert_case($term, $caseMode);
  }

  /**
   * Collects current terminology for use in form text.
   *
   * @return string[]
   *   Returns an associative array with keys for well-known terms,
   *   and values that contain those terms.
   */
  public static function getTerminology() {
    $terms = [];

    $mh = \Drupal::service('module_handler');

    $terms['module']      = Constants::MODULE;
    $terms['moduletitle'] = $mh->getName(Constants::MODULE);

    return $terms;
  }

  /**
   * Creates a page/command title based upon a format and the current selection.
   *
   * The $format argument is a command or page title that may include
   * an "@operand" marker that this function will replace with an indication
   * of the operand(s) for the title, such as a file, folder, or list of same.
   *
   * The $operands array is an associative array with keys named after
   * FolderShare entity kinds (e.g. "file", "folder", "rootfolder", etc.).
   * Each entry for a kind is an array of FolderShare entities of that kind.
   *
   * This function uses the operands array to decide on appropriate text to
   * insert into $format to replace "@operand". The completed title text
   * is returned.
   *
   * @param string $format
   *   A text string giving the title of an operation and containing an
   *   optional "@operand" placeholder into which text describing the
   *   operand(s) is added.
   * @param array $operands
   *   An associative array with keys that are Foldershare entity kinds,
   *   and values that are arrays of FolderShare entities.
   *
   * @return string
   *   Returns the $format string with "@operand" replaces with words
   *   describing the operands.
   */
  public static function createTitle(string $format, array $operands) {
    if (empty($format) === TRUE) {
      return '';
    }

    if (empty($operands) === TRUE) {
      return $format;
    }

    // Determine what kinds of operands we have.
    $nKinds = 0;
    $nItems = 0;
    $firstKind = '';
    $firstItem = NULL;
    foreach ($operands as $kind => $entities) {
      $nEntities = count($entities);
      if ($nEntities !== 0) {
        $nItems += $nEntities;
        $nKinds++;
        if ($firstKind === '') {
          $firstKind = $kind;
        }

        if ($firstItem === NULL) {
          $firstItem = $entities[0];
        }
      }
    }

    // Select the operand name.
    if ($nItems === 1) {
      // When there is only one operand, use the name of the operand
      // in the title.
      $operandName = '"' . $firstItem->getName() . '"';
    }
    elseif ($nKinds === 1) {
      // When there is only one kind for the operands, use the kind
      // in the title.
      $operandName = self::mapKindToTerms($firstKind);
    }
    else {
      // When there are multiple kinds for the operands, use a
      // generic term.
      $operandName = t('Items');
    }

    $title = t($format, ['@operand' => $operandName]);
    return $title;
  }

  /*--------------------------------------------------------------------
   *
   * Configuration.
   *
   * These functions assist in managing stored site configurations.
   *
   *-------------------------------------------------------------------*/

  /**
   * Loads a named configuration.
   *
   * The named configuration for the indicated entity type is found on
   * disk (in any module), loaded, and returned as a raw nested array
   * of values. Returning the array, rather than a created entity,
   * avoids unnecessarily creating new entities when the caller just
   * wants to see particular values within a configuration, or compare
   * it with another configuration.
   *
   * @param string $entityTypeId
   *   The ID (name) of the entity type.
   * @param string $configName
   *   The name of the desired configuration for the entity type.
   *
   * @return array
   *   Returns a nested associative array containing the keys and values
   *   of the configuration. The structure of the array varies with the
   *   type of configuration. A NULL is returned if the configuration
   *   could not be found. An empty array is returned if the configuration
   *   is found, but it is empty.
   */
  public static function loadConfiguration(
    string $entityTypeId,
    string $configName) {

    // Validate
    // --------
    // The entity type ID and configuration name both must be non-empty.
    if (empty($entityTypeId) === TRUE || empty($configName) === TRUE) {
      // Empty entity type ID or configuration name.
      return NULL;
    }

    // Setup
    // -----
    // Get some values we need.
    $entityManager = \Drupal::entityManager();
    try {
      $entityDefinition = $entityManager->getDefinition($entityTypeId);
    }
    catch (\Exception $e) {
      // Unrecognized entity type.
      return NULL;
    }

    // Create the fully-qualified configuration name formed by adding a
    // configuration prefix for the entity type.
    $fullName = $entityDefinition->getConfigPrefix() . '.' . $configName;

    // Load file
    // ---------
    // Read the configuration from a file. Start by checking the install
    // configuration. If not found there, check the optional configuration.
    $configStorage = \Drupal::service('config.storage');

    $installStorage = new ExtensionInstallStorage(
      $configStorage,
      InstallStorage::CONFIG_INSTALL_DIRECTORY);

    $config = $installStorage->read($fullName);
    if (empty($config) === TRUE) {
      $optionalStorage = new ExtensionInstallStorage(
        $configStorage,
        InstallStorage::CONFIG_OPTIONAL_DIRECTORY);

      $config = $optionalStorage->read($fullName);
      if (empty($config) === TRUE) {
        // Cannot find the configuration file.
        return NULL;
      }
    }

    return $config;
  }

  /**
   * Reverts an entity or core configuration by reloading it from a module.
   *
   * This method supports core and entity configurations. Core configurations
   * have a single site-wide instance, while entity configurations are
   * entity instances with an ID and values for entity fields. For instance,
   * core configurations are used to describe an entity type's forms and
   * and displays, while entity configurations are used to describe each
   * of the views configurations that might have been created by a site.
   *
   * This method looks at the $baseName. If it is 'core', the method loads
   * a core configuration. Otherwise it assumes the name is for an entity
   * type and the method loads a configuration and saves it as either a new
   * entity type instance, or to replace an existing instance.
   *
   * This method looks for either type of configuration in the 'install' or
   * 'optional' folders across all installed modules. If not found,
   * FALSE is returned. If found, the configuration is loaded as above.
   *
   * For entity configurations, this method looks for the entity type
   * definition. If not found, FALSE is returned.
   *
   * @param string $baseName
   *   The base name of the configuration. For entity type's, this is the
   *   entity type ID. For core configurations, this is 'core'.
   * @param string $configName
   *   The name of the desired configuration.
   *
   * @return bool
   *   Returns TRUE if the configuration was reverted. FALSE is returned
   *   if the configuration file could not be found. For entity configurations,
   *   FALSE is returned if the entity type could not be found.
   */
  public static function revertConfiguration(
    string $baseName,
    string $configName) {

    // The base name and configuration name both must be non-empty.
    if (empty($baseName) === TRUE || empty($configName) === TRUE) {
      return FALSE;
    }

    // Revert a core or entity configuration.
    if ($baseName === 'core') {
      // Core configurations SOMETIMES prefix with 'core'. Try that first.
      if (self::revertCoreConfiguration('core.' . $configName) === TRUE) {
        return TRUE;
      }

      // Other core configurations do not prefix with 'core'. Try that.
      return self::revertCoreConfiguration($configName);
    }

    return self::revertEntityConfiguration($baseName, $configName);
  }

  /**
   * Reverts a core configuration by reloading it from a module.
   *
   * Core configurations have a single site-wide instance. The form and
   * display configurations for an entity type's fields, for instance,
   * are core configurations.
   *
   * This method looks for a core configuration in the 'install' or
   * 'optional' folders across all installed modules. If not found,
   * FALSE is returned. If found, the configuration is loaded and used
   * to replace the current core configuration.
   *
   * @param string $configName
   *   The name of the desired core configuration.
   *
   * @return bool
   *   Returns TRUE if the configuration was reverted. FALSE is returned
   *   if the configuration file could not be found.
   */
  private static function revertCoreConfiguration(string $configName) {

    //
    // Load file
    // ---------
    // The configuration file exists in several locations:
    // - The current cached configuration used each time Drupal boots.
    // - The original configuration loaded when the module was installed.
    // - The original optional configuration that might load on module install.
    //
    // We specifically want to replace the current cached configuration,
    // so that isn't the one to load here.
    //
    // We'd prefer to get the installed configuration, but it will not exist
    // if the configuration we're asked to load is for an optional feature
    // that was only added if other non-required modules were installed.
    // In that case, we need to switch from the non-existant installed
    // configuration to the optional configuration and load that.
    //
    // Get the configuration storage service.
    $configStorage = \Drupal::service('config.storage');

    // Try to load the install configuration, if any.
    $installStorage = new ExtensionInstallStorage(
      $configStorage,
      InstallStorage::CONFIG_INSTALL_DIRECTORY);
    $config = $installStorage->read($configName);

    if (empty($config) === TRUE) {
      // The install configuration did not exist. Try to load the optional
      // configuration, if any.
      $optionalStorage = new ExtensionInstallStorage(
        $configStorage,
        InstallStorage::CONFIG_OPTIONAL_DIRECTORY);
      $config = $optionalStorage->read($configName);

      if (empty($config) === TRUE) {
        // Neither the install or optional configuration directories found
        // the configuration we're after. Nothing more we can do.
        return FALSE;
      }
    }

    //
    // Revert
    // ------
    // To revert to the newly loaded configuration, we need to get the
    // current cached ('sync') configuration and replace it.
    $configStorage->write($configName, $config);

    return TRUE;
  }

  /**
   * Reverts an entity configuration by reloading it from a module.
   *
   * Entity configurations are entity instances with an ID and values for
   * entity fields. For instance, since a "view" is an entity instance,
   * the configuration of a specific view is an entity configuration.
   * These are individually stored in cache and in a module's configuration
   * folder.
   *
   * This method looks for an entity configuration in the 'install' or
   * 'optional' folders across all installed modules. If not found,
   * FALSE is returned. If found, the configuration is loaded and used
   * to create a new entity or replace an existing entity with the same
   * ID as that found in the configuration file. If the entity type cannot
   * be found, FALSE is returned.
   *
   * @param string $entityTypeId
   *   The ID (name) of the entity type.
   * @param string $configName
   *   The name of the desired entity configuration.
   *
   * @return bool
   *   Returns TRUE if the configuration was reverted. FALSE is returned
   *   if the entity type cannot be found, or if the configuration file
   *   cannot be found.
   */
  private static function revertEntityConfiguration(
    string $entityTypeId,
    string $configName) {

    // Get entity info
    // ---------------
    // The incoming $entityTypeId names an entity type that has its
    // own storage manager and an entity type definition that we need
    // in order to build the full name of a configuration.
    try {
      // Get the entity's storage and definition.
      $entityManager    = \Drupal::entityManager();
      $entityStorage    = $entityManager->getStorage($entityTypeId);
      $entityDefinition = $entityManager->getDefinition($entityTypeId);
    }
    catch (\Exception $e) {
      // Unrecognized entity type!
      return FALSE;
    }

    //
    // Create name
    // -----------
    // The full name of the configuration uses the given $configName,
    // prefixed with an entity type-specific prefix obtained from
    // the entity type definition.
    $fullName = $entityDefinition->getConfigPrefix() . '.' . $configName;

    //
    // Load file
    // ---------
    // The configuration file exists in several locations:
    // - The current cached configuration used each time Drupal boots.
    // - The original configuration loaded when the module was installed.
    // - The original optional configuration that might load on module install.
    //
    // We specifically want to replace the current cached configuration,
    // so that isn't the one to load here.
    //
    // We'd prefer to get the installed configuration, but it will not exist
    // if the configuration we're asked to load is for an optional feature
    // that was only added if other non-required modules were installed.
    // In that case, we need to switch from the non-existant installed
    // configuration to the optional configuration and load that.
    //
    // Get the configuration storage service.
    $configStorage = \Drupal::service('config.storage');

    // Try to load the install configuration, if any.
    $installStorage = new ExtensionInstallStorage(
      $configStorage,
      InstallStorage::CONFIG_INSTALL_DIRECTORY);
    $config = $installStorage->read($fullName);

    if (empty($config) === TRUE) {
      // The install configuration did not exist. Try to load the optional
      // configuration, if any.
      $optionalStorage = new ExtensionInstallStorage(
        $configStorage,
        InstallStorage::CONFIG_OPTIONAL_DIRECTORY);
      $config = $optionalStorage->read($fullName);

      if (empty($config) === TRUE) {
        // Neither the install or optional configuration directories found
        // the configuration we're after. Nothing more we can do.
        return FALSE;
      }
    }

    //
    // Load entity
    // -----------
    // Entity configurations handled by this method are associated with
    // an entity, unsurprisingly. We need to load that entity as it is now
    // so that we can replace its fields with those loaded from the
    // configuration above.
    //
    // The entity might not exist if someone deleted it. This could happen
    // if a site administrator deleted a 'view' that this method is now
    // being called to restore.
    $idKey = $entityDefinition->getKey('id');
    $id = $config[$idKey];
    $currentEntity = $entityStorage->load($id);

    //
    // Revert
    // ------
    // If there is already an entity, update it with the loaded configuration.
    // And if there is not an entity, create a new one using the loaded
    // configuration.
    if ($currentEntity === NULL) {
      // Create a new entity.
      $newEntity = $entityStorage->createFromStorageRecord($config);
    }
    else {
      // Update the existing entity.
      $newEntity = $entityStorage->updateFromStorageRecord(
        $currentEntity,
        $config);
    }

    // Save the new or updated entity.
    $newEntity->trustData();
    $newEntity->save();

    return TRUE;
  }

  /*--------------------------------------------------------------------
   *
   * Search.
   *
   * These functions assist in managing search.
   *
   *-------------------------------------------------------------------*/

  /**
   * Request reindexing of core search.
   *
   * With no argument, this method sweeps through all available search
   * pages and marks each one for re-indexing. With an argument, the
   * indicated search page alone is marked for re-indexing.
   *
   * @param string $entityId
   *   (optional) The ID of a specific search page to reindex. If not
   *   given, or NULL, all search pages are reindexed.
   *
   * @return bool
   *   Returns TRUE if the reindexing request was success. FALSE is
   *   returned if the core Search module is not installed, or if the
   *   indicated entity ID is not found.
   */
  public static function searchReindex(string $entityId = NULL) {

    //
    // The core Search module does not have a direct API call to request
    // a reindex of the site. Such reindexing is needed after the search
    // configuration has changed, such as by reverting it to an original
    // configuration.
    //
    // This code roughly mimics code found in the Search module's
    // 'ReindexConfirm' form.
    //
    if (\Drupal::hasService('search.search_page_repository') === FALSE) {
      // Search page repository not found. Search module not installed?
      return FALSE;
    }

    $rep = \Drupal::service('search.search_page_repository');
    if (empty($entityId) === TRUE) {
      // Reindex all search pages.
      foreach ($rep->getIndexableSearchPages() as $entity) {
        $entity->getPlugin()->markForReindex();
      }

      return TRUE;
    }

    // Reindex a single entity.
    foreach ($rep->getIndexableSearchPages() as $entity) {
      if ($entity->id() === $entityId) {
        $entity->getPlugin()->markForReindex();
        return TRUE;
      }
    }

    return FALSE;
  }

}
