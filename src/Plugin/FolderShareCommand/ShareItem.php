<?php

namespace Drupal\foldershare\Plugin\FolderShareCommand;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Utilities;
use Drupal\foldershare\FolderShareInterface;
use Drupal\foldershare\Entity\FolderShare;
use Drupal\foldershare\Plugin\FolderShareCommand\FolderShareCommandBase;
use Drupal\foldershare\Entity\FolderShareAccessControlHandler;

/**
 * Defines a command plugin to change share settings on a root folder.
 *
 * The command sets the access grants for the root folder of the selected
 * entity. Access grants enable/disable view and author access for
 * individual users.
 *
 * Configuration parameters:
 * - 'parentId': the parent folder, if any.
 * - 'selectionIds': selected entity who's root folder is shared.
 * - 'grants': the new access grants.
 *
 * @ingroup foldershare
 *
 * @FolderShareCommand(
 *  id          = "foldersharecommand_share",
 *  label       = @Translation("Share folder tree"),
 *  title       = @Translation("Share @operand folder tree"),
 *  menu        = @Translation("Share folder tree..."),
 *  tooltip     = @Translation("Share folder tree"),
 *  description = @Translation("Share a folder tree and all of its contents with other users."),
 *  category    = "settings",
 *  weight      = 20,
 *  parentConstraints = {
 *    "kinds"   = {
 *      "none",
 *      "any",
 *    },
 *    "access"  = "none",
 *  },
 *  selectionConstraints = {
 *    "types"   = {
 *      "parent",
 *      "one",
 *    },
 *    "kinds"   = {
 *      "any",
 *    },
 *    "access"  = "share",
 *  },
 * )
 */
class ShareItem extends FolderShareCommandBase {

  /*--------------------------------------------------------------------
   *
   * Constants.
   *
   *--------------------------------------------------------------------*/

  /**
   * Indicate whether settings for the site administrator should be shown.
   *
   * When TRUE, a table row for the 'admin' is included, even though the
   * site admin always has full access. When FALSE, the row is omitted.
   *
   * This is an internal configuration flag that can only be set by editing
   * the code. It may be useful to enable it while debugging.
   */
  const SHOW_SITE_ADMIN = FALSE;

  /**
   * Indicate whether settings for the root folder's owner should be shown.
   *
   * When TRUE, a table row for the owner is included, even though the
   * owner always has full access. When FALSE, the row is omitted.
   *
   * This is an internal configuration flag that can only be set by editing
   * the code. It may be useful to enable it while debugging.
   */
  const SHOW_OWNER = FALSE;

  /*--------------------------------------------------------------------
   *
   * Configuration.
   *
   * These functions initialize a default configuration for the command.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    // Include room for the new grants in the configuration.
    $config = parent::defaultConfiguration();
    $config['grants'] = [];
    return $config;
  }

  /*--------------------------------------------------------------------
   *
   * Execute.
   *
   * These functions execute the command using the current configuration.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (Settings::getSharingAllowed() === FALSE) {
      // Sharing is currently disabled. This disables use of access controls,
      // and setting of them.
      $this->addExecuteMessage(t(
        'Sharing has been disabled for this site.',
        'error'));
      return;
    }

    // Get the selection, defaulting to the parent if there is none.
    $items = $this->getSelection();
    if (empty($items) === TRUE) {
      $item = $this->getParent();
    }
    else {
      $item = reset($items)[0];
    }

    // Load the root folder.
    $root = $item->getRootFolder();

    // Change the access grants on the root.
    $root->setAccessGrants($this->configuration['grants']);
    $root->save();

    // Flush the render cache because sharing may have changed what
    // content is viewable.
    Cache::invalidateTags(['rendered']);

    // Set the status message.
    $this->addExecuteMessage(t(
      'The sharing settings for the @kind "@name" have been updated.',
      [
        '@kind' => Utilities::mapKindToTerm($item->getKind()),
        '@name' => $item->getName(),
      ]),
      'status');
  }

  /*--------------------------------------------------------------------
   *
   * Configuration form.
   *
   * These functions build and respond to the command's configuration
   * form to collect additional command operands.
   *
   *--------------------------------------------------------------------*/

