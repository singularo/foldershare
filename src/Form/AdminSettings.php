<?php

namespace Drupal\foldershare\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\views\Entity\View;
use Drupal\Component\Utility\Html;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Utilities;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Manages an administrator form to adjust the module's configuration.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * <b>Access control:</b>
 * The route to this form must be restricted to administrators.
 *
 * <b>Route parameters:</b>
 * The route to this form has no parameters.
 *
 * @ingroup foldershare
 */
class AdminSettings extends ConfigFormBase {

  /*---------------------------------------------------------------------
   *
   * Constants.
   *
   * These values define internal names for portions of the form that
   * need to be validated or used during form submission. The rest of
   * the forms are text or links to other pages and do not require
   * validation or submit processing.
   *
   *---------------------------------------------------------------------*/

  /**
   * The form input for the file scheme.
   */
  const FORM_FILES_SCHEME = 'foldershare_file_scheme';

  /**
   * The form input for the file directory.
   */
  const FORM_FILES_DIRECTORY = 'foldershare_file_directory';

  /**
   * The form input for the boolean on whether extensions should be checked.
   */
  const FORM_FILES_RESTRICT_EXTENSIONS = 'foldershare_file_restrict_extensions';

  /**
   * The form input for the list of allowed file extensions.
   */
  const FORM_FILES_ALLOWED_EXTENSIONS = 'foldershare_file_allowed_extensions';

  /**
   * The form input for the revert file extensions button.
   */
  const FORM_FILES_RESTORE_ALLOWED_EXTENSIONS = 'foldershare_file_restore_allowed_extensions';

  /**
   * The form input for the maximum uploaded file size.
   */
  const FORM_FILES_MAXIMUM_UPLOAD_SIZE = 'foldershare_file_maximum_upload_size';

  /**
   * The form input for the maximum number of files uploaded at once.
   */
  const FORM_FILES_MAXIMUM_UPLOAD_NUMBER = 'foldershare_file_maximum_upload_number';

  /**
   * The form button input to restore the original forms configuration.
   */
  const FORM_FIELDS_RESTORE_FORMS = 'foldershare_fields_restore_forms';

  /**
   * The form button input to restore the original displays configuration.
   */
  const FORM_FIELDS_RESTORE_DISPLAYS = 'foldershare_fields_restore_displays';

  /**
   * The form button input to restore the original search configuration.
   */
  const FORM_SEARCH_RESTORE_CORESEARCH = 'foldershare_search_restore_coresearch';

  /**
   * The form button input to restore the original REST configuration.
   */
  const FORM_REST_RESTORE_REST = 'foldershare_rest_restore_rest';

  /**
   * The form input for the checkbox to allow sharing.
   */
  const FORM_SECURITY_ALLOW_SHARING = 'foldershare_security_allow_sharing';

  /**
   * The form input for the checkbox to allow sharing with anonymous.
   */
  const FORM_SECURITY_ALLOW_SHARING_WITH_ANONYMOUS = 'foldershare_security_allow_sharing_with_anonymous';

  /**
   * The form input for the checkbox to allow command menu restrictions.
   */
  const FORM_COMMAND_MENU_ALLOW_RESTRICTIONS = 'foldershare_command_menu_allow_restrictions';

  /**
   * The form input for the checkboxes for allowed menu commands.
   */
  const FORM_COMMAND_MENU_ALLOWED = 'foldershare_command_menu_allowed';

  /*---------------------------------------------------------------------
   *
   * Form utilities.
   *
   * These utility functions get information or assist in building the
   * form.
   *
   *---------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    // Base the form ID on the namespace-qualified class name, which
    // already has the module name as a prefix.  PHP's get_class()
    // returns a string with "\" separators. Replace them with underbars.
    return str_replace('\\', '_', get_class($this));
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [Constants::SETTINGS];
  }

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
          return (((int) $matches[1]) * (1024));

        case 'm':
          return (((int) $matches[1]) * (1024 * 1024));

        case 'g':
          return (((int) $matches[1]) * (1024 * 1024 * 1024));
      }
    }

    return (int) $value;
  }

  /**
   * Returns markup for an entry in a related settings section.
   *
   * Arguments to the method indicate the machine name of a related
   * module (if any) and the module's title or settings section title.
   * Additional arguments provide the route and arguments to settings
   * (if any), a description, a flag indicating if the module or settings
   * are required, and an optional array of Drupal.org documentation
   * sections (primarily for core modules).
   *
   * @param string $module
   *   (optional) The machine name of a module.
   * @param string $moduleTitle
   *   The title of a module or settings section.
   * @param string $settingsRoute
   *   (optional) The route to a settings page.
   * @param array $settingsArguments
   *   (optional) An array of arguments for the settings page route.
   * @param string $description
   *   The description of the module and/or settings.
   * @param bool $isCore
   *   TRUE if the module/settings are part of Drupal core.
   * @param array $doc
   *   (optional) An associative array of Drupal.org documentation sections.
   *
   * @return string
   *   Returns HTML markup for the entry.
   */
  private function buildRelatedSettings(
    string $module,
    string $moduleTitle,
    string $settingsRoute,
    array $settingsArguments,
    string $description,
    bool $isCore,
    array $doc) {

    // Create an entry in a list of entries underneath a section title.
    // Each entry has a module/settings page name that, ideally, links
    // to the module/settings page. If the referenced module is not
    // currently enabled at the site, there is no link and the module
    // name is shown alone.
    //
    // Following the module name is a provided description of the module
    // or settings page and why it is relevant here. If provided,
    // links to Drupal.org documentation is added after the description.
    //
    // Module name
    // -----------
    // Get the module name and a possible link to the module or settings page.
    $mh = \Drupal::service('module_handler');
    $addNotEnabled = FALSE;
    if (empty($module) === FALSE && $mh->moduleExists($module) === FALSE) {
      // A module/settings machine name was provided, but that
      // module is not enabled at the site. Show the module title.
      $addNotEnabled = TRUE;
      $moduleLink = $moduleTitle;
    }
    elseif (empty($settingsRoute) === TRUE) {
      // The relevant module is enabled, but there is no settings page for it.
      // Show the module title.
      $moduleLink = $moduleTitle;
    }
    else {
      // The relevant module is enabled and it has a settings page. Link
      // to the page, adding in arguments if provided.
      if (empty($settingsArguments) === TRUE) {
        // The settings page requires no route arguments.
        $moduleLink = Utilities::createRouteLink(
          $settingsRoute,
          '',
          $moduleTitle);
      }
      elseif (isset($settingsArguments[0]) === TRUE) {
        // The settings page requires a simple fragment argument.
        $moduleLink = Utilities::createRouteLink(
          $settingsRoute,
          $settingsArguments[0],
          $moduleTitle);
      }
      else {
        // The settings page requires a named argument.
        $moduleLink = Link::createFromRoute(
          $moduleTitle,
          $settingsRoute,
          $settingsArguments)->toString();
      }
    }

    //
    // Core note
    // ---------
    // Create a note indicating if the module is core or contributed.
    if ($isCore === TRUE) {
      $coreNote = '<em>' . $this->t('(Core module)') . '</em>';
    }
    else {
      $coreNote = '<em>' . $this->t('(Third-party module)') . '</em>';
    }

    //
    // Enabled note
    // ------------
    // Create a note indicating if the module is enabled.
    if ($addNotEnabled === TRUE) {
      $enabledNote = '<em>' . $this->t('(Not enabled)') . '</em>';
    }
    else {
      $enabledNote = '';
    }

    //
    // Documentation links
    // -------------------
    // Get the doc links, if any.
    $docLinks = '';
    if (empty($doc) === FALSE) {
      // Run through the given list of Drupal.org documentation pages.
      // Each one has a page name and a page title.
      $links = '';
      $n = count($doc);
      $i = 0;
      foreach ($doc as $pageName => $pageTitle) {
        ++$i;
        $link = Utilities::createDocLink($pageName, $pageTitle);

        if ($i === $n) {
          if ($n === 2) {
            $links .= ' ' . $this->t('and') . ' ';
          }
          elseif ($n > 2) {
            $links .= ', ' . $this->t('and') . ' ';
          }
        }
        elseif ($i !== 1) {
          $links .= ', ';
        }

        $links .= $link;
      }

      // Assemble this into "(See this, that, and whatever documentation)".
      // We can't call t() with an argument containing the links because
      // t() "sanitizes" those links into text, rather than leaving them
      // as HTML.
      $seeText = $this->t('See the');
      $docText = $this->t('documentation');
      $docLinks = $seeText . ' ' . $links . ' ' . $docText . '.';
    }

    //
    // Assemble
    // --------
    // Put it all together.
    return '<dt>' . $moduleLink . ' ' . $enabledNote . '</dt><dd>' .
      $description . ' ' . $docLinks . ' ' . $coreNote . '</dd>';
  }

