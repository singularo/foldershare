<?php

namespace Drupal\foldershare\Controller;

use Drupal\user\Entity\User;

use Drupal\foldershare\Constants;
use Drupal\foldershare\Utilities;
use Drupal\foldershare\Entity\FolderShareUsage;

/**
 * Generates an administrator page reporting module usage.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This class creates and administrator page that reports the current
 * per-user and total usage. This includes the number of root folders,
 * subfolders, and files, and the storage space used by files.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShareUsage
 */
class AdminUsagePage {

  /*---------------------------------------------------------------------
   *
   * Create page.
   *
   * These functions build the page.
   *
   *---------------------------------------------------------------------*/

  /**
   * Builds and returns a renderable array reporting current module usage.
   *
   * @return array
   *   A Drupal render array.
   */
  public static function page() {
    //
    // Setup
    // -----
    // Create class names.
    $pageClass          = Constants::MODULE . '-admin-usage';
    $tableClass         = Constants::MODULE . '-admin-usage-table';
    $userColumnClass    = Constants::MODULE . '-admin-usage-table-user';
    $rootsColumnClass   = Constants::MODULE . '-admin-usage-table-roots';
    $foldersColumnClass = Constants::MODULE . '-admin-usage-table-folders';
    $filesColumnClass   = Constants::MODULE . '-admin-usage-table-files';
    $bytesColumnClass   = Constants::MODULE . '-admin-usage-table-bytes';
    $totalsClass        = Constants::MODULE . '-admin-usage-table-total';

    $tableName = Constants::MODULE . '-admin-usage-table';

    // Start the page with the CSS class and library.
    $page = [
      '#attached'   => [
        'library'   => [
          Constants::LIBRARY_MODULE,
          Constants::LIBRARY_ADMIN,
        ],
      ],
    ];

    $page['page'] = [
      '#type'       => 'container',
      '#tree'       => TRUE,
      '#attributes' => [
        'class'     => [$pageClass],
      ],
    ];

    //
    // Get usage data
    // --------------
    // Get totals.
    $nRoots   = FolderShareUsage::getNumberOfRootFolders();
    $nFolders = FolderShareUsage::getNumberOfFolders();
    $nFiles   = FolderShareUsage::getNumberOfFiles();
    $nBytes   = FolderShareUsage::getNumberOfBytes();

    // Get per-user values. The array's keys are UIDs.
    $userUsage = FolderShareUsage::getAllUsage();

    // The above returned usage array contains entries ONLY for users that
    // are using the module. Any user that has never created a file or
    // folder will have no usage entry.
    //
    // For the table on this page, we'd like to include ALL users. So,
    // for any user not in the above usage array, add an empty entry.
    //
    // Get a list of all users on the site.
    $allUids = \Drupal::entityQuery('user')->execute();

    foreach ($allUids as $uid) {
      if (isset($userUsage[$uid]) === FALSE) {
        // This user has never used the module. Add an empty entry.
        $userUsage[$uid] = [
          'nRootFolders' => 0,
          'nFolders'     => 0,
          'nFiles'       => 0,
          'nBytes'       => 0,
        ];
      }
    }

    unset($allUids);

    // Load all the users and sort by their display names.
    $loadedUsers = User::loadMultiple(array_keys($userUsage));
    $users = [];
    foreach ($loadedUsers as $user) {
      $users[$user->getDisplayName()] = $user;
    }

    unset($loadedUsers);

    ksort($users, SORT_NATURAL);

    //
    // Create table
    // ------------
    // The table's headers are:
    // - User name.
    // - Number of root folders.
    // - Number of folders.
    // - Number of files.
    // - Number of bytes.
    $page['page']['usage_table'] = [
      '#type'         => 'table',
      '#name'         => $tableName,
      '#responsive'   => FALSE,
      '#sticky'       => TRUE,
      '#caption'      => t('Current usage by user'),
      '#attributes'   => [
        'class'       => [$tableClass],
      ],
      '#header'       => [
        'user'        => [
          'field'     => 'user',
          'data'      => t('Users'),
          'specifier' => 'user',
          'class'     => [$userColumnClass],
          'sort'      => 'ASC',
        ],
        'nroots'      => [
          'field'     => 'nroots',
          'data'      => t('Top-level folders'),
          'specifier' => 'nroots',
          'class'     => [$rootsColumnClass],
        ],
        'nfolders'    => [
          'field'     => 'nfolders',
          'data'      => t('Folders'),
          'specifier' => 'nfolders',
          'class'     => [$foldersColumnClass],
        ],
        'nfiles'      => [
          'field'     => 'nfiles',
          'data'      => t('Files'),
          'specifier' => 'nfiles',
          'class'     => [$filesColumnClass],
        ],
        'nbytes'        => [
          'field'     => 'nbytes',
          'data'      => t('Bytes'),
          'specifier' => 'nbytes',
          'class'     => [$bytesColumnClass],
        ],
      ],
    ];

    //
    // Create rows
    // -----------
    // One row for each user, followed by a totals row.
    $rows = [];
    foreach ($users as $user) {
      $usage = $userUsage[$user->id()];

      // The user column has the user's display name and link to the user.
      $userColumn = [
        'data'      => [
          '#type'   => 'item',
          '#markup' => $user->toLink()->toString(),
        ],
        'class'     => [$userColumnClass],
      ];

      // The roots column has the number of root folders used by the user.
      $rootsColumn = [
        'data'      => [
          '#type'   => 'item',
          '#markup' => $usage['nRootFolders'],
        ],
        'class'     => [$rootsColumnClass],
      ];

      // The folders column has the number of folders used by the user.
      $foldersColumn = [
        'data'      => [
          '#type'   => 'item',
          '#markup' => $usage['nFolders'],
        ],
        'class'     => [$foldersColumnClass],
      ];

      // The files column has the number of files used by the user.
      $filesColumn = [
        'data'      => [
          '#type'   => 'item',
          '#markup' => $usage['nFiles'],
        ],
        'class'     => [$filesColumnClass],
      ];

      // The bytes column has the number of bytes used by the user.
      $bytesColumn = [
        'data'      => [
          '#type'   => 'item',
          '#markup' => format_size($usage['nBytes']),
        ],
        'class'     => [$bytesColumnClass],
      ];

      $rows[] = [
        'data' => [
          'user'     => $userColumn,
          'nroots'   => $rootsColumn,
          'nfolders' => $foldersColumn,
          'nfiles'   => $filesColumn,
          'nbytes'   => $bytesColumn,
        ],
      ];
    }

    // Add row for totals.
    //
    // The user column says "total" for the totals row.
    $userColumn = [
      'data'      => [
        '#type'   => 'item',
        '#markup' => t('Total'),
      ],
      'class'     => [$userColumnClass],
    ];

    // The roots column has the total number of root folders.
    $rootsColumn = [
      'data'      => [
        '#type'   => 'item',
        '#markup' => $nRoots,
      ],
      'class'     => [$rootsColumnClass],
    ];

    // The folders column has the total number of folders.
    $foldersColumn = [
      'data'      => [
        '#type'   => 'item',
        '#markup' => $nFolders,
      ],
      'class'     => [$foldersColumnClass],
    ];

    // The files column has the total number of files.
    $filesColumn = [
      'data'      => [
        '#type'   => 'item',
        '#markup' => $nFiles,
      ],
      'class'     => [$filesColumnClass],
    ];

    // The bytes column has the total number of bytes.
    $bytesColumn = [
      'data'      => [
        '#type'   => 'item',
        '#markup' => format_size($nBytes),
      ],
      'class'     => [$bytesColumnClass],
    ];

    $rows[] = [
      'data' => [
        'user'     => $userColumn,
        'nroots'   => $rootsColumn,
        'nfolders' => $foldersColumn,
        'nfiles'   => $filesColumn,
        'nbytes'   => $bytesColumn,
      ],
      'class'      => [$totalsClass],
    ];

    // Add the rows to the table.
    $page['page']['usage_table']['#rows'] = $rows;

    // Add standard logos and text.
    $page['page']['footer'] = Utilities::createModuleFooter();

    return $page;
  }

}