  /**
   * {@inheritdoc}
   */
  public function hasConfigurationForm() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmitButtonName() {
    if (Settings::getSharingAllowed() === TRUE) {
      return 'Save';
    }

    return 'Done';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $formState) {
    //
    // Build a form to prompt for a new set of users that can or cannot
    // access the folder. Constraints on this command insure that there
    // is exactly one item.
    $items = $this->getSelection();
    if (empty($items) === TRUE) {
      $item = $this->getParent();
    }
    else {
      $item = reset($items)[0];
    }

    //
    // Set page title
    // --------------
    // Set the page's title to use the root folder name being changed.
    $def = $this->getPluginDefinition();
    $root = $item->getRootFolder();

    $form['#title'] = Utilities::createTitle(
      $def['title'],
      [
        FolderShare::ROOT_FOLDER_KIND => [
          $root,
        ],
      ]);

    if ($item->isRootFolder() === TRUE) {
      $description = t(
        'Share all files and folders in this folder tree.');
    }
    else {
      $description = t(
        'Share all files and folders in the folder tree that contains "@childname".',
        [
          '@childname' => $item->getName(),
        ]);
    }

    $form['description'] = [
      '#type'   => 'html_tag',
      '#tag'    => 'p',
      '#value'  => $description,
      '#weight' => (-100),
    ];

    $sharingEnabled = Settings::getSharingAllowed();
    if ($sharingEnabled === FALSE) {
      drupal_set_message(
        t('Sharing has been disabled for this site.'),
        'error');
    }

    //
    // Get user info
    // -------------
    // The returned list has UID keys and values that are arrays with one
    // or more of:
    //
    // 'disabled' = no grants.
    // 'view'     = granted view access.
    // 'author'   = granted author access.
    //
    // The returned array cannot be empty. It always contains at least
    // the owner of the folder, who always has 'view' and 'author' access.
    $grants = $this->getAccessGrants($root);

    // Load all referenced users. We need their display names for the
    // form's list of users. Add the users into an array keyed by
    // the display name, then sort on those keys so that we can create
    // a sorted list in the form.
    $loadedUsers = User::loadMultiple(array_keys($grants));
    $users = [];
    foreach ($loadedUsers as $user) {
      $users[$user->getDisplayName()] = $user;
    }

    ksort($users, SORT_NATURAL);

    // Get the anonymous user. This user always exists and is always UID = 0.
    $anonymousUser = User::load(0);

    // Save the root folder for use when the form is submitted later.
    $this->entity = $root;

    //
    // Start table of users and grants
    // -------------------------------
    // Create a table that has 'User' and 'Access' columns.
    $form[Constants::MODULE . '_share_table'] = [
      '#type'       => 'table',
      '#attributes' => [
        'class'     => [Constants::MODULE . '-share-table'],
      ],
      '#header'     => [
        t('User'),
        t('Access'),
      ],
    ];

    //
    // Define 2nd header
    // -----------------
    // The secondary header breaks the 'Access' column into
    // three child columns (visually) for "None", "View", and "Author".
    // CSS makes these child columns fixed width, and the same width
    // as the radio buttons in the rows below so that they line up.
    $rows    = [];
    $tnone   = t('None');
    $tview   = t('View');
    $tauthor = t('Author');
    $tmarkup = '<span>' . $tnone . '</span><span>' .
      $tview . '</span><span>' . $tauthor . '</span>';
    $rows[]  = [
      'User' => [
        '#type'       => 'item',
        // Do not include markup for user column since we need it to be
        // blank for this row.
        '#markup'     => '',
        '#value'      => -1,
        '#attributes' => [
          'class'     => [Constants::MODULE . '-share-subheader-user'],
        ],
      ],
      'Access'        => [
        '#type'       => 'item',
        '#markup'     => $tmarkup,
        '#attributes' => [
          'class'     => [Constants::MODULE . '-share-subheader-grants'],
        ],
      ],
      '#attributes'   => [
        'class'       => [Constants::MODULE . '-share-subheader'],
      ],
    ];

    //
    // Add anonymous user row
    // ----------------------
    // The 1st row is always for generic anonymous access. Only include
    // the row if sharing with anonymous is allowed by the site.
    $ownerId = $root->getOwnerId();
    $owner   = User::load($ownerId);

    $anonymousEnabled = Settings::getSharingAllowedWithAnonymous();
    if ($anonymousEnabled === TRUE) {
      $rows[] = $this->buildRow(
        $anonymousUser,
        $owner,
        $grants[0],
        $sharingEnabled);
    }

    //
    // Add user rows
    // -------------
    // Go through each of the listed users and add a row.
    foreach ($users as $user) {
      // Skip NULL users. This may occur when a folder included a grant to
      // a user account that has since been deleted.
      if ($user === NULL) {
        continue;
      }

      // Skip the anonymous user. This user has already been handled specially
      // as the first row of the table.
      if ($user->isAnonymous() === TRUE) {
        continue;
      }

      // Optionally skip the site admin. Content administrators are still
      // shown.
      $uid = (int) $user->id();
      if ($uid === 1 && self::SHOW_SITE_ADMIN === FALSE) {
        continue;
      }

      // Optionally skip the owner.
      if ($uid === $ownerId && self::SHOW_OWNER === FALSE) {
        continue;
      }

      // Add the row.
      $rows[] = $this->buildRow(
        $user,
        $owner,
        $grants[$uid],
        $sharingEnabled);
    }

    $form[Constants::MODULE . '_share_table'] = array_merge(
      $form[Constants::MODULE . '_share_table'],
      $rows);

    return $form;
  }