  /*---------------------------------------------------------------------
   *
   * Build form.
   *
   * These functions help build the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds a form to adjust the module settings.
   *
   * The form has multiple vertical tabs, each built by a separate function.
   *
   * @param array $form
   *   An associative array containing the renderable structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   (optional) The current state of the form.
   *
   * @return array
   *   The form renderable array.
   */
  public function buildForm(
    array $form,
    FormStateInterface $formState = NULL) {

    //
    // Setup
    // -----
    // Determine if there are any files already.
    $mh = \Drupal::service('module_handler');
    $terms = Utilities::getTerminology();

    //
    // Introduction
    // ------------
    // Briefly introduce the module and link to further help.
    //
    // Get a link to the module's help, if the help module is installed.
    $helpLink = '';
    if ($mh->moduleExists('help') === TRUE) {
      // The help module exists. Create a link to this module's help.
      $helpLink = Utilities::createHelpLink($terms['module'], 'Module help');
    }

    // Get a link to the usage report.
    $usageLink = Utilities::createRouteLink(
      Constants::ROUTE_USAGE,
      '',
      $this->t('Usage report'));

    // Create a "See also" message with links. Include the help link only
    // if the help module is installed.
    $seeAlso = '';
    if (empty($helpLink) === TRUE) {
      $seeAlso = $this->t("See also the module's") . ' ' . $usageLink . '.';
    }
    else {
      $seeAlso = $this->t('See also') . ' ' . $helpLink . ' ' .
        $this->t("and the module's") . ' ' . $usageLink . '.';
    }

    // Create the introduction.
    $form['introduction'] = [
      '#type'  => 'html_tag',
      '#tag'   => 'p',
      '#value' => $this->t(
        '<strong>@moduletitle</strong> manages a virtual file system with files, folders, and subfolders. Module settings control how content is stored, presented, searched, and secured.',
        [
          '@moduletitle' => $terms['moduletitle'],
        ]) . ' ' . $seeAlso,
      '#attributes' => [
        'class'     => [$terms['module'] . '-settings-help'],
      ],
    ];

    //
    // Vertical tabs
    // -------------
    // Setup vertical tabs. For these to work, all of the children
    // must be of type 'details' and refer to the 'tabs' group.
    $form['tabs'] = [
      '#type'     => 'vertical_tabs',
      '#attached' => [
        'library' => [
          Constants::LIBRARY_MODULE,
          Constants::LIBRARY_ADMIN,
        ],
      ],
    ];

    // Create each of the tabs.
    $this->buildFieldsTab($form, $formState, 'tabs', $terms);
    $this->buildFilesTab($form, $formState, 'tabs', $terms);
    $this->buildInterfaceTab($form, $formState, 'tabs', $terms);
    $this->buildViewsTab($form, $formState, 'tabs', $terms);
    $this->buildSearchTab($form, $formState, 'tabs', $terms);
    $this->buildSecurityTab($form, $formState, 'tabs', $terms);
    $this->buildServicesTab($form, $formState, 'tabs', $terms);

    //
    // Logo footer
    // -----------
    // Add standard logos and text.
    $form['footer'] = Utilities::createModuleFooter();

    // Build and return the form.
    return parent::buildForm($form, $formState);
  }

  /*---------------------------------------------------------------------
   *
   * Validate.
   *
   * These functions validate the form. For reset buttons, validation
   * also executes the reset, then returns the user to the form to
   * continue entering values.
   *
   *---------------------------------------------------------------------*/