  /**
   * Builds and returns a form row for a user.
   *
   * The form row includes the user column with the user's ID and a link
   * to their profile page.  Beside the user column, the row includes
   * one, two, or three radio buttons for 'none', 'view', and 'author'
   * access grants based upon the grants provided and the user's current
   * module permissions.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user for whom to build the row.
   * @param \Drupal\user\Entity\User $owner
   *   The owner of the current root folder.
   * @param array $grant
   *   The access grants for the user.
   * @param bool $sharingEnabled
   *   When FALSE, the row is always disabled. When TRUE, the row may be
   *   enabled or disabled based upon other conditions.
   *
   * @return array
   *   The form table row description for the user.
   */
  private function buildRow(
    User $user,
    User $owner,
    array $grant = NULL,
    bool $sharingEnabled = FALSE) {

    // Interpret grants
    // ----------------
    // Look at the access grant for this user, if any, and set flags
    // we'll use for the form row below.
    if (empty($grant) === TRUE) {
      // There is no entry for the user. Default to disabled.
      $isDisabled = TRUE;
      $canView    = FALSE;
      $canAuthor  = FALSE;
    }
    else {
      // There is any entry for the user. Note what it says.
      $isDisabled = in_array('disabled', $grant);
      $canView    = in_array('view', $grant);
      $canAuthor  = in_array('author', $grant);

      // Resolve conflicts and incomplete information. If the user
      // is explicitly disabled, then ignore view and author grants.
      if ($isDisabled === TRUE) {
        $canView = FALSE;
        $canAuthor = FALSE;
      }
      elseif ($canAuthor === TRUE) {
        // If the user is explicitly granted author access, then automatically
        // include view access.
        $canView = TRUE;
      }
    }

    // Check permissions
    // -----------------
    // Get characteristics of the user being listed. Is their account blocked?
    // Are they the anonymous user? An admin? The owner of the root folder?
    $isBlocked   = $user->isBlocked();
    $isAnonymous = $user->isAnonymous();
    $isOwner     = ((int) $user->id() === (int) $owner->id());
    $isAdmin     = ((int) $user->id() === 1);

    if ($isAdmin === FALSE) {
      // The user is not THE site admin, but they may have site or module
      // content administrative permissions.
      $entityType = \Drupal::entityTypeManager()
        ->getDefinition(FolderShare::ENTITY_TYPE_ID);
      $perm = $entityType->getAdminPermission();
      if (empty($perm) === TRUE) {
        $perm = Constants::ADMINISTER_PERMISSION;
      }

      $access = AccessResult::allowedIfHasPermission($user, $perm);
      if ($access->isAllowed() === TRUE) {
        $isAdmin = TRUE;
      }
    }

    if ($isBlocked === TRUE && $isAnonymous === FALSE) {
      // The account is blocked, disable the row and show the user
      // as having no permissions. Do not include the anonymous account
      // in this, even though it is always blocked.
      $rowDisabled = TRUE;
      $canView     = FALSE;
      $canAuthor   = FALSE;
      $couldView   = FALSE;
      $couldAuthor = FALSE;
    }
    elseif ($isAnonymous === FALSE &&
        Settings::getSharingAllowedWithAnonymous() === FALSE) {
      // The account is for anonymous and sharing with anonymous is
      // disabled.
      $rowDisabled = TRUE;
      $canView     = FALSE;
      $canAuthor   = FALSE;
      $couldView   = FALSE;
      $couldAuthor = FALSE;
    }
    elseif ($isAdmin === TRUE) {
      // The account is an admin, disable the row and show the user
      // as having all permissions.
      $rowDisabled = TRUE;
      $canView     = TRUE;
      $canAuthor   = TRUE;
      $couldView   = TRUE;
      $couldAuthor = TRUE;
    }
    elseif ($isOwner === TRUE) {
      // The account is the root folder's owner. Disable the row and show
      // the user as having all permission.
      $rowDisabled = TRUE;
      $canView     = TRUE;
      $canAuthor   = TRUE;
      $couldView   = TRUE;
      $couldAuthor = TRUE;
    }
    else {
      // The account is unblocked, not an admin, and not the owner.
      // Check their permissions to see which grant options are available.
      $rowDisabled = ($sharingEnabled === FALSE);

      $couldView   = FolderShareAccessControlHandler::mayAccess('view', $user);
      $couldAuthor = FolderShareAccessControlHandler::mayAccess('update', $user);

      // Reduce their access grants if permissions don't grant them the
      // necessary access.
      if ($couldView === FALSE) {
        // If the user doesn't have view permission, block view AND author.
        // It doesn't make sense to have an author grant, but not be able
        // to view the content.
        $canView   = FALSE;
        $canAuthor = FALSE;
      }

      if ($couldAuthor === FALSE) {
        // If the user doesn't have author permission, block view.
        $canAuthor = FALSE;
      }
    }

    // Build row
    // ---------
    // Start by creating the radio button options array based upon the
    // grants.
    $radios = ['none' => ''];
    $default = 'none';

    if ($couldView === TRUE) {
      // User has view permissions, so allow a 'view' choice.
      $radios['view'] = '';
    }

    if ($couldAuthor === TRUE) {
      // User has author permissions, so allow a 'author' choice.
      $radios['author'] = '';
    }

    if ($canView === TRUE) {
      // User has been granted view access.
      $default = 'view';
    }

    if ($canAuthor === TRUE) {
      // User has been granted author access (which for our purposes
      // includes view access).
      $default = 'author';
    }

    $moduleName = \Drupal::moduleHandler()->getName(Constants::MODULE);

    $message = '';
    if ($isAnonymous === TRUE) {
      // Handle the anonymous user specially since they don't have a
      // meaningful profile page.
      $name = $user->getDisplayName();
      $nameMarkup = t(
        '@name (any web site visitor)',
        [
          '@name' => $name,
        ]);

      if ($couldView === FALSE) {
        $message = t(
          '@name users do not have @module module view permission, so they cannot be granted access.',
          [
            '@name'   => mb_convert_case($name, MB_CASE_TITLE),
            '@module' => $moduleName,
          ]);
        $rowDisabled = TRUE;
      }
      elseif ($couldAuthor === FALSE) {
        $message = t(
          '@name users do not have @module module author permission, so they cannot be granted author access.',
          [
            '@name'   => mb_convert_case($name, MB_CASE_TITLE),
            '@module' => $moduleName,
          ]);
      }
    }
    else {
      // Use the link to the user's account.
      $nameMarkup = $user->toLink()->toString();

      if ($isBlocked === TRUE) {
        $message = t("The site administrator has blocked this user's account, so they cannot be granted access.");
        $rowDisabled = TRUE;
      }
      elseif ($isAdmin === TRUE) {
        $message = t('This user has administrator authority, so they always have access.');
      }
      elseif ($isOwner === TRUE) {
        $message = t('This user is the owner of the top-level folder, so they always have access.');
      }
      elseif ($couldView === FALSE) {
        $message = t(
          'This user does not have @module module view permission, so they cannot be granted access.',
          [
            '@module' => $moduleName,
          ]);
        $rowDisabled = TRUE;
      }
      elseif ($couldAuthor === FALSE) {
        $message = t(
          'This user does not have @module module author permission, so they cannot be granted author access.',
          [
            '@module' => $moduleName,
          ]);
      }
    }

    // Create the row. Provide the user's UID as the row value, which we'll
    // use later during validation and submit handling. Show a link to the
    // user's profile and an optional message.
    return [
      'User' => [
        '#type'          => 'item',
        '#value'         => $user->id(),
        '#markup'        => $nameMarkup,
        '#description'   => $message,
        '#attributes'    => [
          'class'        => [Constants::MODULE . '-share-user'],
        ],
      ],

      // Disable the row if the user has no permissions.
      'Access' => [
        '#type'          => 'radios',
        '#options'       => $radios,
        '#default_value' => $default,
        '#disabled'      => $rowDisabled,
        '#attributes'    => [
          'class'        => [Constants::MODULE . '-share-grants'],
        ],
      ],
      '#attributes'      => [
        'class'          => [Constants::MODULE . '-share-row'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $formState) {

    // Setup
    // -----
    // Get the selection, or default to the parent.
    $items = $this->getSelection();
    if (empty($items) === TRUE) {
      $item = $this->getParent();
    }
    else {
      $item = reset($items)[0];
    }

    // Get the root folder to which grants are attached.
    $root = $item->getRootFolder();

    // Get the root folder's owner.
    $ownerId = $root->getOwnerId();

    // Get the original grants. The returned array is indexed by UID.
    $grants = [];
    $originalGrants = $this->getAccessGrants($root);

    // If we omitted the owner, site admin, or anonymous user from the
    // table, copy their original grants forward.
    if (self::SHOW_SITE_ADMIN === FALSE) {
      if (isset($originalGrants[1]) === TRUE) {
        // The site admin (UID = 1) always has full access, but copy
        // this anyway.
        $grants[1] = $originalGrants[1];
      }
    }

    if (self::SHOW_OWNER === FALSE) {
      if (isset($originalGrants[$ownerId]) === TRUE) {
        // The owner always has full access, but copy this anyway.
        $grants[$ownerId] = $originalGrants[$ownerId];
      }
    }

    if (isset($originalGrants[0]) === TRUE) {
      // Anonymous (UID = 0) may have been omitted from the table because
      // the site has disabled sharing with anonymous. But copy the previos
      // values anyway.
      $grants[0] = $originalGrants[0];
    }

    // Validate
    // --------
    // Validate that the new grants make sense.
    //
    // Loop through the form's table of users and access grants. For each
    // user, see if 'none', 'view', or 'author' radio buttons are set
    // and create the associated grant in a user-grant array.
    $values = $formState->getValue(Constants::MODULE . '_share_table');
    foreach ($values as $item) {
      // Get the user ID for this row.
      $uid = $item['User'];

      // Ignore negative user IDs, which is only used for the 2nd header
      // row that labels the radio buttons in the access column.
      if ($uid < 0) {
        continue;
      }

      // Ignore any row for the root folder owner. If self::SHOW_OWNER is FALSE,
      // the row was omitted anyway.
      if ($uid === $ownerId) {
        continue;
      }

      // Get the access being granted.
      switch ($item['Access']) {
        case 'view':
          $grant = ['view'];
          break;

        case 'author':
          // Author grants ALWAYS include view grants.
          $grant = [
            'view',
            'author',
          ];
          break;

        default:
          $grant = ['disabled'];
          break;
      }

      // Save it.
      $grants[$uid] = $grant;
    }

    $this->configuration['grants'] = $grants;

    try {
      $this->validateParameters();
    }
    catch (\Exception $e) {
      $formState->setErrorByName(
        Constants::MODULE . '_share_table',
        $e->getMessage());
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $formState) {

    // Run the command.
    try {
      $this->execute();

      // Get its messages, if any, and transfer them to the page.
      foreach ($this->getExecuteMessages() as $type => $list) {
        switch ($type) {
          case 'error':
          case 'warning':
          case 'status':
            break;

          default:
            $type = 'status';
            break;
        }

        foreach ($list as $message) {
          drupal_set_message($message, $type);
        }
      }
    }
    catch (\Exception $e) {
      drupal_set_message($e->getMessage(), 'error');
      return;
    }
  }

  /*---------------------------------------------------------------------
   *
   * Utilities.
   *
   * These utility functions help in creation of the configuration form.
   *
   *---------------------------------------------------------------------*/

  /**
   * Returns an array of users and their access grants for a root folder.
   *
   * The returned array has one entry per user. The array key is the
   * user's ID, and the array value is an array with one or more of:
   * - 'disabled'
   * - 'view'
   * - 'author'
   *
   * In normal use, the array for a user contains only one of these
   * possibilities:
   * - ['disabled'] = the user is listed for the folder, but access is disabled.
   * - ['view'] = the user only has view access to the folder.
   * - ['view', 'author'] = the user has view and author access to the folder.
   *
   * While it is technically possible for a user to have 'author', but no
   * 'view' access, this is a strange and largely unusable configuration
   * and one this form does not support.
   *
   * It is technically possible for 'disabled' to be combined with the others
   * values, but this would be meaningless and is not supported by this form.
   *
   * @param \Drupal\foldershare\FolderShareInterface $root
   *   The root folder object queried to get a list of users and their
   *   access grants.
   *
   * @return string[]
   *   The array of access grants for the folder.  Array keys are
   *   user IDs, while array values are arrays that contain values
   *   'disabled', 'view', and/or 'author'.
   *
   * @todo Reduce this function so that it only returns a list of users
   * with explicit view, author, or disabled access to the folder. Do
   * not include all users at the site. However, this can only be done
   * when the form is updated to support add/delete users.
   */
  private function getAccessGrants(FolderShareInterface $root) {
    // Get the folder's access grants.
    $grants = $root->getAccessGrants();

    // For now, add all other site users and default them to 'disabled'.
    foreach (\Drupal::entityQuery('user')->execute() as $uid) {
      if (isset($grants[$uid]) === FALSE) {
        $grants[$uid] = ['disabled'];
      }
    }

    return $grants;
  }

}