  /**
   * Validates the form values.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  public function validateForm(array &$form, FormStateInterface $formState) {

    $this->validateFieldsTab($form, $formState);
    $this->validateFilesTab($form, $formState);
    $this->validateInterfaceTab($form, $formState);
    $this->validateViewsTab($form, $formState);
    $this->validateSearchTab($form, $formState);
    $this->validateSecurityTab($form, $formState);
    $this->validateServicesTab($form, $formState);
  }

  /*---------------------------------------------------------------------
   *
   * Submit.
   *
   * These functions submit the form by updating module settings based
   * upon new values in the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Stores the submitted form values.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  public function submitForm(array &$form, FormStateInterface $formState) {

    parent::submitForm($form, $formState);

    $this->submitFieldsTab($form, $formState);
    $this->submitFilesTab($form, $formState);
    $this->submitInterfaceTab($form, $formState);
    $this->submitViewsTab($form, $formState);
    $this->submitSearchTab($form, $formState);
    $this->submitSecurityTab($form, $formState);
    $this->submitServicesTab($form, $formState);
  }

  /*---------------------------------------------------------------------
   *
   * Files tab.
   *
   * These functions build the Files tab on the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds the files tab for the form.
   *
   * Settings:
   * - File system (public or private).
   * - Storage location.
   * - Enable/disable file extension restrictions.
   * - Set a list of allowed file extensions.
   * - Reset the allowed file extensions list to the default.
   * - Set maximum upload file size.
   * - Set maxumum number of files per upload.
   * - Links to related module settings.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   * @param string $tabGroup
   *   The name of the tab group.
   * @param string[] $terms
   *   Current terminology.
   */
  private function buildFilesTab(
    array &$form,
    FormStateInterface $formState,
    string $tabGroup,
    array $terms) {

    //
    // Setup
    // -----
    // Set up some variables.
    $tabName                 = $terms['module'] . '_files_tab';
    $tabSubtitleClass        = $terms['module'] . '-settings-subtitle';
    $tabDescriptionClass     = $terms['module'] . '-settings-description';
    $sectionTitleClass       = $terms['module'] . '-settings-section-title';
    $sectionClass            = $terms['module'] . '-settings-section';
    $sectionDescriptionClass = $terms['module'] . '-settings-section-description';
    $sectionDefaultClass     = $terms['module'] . '-settings-section-default';
    $warningClass            = $terms['module'] . '-warning';
    $relatedClass            = $terms['module'] . '-related-links';

    //
    // Create the tab
    // --------------
    // Start the tab with a title, subtitle, and description.
    $form[$tabName] = [
      '#type'           => 'details',
      '#open'           => FALSE,
      '#group'          => $tabGroup,
      '#title'          => $this->t('Files'),
      '#description'    => [
        'subtitle'      => [
          '#type'       => 'html_tag',
          '#tag'        => 'h2',
          '#value'      => $this->t(
            'Select how files are uploaded and stored.'),
          '#attributes' => [
            'class'     => [$tabSubtitleClass],
          ],
        ],
        'description'   => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            "Files are stored on the site's server and tracked in the site\'s database. Administrators may restrict uploaded files based upon their size and name extension, and limit the number of files uploaded at once."),
          '#attributes' => [
            'class'     => [$tabDescriptionClass],
          ],
        ],
      ],
      '#attributes'     => [
        'class'         => [
          $terms['module'] . '-settings-tab ',
          $terms['module'] . '-files-tab',
        ],
      ],
    ];

    //
    // Create warnings
    // ---------------
    // If the site currently has stored files, then the storage scheme
    // and subdirectory choice cannot be changed.
    //
    // If the site doesn't have stored files, but the private file system
    // has not been configured, then the storage scheme cannot be changed.
    $inUseWarning          = '';
    $noPrivateWarning      = '';
    $storageSchemeDisabled = FALSE;
    $subdirectoryDisabled  = FALSE;

    if (FolderShare::hasFiles() === TRUE) {
      // Create a link to the Drupal core system page to delete all content
      // for the entity type.
      $lnk = Link::createFromRoute(
        'Delete all',
        Constants::ROUTE_DELETEALL,
        [
          'entity_type_id' => FolderShare::ENTITY_TYPE_ID,
        ],
        []);

      $inUseWarning = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t(
          'This setting is disabled because there are already files under management by this module. To change this setting, you must @deleteall files first.',
          [
            '@deleteall' => $lnk->toString(),
          ]),
        '#attributes' => [
          'class'     => [$warningClass],
        ],
      ];
      $storageSchemeDisabled = TRUE;
      $subdirectoryDisabled = TRUE;
    }

    if (empty(PrivateStream::basePath()) === TRUE) {
      $noPrivateWarning = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t(
          'This setting is disabled and restricted to the <em>Public</em> file system. To change this setting, you must configure the site to support a <em>Private</em> file system. See the @filedoc module\'s documentation and the "file_private_path" setting in the site\'s "settings.php" file.',
          [
            '@filedoc' => Utilities::createDocLink('file', 'File'),
          ]),
        '#attributes' => [
          'class'     => [$warningClass],
        ],
      ];
      $subdirectoryDisabled = TRUE;
    }

    //
    // Storage scheme
    // --------------
    // Select whether files are stored in the public or private file system.
    $options = [
      'public'  => $this->t('Public'),
      'private' => $this->t('Private'),
    ];

    // Add section title and description.
    $form[$tabName]['file-system'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('File system') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'    => [
          '#type'        => 'html_tag',
          '#tag'         => 'p',
          '#value'       => $this->t(
            "Select whether the module should use the site's <em>Public</em> or <em>Private</em> file system to store files. A <em>Private</em> file system provides better security."),
          '#attributes'  => [
            'class'      => [$sectionDescriptionClass],
          ],
        ],
      ],
    ];

    // Add warning if there are things disabled.
    if (empty($noPrivateWarning) === FALSE) {
      $form[$tabName]['file-system']['section']['warning'] = $noPrivateWarning;
    }
    elseif (empty($inUseWarning) === FALSE) {
      $form[$tabName]['file-system']['section']['warning'] = $inUseWarning;
    }

    // Add label and widget.
    $form[$tabName]['file-system']['section'][self::FORM_FILES_SCHEME] = [
      '#type'          => 'select',
      '#options'       => $options,
      '#default_value' => Settings::getFileScheme(),
      '#required'      => TRUE,
      '#disabled'      => $storageSchemeDisabled,
      '#title'         => $this->t('File system:'),
      '#description'   => [
        '#type'        => 'html_tag',
        '#tag'         => 'p',
        '#value'       => $this->t(
          'Default: @default',
          [
            '@default'   => $options[Settings::getFileSchemeDefault()],
          ]),
        '#attributes'    => [
          'class'        => [$sectionDefaultClass],
        ],
      ],
    ];

    //
    // File directory
    // --------------
    // The path to a local directory in which to store files.
    //
    // Add section title and description.
    $form[$tabName]['site-directory'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('Storage location') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'Select the server subfolder in the <em>Public</em> or <em>Private</em> file system into which the module will save files. This is typically named after the module. The subfolder will be created automatically if necessary.'),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
      ],
    ];

    // Add warning if there are things disabled.
    if (empty($inUseWarning) === FALSE) {
      $form[$tabName]['site-directory']['section']['warning'] = $inUseWarning;
    }

    // Add label and widget.
    $form[$tabName]['site-directory']['section'][self::FORM_FILES_DIRECTORY] = [
      '#type'          => 'textfield',
      '#default_value' => Html::escape(Settings::getFileDirectory()),
      '#size'          => 40,
      '#maxlength'     => 40,
      '#required'      => TRUE,
      '#disabled'      => $subdirectoryDisabled,
      '#title'         => $this->t('Location:'),
      '#description'   => [
        '#type'        => 'html_tag',
        '#tag'         => 'p',
        '#value'       => $this->t(
          'Default: @default',
          [
            '@default'   => Html::escape(Settings::getFileDirectoryDefault()),
          ]),
        '#attributes'    => [
          'class'        => [$sectionDefaultClass],
        ],
      ],
    ];

    //
    // File name extensions
    // --------------------
    // Select whether file uploads should be restricted based on their
    // file name extensions, and what extensions are allowed.
    $form[$tabName]['fileextensions'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('File name extensions') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'Select whether uploaded files should be restricted to a specific set of allowed file name extensions.'),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
        self::FORM_FILES_RESTRICT_EXTENSIONS => [
          '#type'          => 'checkbox',
          '#title'         => $this->t('Enable restrictions'),
          '#default_value' => Settings::getFileRestrictExtensions(),
          '#return_value'  => 'enabled',
          '#required'      => FALSE,
          '#name'          => self::FORM_FILES_RESTRICT_EXTENSIONS,
        ],
        self::FORM_FILES_ALLOWED_EXTENSIONS => [
          '#type'          => 'textarea',
          '#title'         => $this->t('Allowed file name extensions:'),
          '#default_value' => Settings::getFileAllowedExtensions(),
          '#required'      => FALSE,
          '#rows'          => 7,
          '#name'          => self::FORM_FILES_ALLOWED_EXTENSIONS,
          '#description'   => $this->t(
            'Separate extensions with spaces and do not include dots. Changing this list does not affect files that are already on the site.'),
          '#states'        => [
            'invisible'    => [
              'input[name="' . self::FORM_FILES_RESTRICT_EXTENSIONS . '"]' => [
                'checked' => FALSE,
              ],
            ],
          ],
        ],
        self::FORM_FILES_RESTORE_ALLOWED_EXTENSIONS => [
          '#type'  => 'button',
          '#value' => $this->t('Restore original settings'),
          '#name'  => self::FORM_FILES_RESTORE_ALLOWED_EXTENSIONS,
          '#states'        => [
            'invisible'    => [
              'input[name="' . self::FORM_FILES_RESTRICT_EXTENSIONS . '"]' => [
                'checked' => FALSE,
              ],
            ],
          ],
        ],
      ],
    ];

    // Get the current extensions, if any, on the form.
    $value = $formState->getValue(self::FORM_FILES_ALLOWED_EXTENSIONS);
    if ($value !== NULL) {
      $form[$tabName]['fileextensions']['section'][self::FORM_FILES_ALLOWED_EXTENSIONS]['#value'] = $value;
    }

    //
    // Maximum file size
    // -----------------
    // Select the maximum size for an individual uploaded file.
    // This is limited by the smaller of two PHP settings.
    $form[$tabName]['filemaximumuploadsize'] = [
      '#type'              => 'item',
      '#markup'            => '<h3>' . $this->t('Maximum file upload size') . '</h3>',
      '#attributes'        => [
        'class'            => [$sectionTitleClass],
      ],
      'section'            => [
        '#type'            => 'container',
        '#attributes'      => [
          'class'          => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'Set the maximum size allowed for each uploaded file. This size is also limited by PHP settings in your server\'s "php.ini" file and your site\'s top-level ".htaccess" file. These are currently limiting file sizes to @bytes bytes.',
            [
              '@bytes'     => Settings::getPHPUploadMaximumFileSize(),
            ]),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
        self::FORM_FILES_MAXIMUM_UPLOAD_SIZE => [
          '#type'          => 'textfield',
          '#title'         => $this->t('Size:'),
          '#default_value' => Settings::getUploadMaximumFileSize(),
          '#required'      => TRUE,
          '#size'          => 10,
          '#maxlength'     => 10,
          '#description'   => [
            '#type'        => 'html_tag',
            '#tag'         => 'p',
            '#value'       => $this->t(
              'Default: @default',
              [
                '@default' => Settings::getUploadMaximumFileSizeDefault() .
                  ' ' . $this->t('bytes'),
              ]),
            '#attributes'  => [
              'class'      => [$sectionDefaultClass],
            ],
          ],
        ],
      ],
    ];

    //
    // Maximum file number
    // -------------------
    // Select the maximum number of files uploaded in one post.
    // This is limited by PHP settings.
    $form[$tabName]['filemaximumuploadnumber'] = [
      '#type'              => 'item',
      '#markup'            => '<h3>' . $this->t('Maximum number of files per upload') . '</h3>',
      '#attributes'        => [
        'class'            => [$sectionTitleClass],
      ],
      'section'            => [
        '#type'            => 'container',
        '#attributes'      => [
          'class'          => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'Set the maximum number of files that may be uploaded in a single pload. This number is also limited by PHP settings in your server\'s "php.ini" file and your site\'s top-level ".htaccess" file. These are currently limiting file uploads to @number files.',
            [
              '@number'    => Settings::getPHPUploadMaximumFileNumber(),
            ]),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
        self::FORM_FILES_MAXIMUM_UPLOAD_NUMBER => [
          '#type'          => 'textfield',
          '#title'         => $this->t('Number:'),
          '#default_value' => Settings::getUploadMaximumFileNumber(),
          '#required'      => TRUE,
          '#size'          => 10,
          '#maxlength'     => 10,
          '#description'   => [
            '#type'        => 'html_tag',
            '#tag'         => 'p',
            '#value'       => $this->t(
              'Default: @default',
              [
                '@default' => Settings::getUploadMaximumFileNumberDefault() .
                  ' ' . $this->t('files'),
              ]),
            '#attributes'  => [
              'class'      => [$sectionDefaultClass],
            ],
          ],
        ],
      ],
    ];

    //
    // Related settings
    // ----------------
    // Add links for related settings.
    $markup = '';

    // Cron.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Cron',
      'system.cron_settings',
      [],
      'Configure and run automatic administrative tasks, such as those used by this module to maintain the virtual file system.',
      TRUE,
      [
        'system'                => 'System',
        'automated-cron-module' => 'Automated Cron',
      ]);

    // File.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'File',
      'system.file_system_settings',
      [],
      'Configure the server\'s <em>Public</em> and <em>Private</em> file systems.',
      TRUE,
      ['file' => 'File']);

    $form[$tabName]['related-settings'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('See also') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'modules'        => [
          '#type'        => 'item',
          '#markup'      => '<dl>' . $markup . '</dl>',
          '#attributes'  => [
            'class'      => [$relatedClass],
          ],
        ],
      ],
    ];
  }

  /**
   * Validates form items from the files tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function validateFilesTab(
    array &$form,
    FormStateInterface $formState) {

    // Setup
    // -----
    // Get user input.
    $userInput = $formState->getUserInput();

    // Determine if the private file system is available.
    $hasPrivate = empty(PrivateStream::basePath()) === FALSE;

    //
    // File scheme
    // -----------
    // It must be "public" or "private". If "private", the site's private
    // file system must have been configured.
    $scheme = $formState->getValue(self::FORM_FILES_SCHEME);
    if ($scheme !== 'public' && $scheme !== 'private') {
      $formState->setErrorByName(
        self::FORM_FILES_SCHEME,
        $this->t('The file storage scheme must be either "Public" or "Private".'));
    }

    if ($scheme === 'private' && $hasPrivate === FALSE) {
      $formState->setErrorByName(
        self::FORM_FILES_SCHEME,
        $this->t('The "Private" file storage scheme is not available at this time because the web site has not been configured to support it.'));
    }

    //
    // File directory
    // --------------
    // The name cannot be empty. The directory need not exist
    // (yet), but it must be possible to create it if needed.
    $dir = $formState->getValue(self::FORM_FILES_DIRECTORY);
    if (empty($dir) === TRUE) {
      $formState->setErrorByName(
        self::FORM_FILES_DIRECTORY,
        $this->t('The name of the file storage directory cannot be empty.'));
    }

    // Build the full path and see if the directory is usable.
    $uriPath  = $scheme . '://' . $dir;
    $realPath = \Drupal::service('file_system')->realpath($uriPath);
    $error    = Settings::validateFileDirectory($realPath);
    if (empty($error) === FALSE) {
      $formState->setErrorByName(self::FORM_FILES_DIRECTORY, $error);
    }

    //
    // File extension restore
    // ----------------------
    // Check if the reset button was pressed.
    $value = $formState->getValue(self::FORM_FILES_RESTORE_ALLOWED_EXTENSIONS);
    if (isset($userInput[self::FORM_FILES_RESTORE_ALLOWED_EXTENSIONS]) === TRUE &&
        $userInput[self::FORM_FILES_RESTORE_ALLOWED_EXTENSIONS] === (string) $value) {
      // Reset button pressed.
      //
      // Get the default extensions.
      $value = Settings::getFileAllowedExtensionsDefault();

      // Immediately change the configuration and file field.
      Settings::setFileAllowedExtensions($value);
      FolderShare::setFileAllowedExtensions($value);

      // Reset the form's extensions list.
      $formState->setValue(self::FORM_FILES_ALLOWED_EXTENSIONS, $value);

      // Unset the button in form state since it has already been handled.
      $formState->setValue(self::FORM_FILES_RESTORE_ALLOWED_EXTENSIONS, NULL);

      // Rebuild the page.
      $formState->setRebuild(TRUE);
      drupal_set_message(
        t('The file extensions list has been restored to its original values.'),
        'status');
    }

    //
    // Maximum upload size and number
    // ------------------------------
    // The values cannot be empty, zero, or negative.
    $value = $formState->getValue(self::FORM_FILES_MAXIMUM_UPLOAD_SIZE);
    if (empty($value) === TRUE || ((int) $value) <= 0) {
      $formState->setErrorByName(
        self::FORM_FILES_MAXIMUM_UPLOAD_SIZE,
        $this->t('The maximum file upload size must be a positive integer.'));
    }

    $value = $formState->getValue(self::FORM_FILES_MAXIMUM_UPLOAD_NUMBER);
    if (empty($value) === TRUE || ((int) $value) <= 0) {
      $formState->setErrorByName(
        self::FORM_FILES_MAXIMUM_UPLOAD_NUMBER,
        $this->t('The maximum file upload number must be a positive integer.'));
    }
  }

  /**
   * Stores the submitted form values for the files tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function submitFilesTab(
    array &$form,
    FormStateInterface $formState) {

    //
    // File scheme and directory
    // -------------------------
    // Update file storage scheme and directory if there are no
    // files under management.
    if (FolderShare::hasFiles() === FALSE) {
      Settings::setFileScheme(
        $formState->getValue(self::FORM_FILES_SCHEME));

      Settings::setFileDirectory(
        $formState->getValue(self::FORM_FILES_DIRECTORY));
    }

    //
    // File extensions
    // ---------------
    // Updated whether extensions are checked, and which ones
    // are allowed.
    $value      = $formState->getValue(self::FORM_FILES_RESTRICT_EXTENSIONS);
    $enabled    = ($value === 'enabled') ? TRUE : FALSE;
    $extensions = $formState->getValue(self::FORM_FILES_ALLOWED_EXTENSIONS);
    Settings::setFileRestrictExtensions($enabled);
    Settings::setFileAllowedExtensions($extensions);

    // If extensions are enabled, forward them to the file field.
    // Otherwise, clear the extension list on the file field.
    if ($enabled === TRUE) {
      FolderShare::setFileAllowedExtensions($extensions);
    }
    else {
      FolderShare::setFileAllowedExtensions('');
    }

    //
    // Maximum upload size and number
    // ------------------------------
    // Update the limits.
    Settings::setUploadMaximumFileSize(
      $formState->getValue(self::FORM_FILES_MAXIMUM_UPLOAD_SIZE));

    Settings::setUploadMaximumFileNumber(
      $formState->getValue(self::FORM_FILES_MAXIMUM_UPLOAD_NUMBER));
  }

  /*---------------------------------------------------------------------
   *
   * Fields tab.
   *
   * These functions build the Fields settings tab on the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds the fields tab for the form.
   *
   * Settings:
   * - Link to manage fields tab.
   * - Link to manage forms tab.
   * - Link to manage displays tab.
   * - Buttons to reset forms and displays.
   * - Links to related module settings.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   * @param string $tabGroup
   *   The name of the tab group.
   * @param string[] $terms
   *   Current terminology.
   */
  private function buildFieldsTab(
    array &$form,
    FormStateInterface $formState,
    string $tabGroup,
    array $terms) {

    //
    // Setup
    // -----
    // Set up some variables.
    $mh = \Drupal::service('module_handler');

    $tabName                 = $terms['module'] . '_fields_tab';
    $tabSubtitleClass        = $terms['module'] . '-settings-subtitle';
    $tabDescriptionClass     = $terms['module'] . '-settings-description';
    $sectionTitleClass       = $terms['module'] . '-settings-section-title';
    $sectionClass            = $terms['module'] . '-settings-section';
    $sectionDescriptionClass = $terms['module'] . '-settings-section-description';
    $warningClass            = $terms['module'] . '-warning';
    $relatedClass            = $terms['module'] . '-related-links';

    $entityTypeId = FolderShare::ENTITY_TYPE_ID;

    //
    // Create the tab
    // --------------
    // Start the tab with a title, subtitle, and description.
    $form[$tabName] = [
      '#type'           => 'details',
      '#open'           => FALSE,
      '#group'          => $tabGroup,
      '#title'          => $this->t('Fields'),
      '#description'    => [
        'subtitle'      => [
          '#type'       => 'html_tag',
          '#tag'        => 'h2',
          '#value'      => $this->t(
            'Manage fields for files and folders.'),
          '#attributes' => [
            'class'     => [$tabSubtitleClass],
          ],
        ],
        'description'   => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'Files and folders contain fields for names, dates, sizes, descriptions, and more. You may add your own fields and adjust how fields are displayed when viewed and edited.'),
          '#attributes' => [
            'class'     => [$tabDescriptionClass],
          ],
        ],
      ],
      '#attributes'     => [
        'class'         => [
          $terms['module'] . '-settings-tab ',
          $terms['module'] . '-fields-tab',
        ],
      ],
    ];

    //
    // Manage fields, forms, and displays
    // ----------------------------------
    // By default, the Field UI module's forms for managing fields,
    // forms, and displays are in tabs at the top of the page. Include
    // links and comments here too.
    $fieldUiInstalled = ($mh->moduleExists('field_ui') === TRUE);
    if ($fieldUiInstalled === TRUE) {
      $fieldUiLink = Utilities::createHelpLink('field_ui', 'Field UI module');
    }
    else {
      $fieldUiLink = $this->t('Field UI module');
    }

    $form[$tabName]['manage-field-ui'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('Fields, forms, and displays') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'Built-in fields for files and folders include a name, description, size, and creation and modification dates. Fields may be adjusted with the optional @fieldui to add new fields and manage how fields are viewed and edited.',
            [
              '@moduletitle' => $terms['moduletitle'],
              '@fieldui'   => $fieldUiLink,
            ]),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
      ],
    ];

    //
    // Create warning
    // --------------
    // If the field_ui module is not installed, links to "Manage *" will
    // not work. Create a warning.
    if ($fieldUiInstalled === FALSE) {
      $disabledWarning = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t(
          'These items are disabled because they require the Field UI module. To use these settings, please enable the @fieldui.',
          [
            '@fieldui' => Utilities::createRouteLink(
              'system.modules_list',
              'module-field-ui',
              'Field UI module'),
          ]),
        '#attributes' => [
          'class'     => [$warningClass],
        ],
      ];

      $form[$tabName]['manage-field-ui']['section']['warning'] = $disabledWarning;
      $administerFields = FALSE;
      $administerForms = FALSE;
      $administerDisplays = FALSE;
    }
    else {
      // If the user does not have field, form, or display administation
      // permissions, links to "Manage *" will not work. Create a warning.
      $account = \Drupal::currentUser();

      $administerFields = $account->hasPermission(
        'administer ' . Constants::MODULE . ' fields');
      $administerForms = $account->hasPermission(
        'administer ' . Constants::MODULE . ' form display');
      $administerDisplays = $account->hasPermission(
        'administer ' . Constants::MODULE . ' display');

      if ($administerFields === FALSE || $administerForms === FALSE ||
          $administerDisplays === FALSE) {
        $disabledWarning = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'Some of these items are disabled because they require that you have additional permissions. To use these settings, you need @fieldui permissions to administer fields, forms, and displays for @moduletitle.',
            [
              '@fieldui' => Utilities::createRouteLink(
                'user.admin_permissions',
                'module-field-ui',
                'Field UI module'),
              '@moduletitle' => $terms['moduletitle'],
            ]),
          '#attributes' => [
            'class'     => [$warningClass],
          ],
        ];

        $form[$tabName]['manage-field-ui']['section']['warning'] = $disabledWarning;
      }
    }

    //
    // Create links
    // ------------
    // Create links to the field_ui tabs, if possible.
    if ($fieldUiInstalled === TRUE) {
      // The module is installed. Use its routes.
      if ($administerFields === TRUE) {
        $manageFieldsLink = Utilities::createRouteLink(
            'entity.' . $entityTypeId . '.field_ui_fields');
      }
      else {
        $manageFieldsLink = $this->t('Manage fields');
      }

      if ($administerForms === TRUE) {
        $manageFormsLink = Utilities::createRouteLink(
            'entity.entity_form_display.' . $entityTypeId . '.default');
        $formResetDisabled = FALSE;
      }
      else {
        $manageFormsLink = $this->t('Manage form display');
        $formResetDisabled = TRUE;
      }

      if ($administerDisplays === TRUE) {
        $manageDisplayLink = Utilities::createRouteLink(
            'entity.entity_view_display.' . $entityTypeId . '.default');
        $displayResetDisabled = FALSE;
      }
      else {
        $manageDisplayLink = $this->t('Manage display');
        $displayResetDisabled = TRUE;
      }
    }
    else {
      // The Field UI module is NOT installed. Just use text.
      $manageFieldsLink  = $this->t('Manage fields');
      $manageFormsLink   = $this->t('Manage form display');
      $manageDisplayLink = $this->t('Manage display');

      $formResetDisabled    = TRUE;
      $displayResetDisabled = TRUE;
    }

    $form[$tabName]['manage-field-ui']['section']['manage-fields'] = [
      '#type'    => 'container',
      '#prefix'  => '<dl>',
      '#suffix'  => '</dl>',
      'title'    => [
        '#type'  => 'html_tag',
        '#tag'   => 'dt',
        '#value' => $manageFieldsLink,
      ],
      'data'     => [
        '#type'  => 'html_tag',
        '#tag'   => 'dd',
        '#value' => $this->t('Create, delete, and modify additional fields for files and folders.'),
      ],
    ];

    $form[$tabName]['manage-field-ui']['section']['manage-form'] = [
      '#type'      => 'container',
      '#prefix'    => '<dl>',
      '#suffix'    => '</dl>',
      'title'      => [
        '#type'    => 'html_tag',
        '#tag'     => 'dt',
        '#value'   => $manageFormsLink,
      ],
      'data'       => [
        '#type'    => 'html_tag',
        '#tag'     => 'dd',
        '#value'   => $this->t('Manage forms to edit fields for files and folders.'),
      ],
      self::FORM_FIELDS_RESTORE_FORMS => [
        '#type'     => 'button',
        '#value'    => $this->t('Restore original settings'),
        '#disabled' => $formResetDisabled,
        '#name'     => self::FORM_FIELDS_RESTORE_FORMS,
        '#prefix'   => '<dd>',
        '#suffix  ' => '</dd>',
      ],
    ];

    $form[$tabName]['manage-field-ui']['section']['manage-display'] = [
      '#type'      => 'container',
      '#prefix'    => '<dl>',
      '#suffix'    => '</dl>',
      'title'      => [
        '#type'    => 'html_tag',
        '#tag'     => 'dt',
        '#value'   => $manageDisplayLink,
      ],
      'data'       => [
        '#type'    => 'html_tag',
        '#tag'     => 'dd',
        '#value'   => $this->t('Manage how fields are presented on file and folder pages.'),
      ],
      self::FORM_FIELDS_RESTORE_DISPLAYS => [
        '#type'     => 'button',
        '#value'    => $this->t('Restore original settings'),
        '#disabled' => $displayResetDisabled,
        '#name'     => self::FORM_FIELDS_RESTORE_DISPLAYS,
        '#prefix'   => '<dd>',
        '#suffix  ' => '</dd>',
      ],
    ];

    //
    // Related settings
    // ----------------
    // Add links to settings.
    $markup = '';

    // Content language.
    $markup .= $this->buildRelatedSettings(
      'language',
      'Content Language',
      'language.content_settings_page',
      [],
      'Enable users to select a default language for appropriate text fields.',
      TRUE,
      []);

    // Comment types.
    $markup .= $this->buildRelatedSettings(
      'comment',
      'Comment types',
      'entity.comment_type.collection',
      [],
      'Configure how comments are created and shown on files and folders.',
      TRUE,
      ['comment-module' => 'Comments']);

    // Datetime.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Dates and times',
      'entity.date_format.collection',
      [],
      'Configure date and time formats available when creation and modification dates are displayed.',
      TRUE,
      ['datetime' => 'Date-Time']);

    // Language.
    $markup .= $this->buildRelatedSettings(
      'language',
      'Languages',
      'entity.configurable_language.collection',
      [],
      'Enable users to provide authored translations of appropriate text fields.',
      TRUE,
      []);

    // Regional.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Regional',
      'system.regional_settings',
      [],
      'Configure regional formats for the display of dates and times, and select how times are mapped to the current or user time zone.',
      TRUE,
      []);

    // Text editors.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Text Editors',
      'filter.admin_overview',
      [],
      'Configure WYSIWYG text editors available on entity edit forms to assist in editing formatted text.',
      TRUE,
      ['editor' => 'Text editor']);

    // Text formats.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Text Formats',
      'filter.admin_overview',
      [],
      'Configure text formats available on entity edit forms to filter and process formatted text.',
      TRUE,
      ['filter' => 'Filter']);

    // Tokens.
    $markup .= $this->buildRelatedSettings(
      'token',
      'Tokens',
      'help.page',
      ['name' => 'token'],
      'Manage tokens created by this module and others.',
      FALSE,
      []);

    $form[$tabName]['related-settings'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('See also') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'modules'        => [
          '#type'        => 'item',
          '#markup'      => '<dl>' . $markup . '</dl>',
          '#attributes'  => [
            'class'      => [$relatedClass],
          ],
        ],
      ],
    ];
  }

  /**
   * Validates form items from the fields tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function validateFieldsTab(
    array &$form,
    FormStateInterface $formState) {

    //
    // Restores
    // --------
    // Restore to an original configuration.
    $restores = [
      self::FORM_FIELDS_RESTORE_FORMS => 'entity_form_display.foldershare.foldershare.default',
      self::FORM_FIELDS_RESTORE_DISPLAYS => 'entity_view_display.foldershare.foldershare.default',
    ];

    $userInput = $formState->getUserInput();
    $formFieldName = '';
    $configName = '';
    foreach ($restores as $key => $value) {
      if (isset($userInput[$key]) === TRUE) {
        $formFieldName = $key;
        $configName = $value;
        break;
      }
    }

    if (empty($formFieldName) === TRUE) {
      // None of the configurations were restored.  Perhaps something else
      // on the form was triggered. Return silently.
      return;
    }

    // Restore the configuration.
    $status = Utilities::revertConfiguration('core', $configName);

    // Unset the button in form state since it has already been handled.
    unset($userInput[$formFieldName]);
    $formState->setUserInput($userInput);

    // Rebuild the page.
    $formState->setRebuild(TRUE);
    if ($status === TRUE) {
      drupal_set_message(
        t('The item has been restored to its original configuration.'),
        'status');
    }
    else {
      drupal_set_message(
        t('An unexpected error occurred and the item could not be restored.'),
        'error');
    }
  }

  /**
   * Stores the submitted form values for the fields tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function submitFieldsTab(
    array &$form,
    FormStateInterface $formState) {

    // Nothing to do.
  }

  /*---------------------------------------------------------------------
   *
   * Views tab.
   *
   * These functions build the Views settings tab on the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds the views tab for the form.
   *
   * Settings:
   * - Buttons to reset each of the views.
   * - Links to related module settings.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   * @param string $tabGroup
   *   The name of the tab group.
   * @param string[] $terms
   *   Current terminology.
   */
  private function buildViewsTab(
    array &$form,
    FormStateInterface $formState,
    string $tabGroup,
    array $terms) {

    //
    // Setup
    // -----
    // Set up some variables.
    $mh = \Drupal::service('module_handler');

    $tabName                 = $terms['module'] . '_views_tab';
    $tabSubtitleClass        = $terms['module'] . '-settings-subtitle';
    $tabDescriptionClass     = $terms['module'] . '-settings-description';
    $sectionTitleClass       = $terms['module'] . '-settings-section-title';
    $sectionClass            = $terms['module'] . '-settings-section';
    $sectionDescriptionClass = $terms['module'] . '-settings-section-description';
    $warningClass            = $terms['module'] . '-warning';
    $relatedClass            = $terms['module'] . '-related-links';

    //
    // Create the tab
    // --------------
    // Start the tab with a title, subtitle, and description.
    $form[$tabName] = [
      '#type'           => 'details',
      '#open'           => FALSE,
      '#group'          => $tabGroup,
      '#title'          => $this->t('Lists'),
      '#description'    => [
        'subtitle'      => [
          '#type'       => 'html_tag',
          '#tag'        => 'h2',
          '#value'      => $this->t(
            'Manage lists of files and folders.'),
          '#attributes' => [
            'class'     => [$tabSubtitleClass],
          ],
        ],
        'description'   => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'You may configure file and folder lists to adjust which fields are displayed and how their values are presented.'),
          '#attributes' => [
            'class'     => [$tabDescriptionClass],
          ],
        ],
      ],
      '#attributes'     => [
        'class'         => [
          $terms['module'] . '-settings-tab ',
          $terms['module'] . '-views-tab',
        ],
      ],
    ];

    //
    // Manage views
    // ------------
    // List and reset views.
    $viewsUiInstalled = ($mh->moduleExists('views_ui') === TRUE);
    if ($viewsUiInstalled === TRUE) {
      $viewsUiLink = Utilities::createRouteLink(
        'entity.view.collection',
        '',
        'Views UI module');
    }
    else {
      $viewsUiLink = $this->t('Views UI module');
    }

    $form[$tabName]['manage-views-ui'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('Views') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'The layout and presentation of lists may be controlled using the optional @viewsui.',
            [
              '@viewsui'   => $viewsUiLink,
            ]),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
      ],
    ];

    //
    // Create warning
    // --------------
    // If the view_ui module is not installed, links to the views config
    // pages will not work. Create a warning.
    if ($viewsUiInstalled === FALSE) {
      $disabledWarning = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t(
          'These items are disabled because they require the Views UI module. To use these settings, you must enable the @viewsui.',
          [
            '@viewsui' => Utilities::createRouteLink(
              'system.modules_list',
              'module-views-ui',
              'Views UI module'),
          ]),
        '#attributes' => [
          'class'     => [$warningClass],
        ],
      ];
      $viewsUiDisabled = TRUE;

      $form[$tabName]['manage-views-ui']['section']['warning'] = $disabledWarning;
    }
    else {
      // If the user does not have view administation permission,
      // links to views config pages will not work. Create a warning.
      $account = \Drupal::currentUser();

      $administerViews = $account->hasPermission('administer views');

      if ($administerViews === FALSE) {
        $disabledWarning = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'These items are disabled because they require that you have additional permissions. To use these settings, you need @viewui permissions to administer views.',
            [
              '@viewui' => Utilities::createRouteLink(
                'user.admin_permissions',
                'module-view-ui',
                'View UI module'),
            ]),
          '#attributes' => [
            'class'     => [$warningClass],
          ],
        ];
        $viewsUiDisabled = TRUE;

        $form[$tabName]['manage-view-ui']['section']['warning'] = $disabledWarning;
      }
      else {
        $viewsUiDisabled = FALSE;
      }
    }

    //
    // Create view links
    // -----------------
    // For each well-known view, create a link to the view. What we have
    // is each view's machine name. We need to load the entity, if any,
    // to get its current name, and load the on-disk configuration, if any,
    // to get its original name.
    //
    // If there is no on-disk configuration, then we can't reset it.
    //
    // If there is no current entity, we can't link to it.
    $viewLinks = [
      // Only one multi-purpose view at this time.
      [
        'viewMachineName' => Constants::VIEW_LISTS,
        'description' => 'List files, folders, and subfolders.',
      ],
    ];

    // Collect information about the views, such as whether the view
    // exists and its name.
    foreach ($viewLinks as $index => $viewLink) {
      $viewMachineName = $viewLink['viewMachineName'];

      // By default:
      // - Use the machine name we have for the original and current name.
      // - Do not disable a link to the view.
      // - Do not disable a reset button for the view.
      $viewLinks[$index]['originalName'] = $viewMachineName;
      $viewLinks[$index]['currentName'] = $viewMachineName;
      $viewLinks[$index]['disableLink'] = FALSE;
      $viewLinks[$index]['disableReset'] = FALSE;

      // If the Views UI module is not enabled, or if the user does not
      // have administration permission, then we cannot link to the view
      // or reset it.
      if ($viewsUiDisabled === TRUE) {
        $viewLinks[$index]['disableLink'] = TRUE;
        $viewLinks[$index]['disableReset'] = TRUE;
      }

      // Load the current entity. If the view does not exist, we cannot
      // link to it.
      $viewEntity = View::load($viewMachineName);
      if ($viewEntity === NULL) {
        // No entity. Cannot link to it.
        $viewLinks[$index]['disableLink'] = TRUE;
      }
      else {
        $viewLinks[$index]['currentName'] = $viewEntity->label();
      }

      // Load the configuration from disk. If the configuration does not
      // exist, we cannot reset the view using the disk configuration.
      $viewConfig = Utilities::loadConfiguration('view', $viewMachineName);
      if (empty($viewConfig) === TRUE) {
        // No configuration. Cannot reset it.
        $viewLinks[$index]['disableReset'] = TRUE;
      }
      elseif (isset($viewConfig['label']) === TRUE) {
        $viewLinks[$index]['originalName'] = $viewConfig['label'];
      }
    }

    // Format the views. For each one, link to the view and show a reset
    // button.
    foreach ($viewLinks as $index => $viewLink) {
      $viewMachineName = $viewLink['viewMachineName'];

      // Create the link to the current page, if any.
      if ($viewLink['disableLink'] === TRUE) {
        $link = Html::escape($viewLink['originalName']);
      }
      else {
        $link = Link::createFromRoute(
          Html::escape($viewLink['currentName']),
          Constants::ROUTE_VIEWS_UI_VIEW,
          ['view' => $viewMachineName])->toString();
      }

      // Create notes about creating the view and changing the name.
      $note = '';
      if ($viewLink['disableLink'] === TRUE) {
        $note = $this->t(
          'Resetting the view will re-create the original view.');
      }
      elseif ($viewLink['currentName'] !== $viewLink['originalName']) {
        $note = $this->t(
          'Resetting the view will restore its original "@name" name.',
          [
            '@name' => $viewLink['originalName'],
          ]);
      }

      $form[$tabName]['manage-views-ui']['section']['section-' . $viewMachineName] = [
        '#type'       => 'container',
        '#prefix'     => '<dl>',
        '#suffix'     => '</dl>',
        'title'       => [
          '#type'     => 'html_tag',
          '#tag'      => 'dt',
          '#value'    => $link,
        ],
        'data'        => [
          '#type'     => 'html_tag',
          '#tag'      => 'dd',
          '#value'    => $this->t($viewLink['description']) . ' ' . $note,
        ],
        $viewMachineName => [
          '#type'     => 'button',
          '#value'    => $this->t('Restore original settings'),
          '#name'     => $viewMachineName,
          '#disabled' => $viewLink['disableReset'],
          '#prefix'   => '<dd>',
          '#suffix'   => '</dd>',
        ],
      ];
    }

    //
    // Related settings
    // ----------------
    // Add links to related settings.
    $markup = '';

    // Datetime.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Dates and times',
      'entity.date_format.collection',
      [],
      'Configure date and time formats available when creation and modification dates are displayed.',
      TRUE,
      ['datetime' => 'Date-Time']);

    // Regional.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Regional',
      'system.regional_settings',
      [],
      'Configure regional formats for the display of dates and times, and select how times are mapped to the current or user time zone.',
      TRUE,
      []);

    $form[$tabName]['related-settings-display'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('See also') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'modules'        => [
          '#type'        => 'item',
          '#markup'      => '<dl>' . $markup . '</dl>',
          '#attributes'  => [
            'class'      => [$relatedClass],
          ],
        ],
      ],
    ];
  }

  /**
   * Validates form items from the views tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function validateViewsTab(
    array &$form,
    FormStateInterface $formState) {

    //
    // Restores
    // --------
    // Restore to an original configuration.
    $restores = [
      Constants::VIEW_LISTS,
    ];

    // Figure out which view reset button was pressed, if any.
    $userInput = $formState->getUserInput();
    $viewMachineName = '';
    foreach ($restores as $name) {
      if (isset($userInput[$name]) === TRUE) {
        $viewMachineName = $name;
        break;
      }
    }

    if (empty($viewMachineName) === TRUE) {
      // None of the views were restored.  Perhaps something else
      // on the form was triggered. Return silently.
      return;
    }

    // Restore the view.
    $status = Utilities::revertConfiguration('view', $viewMachineName);

    // Unset the button in form state since it has already been handled.
    unset($userInput[$viewMachineName]);
    $formState->setUserInput($userInput);

    // Rebuild the page.
    $formState->setRebuild(TRUE);
    if ($status === TRUE) {
      drupal_set_message(
        t('The view has been restored to its original configuration.'),
        'status');
    }
    else {
      drupal_set_message(
        t('An unexpected error occurred and the view could not be restored.'),
        'error');
    }
  }

  /**
   * Stores the submitted form values for the views tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function submitViewsTab(
    array &$form,
    FormStateInterface $formState) {

    // Nothing to do.
  }

  /*---------------------------------------------------------------------
   *
   * Search tab.
   *
   * These functions build the Search settings tab on the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds the search tab for the form.
   *
   * Settings:
   * - Link to search configuration.
   * - Button to reset search configuration.
   * - Links to related module settings.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   * @param string $tabGroup
   *   The name of the tab group.
   * @param string[] $terms
   *   Current terminology.
   */
  private function buildSearchTab(
    array &$form,
    FormStateInterface $formState,
    string $tabGroup,
    array $terms) {

    //
    // Setup
    // -----
    // Set up some variables.
    $mh = \Drupal::service('module_handler');

    $tabName                 = $terms['module'] . '_search_tab';
    $tabSubtitleClass        = $terms['module'] . '-settings-subtitle';
    $tabDescriptionClass     = $terms['module'] . '-settings-description';
    $sectionTitleClass       = $terms['module'] . '-settings-section-title';
    $sectionClass            = $terms['module'] . '-settings-section';
    $sectionDescriptionClass = $terms['module'] . '-settings-section-description';
    $warningClass            = $terms['module'] . '-warning';
    $relatedClass            = $terms['module'] . '-related-links';

    //
    // Create the tab
    // --------------
    // Start the tab with a title, subtitle, and description.
    $form[$tabName] = [
      '#type'           => 'details',
      '#open'           => FALSE,
      '#group'          => $tabGroup,
      '#title'          => $this->t('Search'),
      '#description'    => [
        'subtitle'      => [
          '#type'       => 'html_tag',
          '#tag'        => 'h2',
          '#value'      => $this->t(
            'Manage search indexing and results.'),
          '#attributes' => [
            'class'     => [$tabSubtitleClass],
          ],
        ],
        'description'   => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'File and folder names, descriptions, and file content is indexed and shown in search results. You may configure when indexing is done, what is included, and how search results are presented.'),
          '#attributes' => [
            'class'     => [$tabDescriptionClass],
          ],
        ],
      ],
      '#attributes'     => [
        'class'         => [
          $terms['module'] . '-settings-tab ',
          $terms['module'] . '-search-tab',
        ],
      ],
    ];

    //
    // Manage search
    // -------------
    // The search configuration is created by the core search module.
    $searchInstalled = ($mh->moduleExists('search') === TRUE);
    if ($searchInstalled === TRUE) {
      $searchLink = Utilities::createHelpLink('search', 'Search module');
    }
    else {
      $searchLink = $this->t('Search module');
    }

    $form[$tabName]['manage-search'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('Core search') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'The optional @search provides basic support for searching content. @moduletitle provides an optional search plugin to index and present search results for files and folders.',
            [
              '@moduletitle' => $terms['moduletitle'],
              '@search'      => $searchLink,
            ]),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
      ],
    ];

    //
    // Create warning
    // --------------
    // If the search module is not installed, links to it will
    // not work. Create a warning.
    if ($searchInstalled === FALSE) {
      $disabledWarning = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t(
          'These items are disabled because they require the Search module. To use these settings, you must enable the @search.',
          [
            '@search' => Utilities::createRouteLink(
              'system.modules_list',
              'module-search',
              'Search module'),
          ]),
        '#attributes' => [
          'class'     => [$warningClass],
        ],
      ];
      $searchDisabled = TRUE;

      $form[$tabName]['manage-search']['section']['warning'] = $disabledWarning;
    }
    else {
      // If the user does not have search administation permission,
      // links to search config pages will not work. Create a warning.
      $account = \Drupal::currentUser();

      $administerSearch = $account->hasPermission(
        'administer search');

      if ($administerSearch === FALSE) {
        $disabledWarning = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'These items are disabled because they require that you have additional permissions. To use these settings, you need @search permissions to administer search.',
            [
              '@search' => Utilities::createRouteLink(
                'user.admin_permissions',
                'module-search',
                'Search module'),
            ]),
          '#attributes' => [
            'class'     => [$warningClass],
          ],
        ];
        $searchDisabled = TRUE;

        $form[$tabName]['manage-view-ui']['section']['warning'] = $disabledWarning;
      }
      else {
        $searchDisabled = FALSE;
      }
    }

    //
    // Create links
    // ------------
    // Create links to the search module, if possible.
    if ($searchDisabled === FALSE) {
      // The module is installed. Use its routes.
      $searchLink = Utilities::createRouteLink(
            'entity.search_page.collection');
      $resetDisabled = FALSE;
    }
    else {
      // The module is NOT installed. Just use text.
      $searchLink = $this->t('Search');
      $resetDisabled = TRUE;
    }

    $form[$tabName]['manage-search']['section']['coresearch'] = [
      '#type'    => 'container',
      '#prefix'  => '<dl>',
      '#suffix'  => '</dl>',
      'title'    => [
        '#type'  => 'html_tag',
        '#tag'   => 'dt',
        '#value' => $searchLink,
      ],
      'data'     => [
        '#type'  => 'html_tag',
        '#tag'   => 'dd',
        '#value' => $this->t('Configure how search creates indexes of site content and presents search results.'),
      ],
      self::FORM_SEARCH_RESTORE_CORESEARCH => [
        '#type'     => 'button',
        '#value'    => $this->t('Restore original settings'),
        '#disabled' => $resetDisabled,
        '#name'     => self::FORM_SEARCH_RESTORE_CORESEARCH,
        '#prefix'   => '<dd>',
        '#suffix'   => '</dd>',
      ],
    ];

    //
    // Related settings
    // ----------------
    // Add links to related settings.
    $markup = '';

    // Cron.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'Cron',
      'system.cron_settings',
      [],
      'Configure and run automatic administrative tasks, such as when search indexing is done.',
      TRUE,
      [
        'system'                => 'System',
        'automated-cron-module' => 'Automated Cron',
      ]);

    $form[$tabName]['related-settings'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('See also') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'modules'        => [
          '#type'        => 'item',
          '#markup'      => '<dl>' . $markup . '</dl>',
          '#attributes'  => [
            'class'      => [$relatedClass],
          ],
        ],
      ],
    ];
  }

  /**
   * Validates form items from the search tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function validateSearchTab(
    array &$form,
    FormStateInterface $formState) {

    //
    // Restores
    // --------
    // Restore to an original configuration.
    $restores = [
      self::FORM_SEARCH_RESTORE_CORESEARCH => 'page.foldershare_search',
    ];

    $userInput = $formState->getUserInput();
    $formFieldName = '';
    $configName = '';
    foreach ($restores as $key => $value) {
      if (isset($userInput[$key]) === TRUE) {
        $formFieldName = $key;
        $configName = $value;
        break;
      }
    }

    if (empty($formFieldName) === TRUE) {
      // None of the configurations were restored.  Perhaps something else
      // on the form was triggered. Return silently.
      return;
    }

    // Restore the view.
    $status = Utilities::revertConfiguration('search', $configName);

    // Unset the button in form state since it has already been handled.
    unset($userInput[$formFieldName]);
    $formState->setUserInput($userInput);

    // Rebuild the page.
    $formState->setRebuild(TRUE);
    if ($status === TRUE) {
      drupal_set_message(
        t('The item has been restored to its original configuration.'),
        'status');
    }
    else {
      drupal_set_message(
        t('An unexpected error occurred and the item could not be restored.'),
        'error');
    }
  }

  /**
   * Stores the submitted form values for the search tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function submitSearchTab(
    array &$form,
    FormStateInterface $formState) {

    // Nothing to do.
  }

  /*---------------------------------------------------------------------
   *
   * Security tab.
   *
   * These functions build the Security settings tab on the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds the security tab for the form.
   *
   * Settings:
   * - Link to search configuration.
   * - Button to reset search configuration.
   * - Links to related module settings.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   * @param string $tabGroup
   *   The name of the tab group.
   * @param string[] $terms
   *   Current terminology.
   */
  private function buildSecurityTab(
    array &$form,
    FormStateInterface $formState,
    string $tabGroup,
    array $terms) {

    //
    // Setup
    // -----
    // Set up some variables.
    $mh = \Drupal::service('module_handler');

    $tabName                 = $terms['module'] . '_security_tab';
    $tabSubtitleClass        = $terms['module'] . '-settings-subtitle';
    $tabDescriptionClass     = $terms['module'] . '-settings-description';
    $sectionTitleClass       = $terms['module'] . '-settings-section-title';
    $sectionClass            = $terms['module'] . '-settings-section';
    $sectionDescriptionClass = $terms['module'] . '-settings-section-description';
    $warningClass            = $terms['module'] . '-warning';
    $relatedClass            = $terms['module'] . '-related-links';

    //
    // Create the tab
    // --------------
    // Start the tab with a title, subtitle, and description.
    $form[$tabName] = [
      '#type'           => 'details',
      '#open'           => FALSE,
      '#group'          => $tabGroup,
      '#title'          => $this->t('Security'),
      '#description'    => [
        'subtitle'      => [
          '#type'       => 'html_tag',
          '#tag'        => 'h2',
          '#value'      => $this->t(
            'Manage how content is secured and shared.'),
          '#attributes' => [
            'class'     => [$tabSubtitleClass],
          ],
        ],
        'description'   => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'Files and folders may be shared among users and with the public. Content may be accessed using a web browser and using optional services that work with software that runs on personal computers and mobile devices.'),
          '#attributes' => [
            'class'     => [$tabDescriptionClass],
          ],
        ],
      ],
      '#attributes'     => [
        'class'         => [
          $terms['module'] . '-settings-tab ',
          $terms['module'] . '-security-tab',
        ],
      ],
    ];

    //
    // Manage sharing
    // --------------
    // The sharing configuration is created by this module.
    $form[$tabName]['manage-sharing'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('Sharing') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'    => [
          '#type'        => 'html_tag',
          '#tag'         => 'p',
          '#value'       => $this->t(
            'Select whether to enable sharing, and whether users may may publish their content for public access by anonymous site visitors. When sharing is disabled, users may only view and edit their own files and folders.'),
          '#attributes'  => [
            'class'      => [$sectionDescriptionClass],
          ],
        ],
        self::FORM_SECURITY_ALLOW_SHARING => [
          '#type'          => 'checkbox',
          '#title'         => $this->t('Enable shared access'),
          '#default_value' => Settings::getSharingAllowed(),
          '#return_value'  => 'enabled',
          '#required'      => FALSE,
          '#name'          => self::FORM_SECURITY_ALLOW_SHARING,
        ],
        self::FORM_SECURITY_ALLOW_SHARING_WITH_ANONYMOUS => [
          '#type'          => 'checkbox',
          '#title'         => $this->t('Enable shared access with anonymous users'),
          '#default_value' => Settings::getSharingAllowedWithAnonymous(),
          '#return_value'  => 'enabled',
          '#required'      => FALSE,
          '#states'        => [
            'invisible'    => [
              'input[name="' . self::FORM_SECURITY_ALLOW_SHARING . '"]' => [
                'checked' => FALSE,
              ],
            ],
          ],
        ],
      ],
    ];

    //
    // Related settings
    // ----------------
    // Add links to related settings.
    $markup = '';

    // User permissions.
    $markup .= $this->buildRelatedSettings(
      // No specific module.
      '',
      'User permissions',
      'user.admin_permissions',
      ['module-' . Constants::MODULE],
      'Grant view, author, share, and administer permissions to user roles.',
      TRUE,
      ['user' => 'User']);

    $form[$tabName]['related-settings'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('See also') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'modules'        => [
          '#type'        => 'item',
          '#markup'      => '<dl>' . $markup . '</dl>',
          '#attributes'  => [
            'class'      => [$relatedClass],
          ],
        ],
      ],
    ];
  }

  /**
   * Validates form items from the security tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function validateSecurityTab(
    array &$form,
    FormStateInterface $formState) {

    // Nothing to do.
  }

  /**
   * Stores the submitted form values for the search tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function submitSecurityTab(
    array &$form,
    FormStateInterface $formState) {

    // Sharing enable/disable.
    $value = $formState->getValue(self::FORM_SECURITY_ALLOW_SHARING);
    $enabled = ($value === 'enabled');

    if (Settings::getSharingAllowed() !== $enabled) {
      // Update and clear render cache.
      Settings::setSharingAllowed($enabled);
      Cache::invalidateTags(['rendered']);
    }

    // Anonymous sharing enable/disable.
    $value = $formState->getValue(self::FORM_SECURITY_ALLOW_SHARING_WITH_ANONYMOUS);
    $enabled = ($value === 'enabled');

    if (Settings::getSharingAllowedWithAnonymous() !== $enabled) {
      // Update and clear render cache.
      Settings::setSharingAllowedWithAnonymous($enabled);
      Cache::invalidateTags(['rendered']);
    }
  }

  /*---------------------------------------------------------------------
   *
   * Interface tab.
   *
   * These functions build the Interface settings tab on the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds the interface tab for the form.
   *
   * Settings:
   * - Checkboxes to enable menu commands.
   * - Checkboxes to enable toolbar buttons.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   * @param string $tabGroup
   *   The name of the tab group.
   * @param string[] $terms
   *   Current terminology.
   */
  private function buildInterfaceTab(
    array &$form,
    FormStateInterface $formState,
    string $tabGroup,
    array $terms) {

    //
    // Setup
    // -----
    // Set up some variables.
    $tabName                 = $terms['module'] . '_interface_tab';
    $tabSubtitleClass        = $terms['module'] . '-settings-subtitle';
    $tabDescriptionClass     = $terms['module'] . '-settings-description';
    $sectionTitleClass       = $terms['module'] . '-settings-section-title';
    $sectionClass            = $terms['module'] . '-settings-section';
    $sectionDescriptionClass = $terms['module'] . '-settings-section-description';

    //
    // Create the tab
    // --------------
    // Start the tab with a title, subtitle, and description.
    $form[$tabName] = [
      '#type'           => 'details',
      '#open'           => FALSE,
      '#group'          => $tabGroup,
      '#title'          => $this->t('Interface'),
      '#description'    => [
        'subtitle'      => [
          '#type'       => 'html_tag',
          '#tag'        => 'h2',
          '#value'      => $this->t(
            'Manage the user interface.'),
          '#attributes' => [
            'class'     => [$tabSubtitleClass],
          ],
        ],
        'description'   => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'File and folder lists include a menu and toolbar for commands to create, upload, edit, and delete items. You may configure select the commands to include.'),
          '#attributes' => [
            'class'     => [$tabDescriptionClass],
          ],
        ],
      ],
      '#attributes'     => [
        'class'         => [
          $terms['module'] . '-settings-tab ',
          $terms['module'] . '-interface-tab',
        ],
      ],
    ];

    //
    // Manage menus
    // ------------
    // Select commands to include in menus.
    $allNames = [];
    $allChosen = [];
    foreach (Settings::getAllCommandDefinitions() as $id => $def) {
      $provider    = $def['provider'];
      $description = $def['description'];

      $allNames[$id] = '<dt>' . $def['label'] . '</dt><dd>' . $description .
        ' <em>(' . $provider . ' module)</em></dd>';
      $allChosen[$id] = NULL;
    }

    foreach (Settings::getCommandMenuAllowed() as $id) {
      $allChosen[$id] = $id;
    }

    $form[$tabName]['manage-menus'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('Menus') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'Commands are plugins provided by this module and others. By default, all available commands are included on menus. You may restrict menus to a specific set of commands.'),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
        self::FORM_COMMAND_MENU_ALLOW_RESTRICTIONS => [
          '#type'          => 'checkbox',
          '#title'         => $this->t('Enable command menu restrictions'),
          '#default_value' => Settings::getCommandMenuRestrict(),
          '#return_value'  => 'enabled',
          '#required'      => FALSE,
          '#name'          => self::FORM_COMMAND_MENU_ALLOW_RESTRICTIONS,
        ],
        'foldershare_command_menu_choices' => [
          '#type'          => 'container',
          '#states'        => [
            'invisible'    => [
              'input[name="' . self::FORM_COMMAND_MENU_ALLOW_RESTRICTIONS . '"]' => [
                'checked' => FALSE,
              ],
            ],
          ],
          'description'      => [
            '#type'          => 'html_tag',
            '#tag'           => 'p',
            '#value'         => $this->t('Select the commands to allow:'),
          ],
          self::FORM_COMMAND_MENU_ALLOWED => [
            '#type'          => 'checkboxes',
            '#options'       => $allNames,
            '#default_value' => $allChosen,
            '#prefix'        => '<dl>',
            '#suffix'        => '</dl>',
          ],
        ],
      ],
    ];
  }

  /**
   * Validates form items from the interface tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function validateInterfaceTab(
    array &$form,
    FormStateInterface $formState) {

    // Nothing to do.
  }

  /**
   * Stores the submitted form values for the interface tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function submitInterfaceTab(
    array &$form,
    FormStateInterface $formState) {

    // Get whether the command menu is restricted.
    $value = $formState->getValue(self::FORM_COMMAND_MENU_ALLOW_RESTRICTIONS);
    $enabled = ($value === 'enabled') ? TRUE : FALSE;
    Settings::setCommandMenuRestrict($enabled);

    // Get allowed menu commands.
    $allChosen = $formState->getValue(self::FORM_COMMAND_MENU_ALLOWED);
    Settings::setCommandMenuAllowed(array_keys(array_filter($allChosen)));

    Cache::invalidateTags(['rendered']);
  }

  /*---------------------------------------------------------------------
   *
   * Web services tab.
   *
   * These functions build the Web services settings tab on the form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds the web services tab for the form.
   *
   * Settings:
   * - Link to REST configuration.
   * - Links to related module settings.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   * @param string $tabGroup
   *   The name of the tab group.
   * @param string[] $terms
   *   Current terminology.
   */
  private function buildServicesTab(
    array &$form,
    FormStateInterface $formState,
    string $tabGroup,
    array $terms) {

    //
    // Setup
    // -----
    // Set up some variables.
    $mh = \Drupal::service('module_handler');

    $tabName                 = $terms['module'] . '_services_tab';
    $tabSubtitleClass        = $terms['module'] . '-settings-subtitle';
    $tabDescriptionClass     = $terms['module'] . '-settings-description';
    $sectionTitleClass       = $terms['module'] . '-settings-section-title';
    $sectionClass            = $terms['module'] . '-settings-section';
    $sectionDescriptionClass = $terms['module'] . '-settings-section-description';
    $warningClass            = $terms['module'] . '-warning';
    $relatedClass            = $terms['module'] . '-related-links';

    //
    // Create the tab
    // --------------
    // Start the tab with a title, subtitle, and description.
    $form[$tabName] = [
      '#type'           => 'details',
      '#open'           => FALSE,
      '#group'          => $tabGroup,
      '#title'          => $this->t('Web&nbsp;services'),
      '#description'    => [
        'subtitle'      => [
          '#type'       => 'html_tag',
          '#tag'        => 'h2',
          '#value'      => $this->t(
            'Manage REST web services.'),
          '#attributes' => [
            'class'     => [$tabSubtitleClass],
          ],
        ],
        'description'   => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'Files and folders are always accessible via a web browser, but additional web services may be enabled to allow client applications to access content without a browser. You may configure these services to enable or disable specific types of access.'),
          '#attributes' => [
            'class'     => [$tabDescriptionClass],
          ],
        ],
      ],
      '#attributes'     => [
        'class'         => [
          $terms['module'] . '-settings-tab ',
          $terms['module'] . '-services-tab',
        ],
      ],
    ];

    //
    // Manage web services
    // -------------------
    // The REST configuration is adjustable via the REST UI module.
    $restuiInstalled = ($mh->moduleExists('restui') === TRUE);
    if ($restuiInstalled === TRUE) {
      $restuiHelpLink = Utilities::createHelpLink('restui', 'REST UI module');
    }
    else {
      $restuiHelpLink = $this->t('REST UI module');
    }

    $restInstalled = ($mh->moduleExists('rest') === TRUE);
    if ($restInstalled === TRUE) {
      $restHelpLink = Utilities::createHelpLink('rest', 'REST module');
    }
    else {
      $restHelpLink = $this->t('REST module');
    }

    $form[$tabName]['manage-rest'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('REST web services') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'description'      => [
          '#type'          => 'html_tag',
          '#tag'           => 'p',
          '#value'         => $this->t(
            'The @rest manages REST (Representational State Transfer) web services, such as those provided by @moduletitle. While you can edit YML files to customize a configuration, the optional @restui provides a better user interface. Using the @restui you may enable and disable specific services and adjust their features.',
            [
              '@moduletitle' => $terms['moduletitle'],
              '@restui'      => $restuiHelpLink,
              '@rest'        => $restHelpLink,
            ]),
          '#attributes'    => [
            'class'        => [$sectionDescriptionClass],
          ],
        ],
      ],
    ];

    //
    // Create warning
    // --------------
    // If the REST module is not installed, links to it will
    // not work. Create a warning.
    if ($restInstalled === FALSE) {
      $disabledWarning = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t(
          'These items are disabled because they require the @rest. To use these settings, you must enable the @rest.',
          [
            '@rest' => Utilities::createRouteLink(
              'system.modules_list',
              'module-rest',
              'REST module'),
          ]),
        '#attributes' => [
          'class'     => [$warningClass],
        ],
      ];
      $restDisabled = TRUE;

      $form[$tabName]['manage-rest']['section']['warning'] = $disabledWarning;
    }
    else {
      // If the user does not have REST administation permission,
      // links to REST config pages will not work. Create a warning.
      $account = \Drupal::currentUser();

      $administerRest = $account->hasPermission(
        'administer rest resources');

      if ($administerRest === FALSE) {
        $disabledWarning = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'These items are disabled because they require that you have additional permissions. To use these settings, you need @rest permissions to administer REST resources.',
            [
              '@rest' => Utilities::createRouteLink(
                'user.admin_permissions',
                'module-rest',
                'REST module'),
            ]),
          '#attributes' => [
            'class'     => [$warningClass],
          ],
        ];
        $restDisabled = TRUE;

        $form[$tabName]['manage-view-ui']['section']['warning'] = $disabledWarning;
      }
      else {
        $restDisabled = FALSE;
      }
    }

    //
    // Create links
    // ------------
    // Create links to the REST module, if possible.
    if ($restDisabled === FALSE) {
      // The module is installed but the module has no configuration page.
      // Use the help link already created above.
      $resetDisabled = FALSE;
    }
    else {
      // The module is NOT installed. Just use text.
      $resetDisabled = TRUE;
    }

    $form[$tabName]['manage-rest']['section']['rest'] = [
      '#type'    => 'container',
      '#prefix'  => '<dl>',
      '#suffix'  => '</dl>',
      'title'    => [
        '#type'  => 'html_tag',
        '#tag'   => 'dt',
        '#value' => $restHelpLink,
      ],
      'data'     => [
        '#type'  => 'html_tag',
        '#tag'   => 'dd',
        '#value' => $this->t('Provide REST web services for entities and other features at the site.'),
      ],
      self::FORM_REST_RESTORE_REST => [
        '#type'     => 'button',
        '#value'    => $this->t('Restore original settings'),
        '#disabled' => $resetDisabled,
        '#name'     => self::FORM_REST_RESTORE_REST,
        '#prefix'   => '<dd>',
        '#suffix'   => '</dd>',
      ],
    ];

    //
    // Related settings
    // ----------------
    // Add links to related settings.
    $markup = '';

    // REST permissions.
    $markup .= $this->buildRelatedSettings(
      'rest',
      'REST administration permissions',
      'user.admin_permissions',
      ['module-rest'],
      'Grant administrative permissions for REST services.',
      TRUE,
      ['rest' => 'REST']);

    // REST UI.
    $markup .= $this->buildRelatedSettings(
      'restui',
      'REST UI',
      'restui.list',
      [],
      'Manage the REST configuration for the site.',
      FALSE,
      []);

    $form[$tabName]['related-settings'] = [
      '#type'            => 'item',
      '#markup'          => '<h3>' . $this->t('See also') . '</h3>',
      '#attributes'      => [
        'class'          => [$sectionTitleClass],
      ],
      'section'          => [
        '#type'          => 'container',
        '#attributes'    => [
          'class'        => [$sectionClass],
        ],
        'modules'        => [
          '#type'        => 'item',
          '#markup'      => '<dl>' . $markup . '</dl>',
          '#attributes'  => [
            'class'      => [$relatedClass],
          ],
        ],
      ],
    ];
  }

  /**
   * Validates form items from the web services tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function validateServicesTab(
    array &$form,
    FormStateInterface $formState) {

    //
    // Restores
    // --------
    // Restore to an original configuration.
    $restores = [
      self::FORM_REST_RESTORE_REST => 'rest.resource.entity.foldershare',
    ];

    $userInput = $formState->getUserInput();
    $formFieldName = '';
    $configName = '';
    foreach ($restores as $key => $value) {
      if (isset($userInput[$key]) === TRUE) {
        $formFieldName = $key;
        $configName = $value;
        break;
      }
    }

    if (empty($formFieldName) === TRUE) {
      // None of the configurations were restored.  Perhaps something else
      // on the form was triggered. Return silently.
      return;
    }

    // Restore the view.
    $status = Utilities::revertConfiguration('core', $configName);

    // Unset the button in form state since it has already been handled.
    unset($userInput[$formFieldName]);
    $formState->setUserInput($userInput);

    // Rebuild the page.
    $formState->setRebuild(TRUE);
    if ($status === TRUE) {
      drupal_set_message(
        t('The item has been restored to its original configuration.'),
        'status');
    }
    else {
      drupal_set_message(
        t('An unexpected error occurred and the item could not be restored.'),
        'error');
    }
  }

  /**
   * Stores the submitted form values for the web services tab.
   *
   * @param array $form
   *   The form configuration.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The entered values for the form.
   */
  private function submitServicesTab(
    array &$form,
    FormStateInterface $formState) {

    // Nothing to do.
  }

}
