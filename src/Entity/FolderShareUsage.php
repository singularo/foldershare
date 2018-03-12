<?php

namespace Drupal\foldershare\Entity;

use Drupal\Core\Database\Database;

use Drupal\foldershare\Entity\FolderShare;

/**
 * Records per-user usage for the module.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * For each user, this class records:
 * - nRootFolders: the number of root folders.
 * - nFolders: the number of folders, including root folders.
 * - nFiles: the number of files.
 * - nBytes: the total storage of all files.
 *
 * Methods on this class return these values.
 *
 * The underlying database table is created iin MODULE.install when
 * the module is installed.
 *
 * <b>Access control:</b>
 * These functions do not check permissions or access controls.  It is
 * up to the caller to check permissions if needed.
 *
 * <b>Locking:</b>
 * These functions do not lock resources, nor do they need to.
 * The underlying database may handle locks during appropriate operations.
 *
 * @ingroup foldershare
 *
 * @see \Drupal\foldershare\Entity\FolderShare
 */
class FolderShareUsage {

  /*--------------------------------------------------------------------
   *
   * Database tables.
   *
   *-------------------------------------------------------------------*/

  /**
   * The name of the per-user usage tracking database table.
   *
   * This name must match the table defined in MODULE.install.
   */
  const USAGE_TABLE = 'foldershare_usage';

  /*--------------------------------------------------------------------
   *
   * Get totals.
   *
   *-------------------------------------------------------------------*/

  /**
   * Returns the total number of bytes.
   *
   * The returned value only includes storage space used for files.
   * Any storage space required in the database for folder or file
   * metadata is not included.
   *
   * @return int
   *   The total number of bytes.
   *
   * @see FolderShare::getNumberOfBytes()
   */
  public static function getNumberOfBytes() {
    return FolderShare::getNumberOfBytes();
  }

  /**
   * Returns the total number of folders.
   *
   * @return int
   *   The total number of folders.
   *
   * @see FolderShare::getNumberOfFolders()
   */
  public static function getNumberOfFolders() {
    return FolderShare::getNumberOfFolders();
  }

  /**
   * Returns the total number of root folders.
   *
   * @return int
   *   The total number of root folders.
   *
   * @see FolderShare::getNumberOfRootFolders()
   */
  public static function getNumberOfRootFolders() {
    return FolderShare::getNumberOfRootFolders();
  }

  /**
   * Returns the total number of files.
   *
   * @return int
   *   The total number of folders.
   *
   * @see FolderShare::getNumberOfFiles()
   */
  public static function getNumberOfFiles() {
    return FolderShare::getNumberOfFiles();
  }

  /**
   * Returns the total number of users with files.
   *
   * @return int
   *   The total number of users with files.
   */
  public static function getNumberOfUsersWithFiles() {
    $fileIds = FolderShare::getAllFileUserIds();
    $uids = [];
    foreach ($fileIds as $uid) {
      $uids[$uid] = 1;
    }

    return count($uids);
  }

  /**
   * Returns the total number of users with folders.
   *
   * @return int
   *   The total number of users with folders.
   */
  public static function getNumberOfUsersWithFolders() {
    $uids = [];

    $folderIds = FolderShare::getAllFolderUserIds();
    foreach ($folderIds as $uid) {
      $uids[$uid] = 1;
    }

    $folderIds = FolderShare::getAllRootFolderUserIds();
    foreach ($folderIds as $uid) {
      $uids[$uid] = 1;
    }

    return count($uids);
  }

  /**
   * Returns the total number of users with root folders.
   *
   * @return int
   *   The total number of users with root folders.
   */
  public static function getNumberOfUsersWithRootFolders() {
    $uids = [];
    $folderIds = FolderShare::getAllRootFolderUserIds();
    foreach ($folderIds as $uid) {
      $uids[$uid] = 1;
    }

    return count($uids);
  }

  /*--------------------------------------------------------------------
   *
   * Get/Set usage.
   *
   *-------------------------------------------------------------------*/

  /**
   * Clears all usage for all users.
   *
   * The usage for all users are deleted.
   */
  public static function clearAllUsage() {
    $connection = Database::getConnection();
    $connection->delete(self::USAGE_TABLE)
      ->execute();
  }

  /**
   * Clears the usage for a user.
   *
   * The usage for the indicated user are deleted.
   *
   * @param int $uid
   *   The user ID of the user whose usage is cleared.
   */
  public static function clearUsage(int $uid) {
    if ($uid < 0) {
      // Invalid UID.
      return;
    }

    $connection = Database::getConnection();
    $connection->delete(self::USAGE_TABLE)
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Returns the usage of all users.
   *
   * The returned array has one entry for each user in the
   * database. Array keys are user IDs, and array values are associative
   * arrays with keys for specific metrics and values for those
   * metrics. Supported array keys are:
   *
   * - nRootFolders: the number of root folders.
   * - nFolders: the number of folders, including root folders.
   * - nFiles: the number of files.
   * - nBytes: the total storage of all files.
   *
   * All metrics are for the total number of items or bytes owned by
   * the user.
   *
   * The returned values for bytes used is the current storage space use
   * for each user. This value does not include any database storage space
   * required for file and folder metadata.
   *
   * The returned array only contains records for those users that
   * have current usage . Users who have no recorded metrics
   * will not be listed in the returned array.
   *
   * @return array
   *   An array with user ID array keys. Each array value is an
   *   associative array with keys for each of the above usage.
   */
  public static function getAllUsage() {
    // Query the usage table for all entries.
    $connection = Database::getConnection();
    $select = $connection->select(self::USAGE_TABLE, 'u');
    $select->addField('u', 'uid', 'uid');
    $select->addField('u', 'nRootFolders', 'nRootFolders');
    $select->addField('u', 'nFolders', 'nFolders');
    $select->addField('u', 'nFiles', 'nFiles');
    $select->addField('u', 'nBytes', 'nBytes');
    $records = $select->execute()->fetchAll();

    // Build and return an array from the records.  Array keys
    // are user IDs, while values are usage info.
    $usage = [];
    foreach ($records as $record) {
      $usage[$record->uid] = [
        'nRootFolders' => $record->nRootFolders,
        'nFolders'     => $record->nFolders,
        'nFiles'       => $record->nFiles,
        'nBytes'       => $record->nBytes,
      ];
    }

    return $usage;
  }

  /**
   * Returns the usage for a user.
   *
   * The returned associative array has keys for specific metrics,
   * and values for those metrics. Supported array keys are:
   *
   * - nRootFolders: the number of root folders.
   * - nFolders: the number of folders, including root folders.
   * - nFiles: the number of files.
   * - nBytes: the total storage of all files.
   *
   * All metrics are for the total number of items or bytes owned by
   * the user.
   *
   * The returned value for bytes used is the current storage space use
   * for the user. This value does not include any database storage space
   * required for file and folder metadata.
   *
   * If there is no recorded usage information for the user, an
   * array is returned with all metric values zero.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be returned.
   *
   * @return array
   *   An associative array is returned that includes keys for each
   *   of the above usage.
   */
  public static function getUsage(int $uid) {
    if ($uid < 0) {
      // Invalid UID.
      return [
        'nRootFolders' => 0,
        'nFolders'     => 0,
        'nFiles'       => 0,
        'nBytes'       => 0,
      ];
    }

    // Query the usage table for an entry for this user.
    // There could be none, or one, but not multiple entries.
    $connection = Database::getConnection();
    $select = $connection->select(self::USAGE_TABLE, 'u');
    $select->addField('u', 'uid', 'uid');
    $select->addField('u', 'nRootFolders', 'nRootFolders');
    $select->addField('u', 'nFolders', 'nFolders');
    $select->addField('u', 'nFiles', 'nFiles');
    $select->addField('u', 'nBytes', 'nBytes');
    $select->condition('u.uid', $uid, '=');
    $records = $select->execute()->fetchAll();

    // If none, return an empty usage array.
    if (count($records) === 0) {
      return [
        'nRootFolders' => 0,
        'nFolders'     => 0,
        'nFiles'       => 0,
        'nBytes'       => 0,
      ];
    }

    // Otherwise return the usage.
    $record = array_shift($records);
    return [
      'nRootFolders' => $record->nRootFolders,
      'nFolders'     => $record->nFolders,
      'nFiles'       => $record->nFiles,
      'nBytes'       => $record->nBytes,
    ];
  }

  /**
   * Returns the storage used for a user.
   *
   * The returned value is the current storage space use for the user.
   * This value does not include any database storage space required
   * for file and folder metadata.
   *
   * This is a convenience function that just returns the 'nBytes'
   * value from the user's usage.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be returned.
   *
   * @return int
   *   The total storage space used for files owned by the user.
   *
   * @see getUsage
   */
  public static function getUsageBytes(int $uid) {
    $usage = getUsage($uid);
    return $usage['nBytes'];
  }

  /**
   * Sets the usage for a user.
   *
   * The associative array parameter must have keys for specific
   * metrics, and values for those metrics. Supported array keys are:
   *
   * - nRootFolders: the number of root folders.
   * - nFolders: the number of folders, including root folders.
   * - nFiles: the number of files.
   * - nBytes: the total storage of all files.
   *
   * All metrics are for the total number of items or bytes owned by
   * the user.
   *
   * If the usage array is NULL, usage for the user are cleared.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be set.
   * @param array $usage
   *   (optional) An associative array that includes keys for each
   *   of the above usage. The default is a NULL, which clears
   *   all usage for the user.
   */
  public static function setUsage(int $uid, array $usage = NULL) {
    if ($uid < 0) {
      // Invalid UID.
      return;
    }

    // Delete records for this user, if there are any.
    $connection = Database::getConnection();
    $connection->delete(self::USAGE_TABLE)
      ->condition('uid', $uid)
      ->execute();

    if ($usage === NULL) {
      // No new values.
      return;
    }

    // Get the metrics, if present, and insure they are >= 0.
    $nRootFolders = 0;
    if (isset($usage['nRootFolders']) === TRUE &&
        (int) $usage['nRootFolders'] >= 0) {
      $nRootFolders = (int) $usage['nRootFolders'];
    }

    $nFolders = 0;
    if (isset($usage['nFolders']) === TRUE &&
        (int) $usage['nFolders'] >= 0) {
      $nFolders = (int) $usage['nFolders'];
    }

    $nFiles = 0;
    if (isset($usage['nFiles']) === TRUE &&
        (int) $usage['nFiles'] >= 0) {
      $nFiles = (int) $usage['nFiles'];
    }

    $nBytes = 0;
    if (isset($usage['nBytes']) === TRUE &&
        (int) $usage['nBytes'] >= 0) {
      $nBytes = (int) $usage['nBytes'];
    }

    // Set the metrics.
    $connection->insert(self::USAGE_TABLE)
      ->fields([
        'uid'          => $uid,
        'nRootFolders' => $nRootFolders,
        'nFolders'     => $nFolders,
        'nFiles'       => $nFiles,
        'nBytes'       => $nBytes,
      ])
      ->execute();
  }

  /*--------------------------------------------------------------------
   *
   * Update usage.
   *
   *-------------------------------------------------------------------*/

  /**
   * Updates the usage metric for the number of bytes used for a user.
   *
   * The recorded number of bytes for the user is updated
   * by adding the $addSub parameter, which may be positive or negative.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be changed.
   * @param int $addSub
   *   The number to be added or removed from the current usage metric.
   *   This is often (+1) or (-1) to add (on creation) or subtract
   *   (on deletion).
   */
  public static function updateUsageBytes(int $uid, int $addSub) {
    if ($uid < 0) {
      // Invalid UID.
      return;
    }

    $usage = self::getUsage($uid);
    $usage['nBytes'] += $addSub;
    self::setUsage($uid, $usage);
  }

  /**
   * Updates the usage metric for the number of files for a user.
   *
   * The recorded number of files for the user is updated
   * by adding the $addSub parameter, which may be positive or negative.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be changed.
   * @param int $addSub
   *   The number to be added or removed from the current usage metric.
   *   This is often (+1) or (-1) to add (on creation) or subtract
   *   (on deletion).
   */
  public static function updateUsageFiles(int $uid, int $addSub) {
    if ($uid < 0) {
      // Invalid UID.
      return;
    }

    $usage = self::getUsage($uid);
    $usage['nFiles'] += $addSub;
    self::setUsage($uid, $usage);
  }

  /**
   * Updates the usage metric for the number of folders for a user.
   *
   * The recorded number of folders for the user is updated
   * by adding the $addSub parameter, which may be positive or negative.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be changed.
   * @param int $addSub
   *   The number to be added or removed from the current usage metric.
   *   This is often (+1) or (-1) to add (on creation) or subtract
   *   (on deletion).
   */
  public static function updateUsageFolders(int $uid, int $addSub) {
    if ($uid < 0) {
      // Invalid UID.
      return;
    }

    $usage = self::getUsage($uid);
    $usage['nFolders'] += $addSub;
    self::setUsage($uid, $usage);
  }

  /**
   * Updates the usage metric for the number of root folders for a user.
   *
   * The recorded number of root folders for the user is updated
   * by adding the $addSub parameter, which may be positive or negative.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be changed.
   * @param int $addSub
   *   The number to be added or removed from the current usage metric.
   *   This is often (+1) or (-1) to add (on creation) or subtract
   *   (on deletion).
   */
  public static function updateUsageRootFolders(int $uid, int $addSub) {
    if ($uid < 0) {
      // Invalid UID.
      return;
    }

    $usage = self::getUsage($uid);
    $usage['nRootFolders'] += $addSub;
    self::setUsage($uid, $usage);
  }

  /**
   * Updates all of the usage for a user.
   *
   * The $addSub parameter must be an associative array with array
   * keys for specific metrics, and values to add (or subtract) to
   * update those values. Supported array keys are:
   *
   * - nRootFolders: the number of root folders.
   * - nFolders: the number of folders, including root folders.
   * - nFiles: the number of files.
   * - nBytes: the total storage of all files.
   *
   * The database storage space required for folders and files should
   * not be included in the number of bytes in use. The byte usage is
   * strictly the total size of all files owned by the user.
   *
   * @param int $uid
   *   The user ID of the user whose usage is to be changed.
   * @param array $addSub
   *   The array of metrics to be added or removed from the current
   *   usage. Array keys are for specific metrics, and
   *   values are for changes to those metrics.
   */
  public static function updateUsage(int $uid, array $addSub) {
    if ($uid < 0) {
      // Invalid UID.
      return;
    }

    if (empty($addSub) === TRUE) {
      // Nothing to do.
      return;
    }

    $usage = self::getUsage($uid);
    $changed = FALSE;

    if (isset($addSub['nRootFolders']) === TRUE) {
      $usage['nRootFolders'] += (int) $addSub['nRootFolders'];
      $changed = TRUE;
    }

    if (isset($addSub['nFolders']) === TRUE) {
      $usage['nFolders'] += (int) $addSub['nFolders'];
      $changed = TRUE;
    }

    if (isset($addSub['nFiles']) === TRUE) {
      $usage['nFiles'] += (int) $addSub['nFiles'];
      $changed = TRUE;
    }

    if (isset($addSub['nBytes']) === TRUE) {
      $usage['nBytes'] += (int) $addSub['nBytes'];
      $changed = TRUE;
    }

    if ($changed === TRUE) {
      self::setUsage($uid, $usage);
    }
  }

  /**
   * Updates usage for all users.
   *
   * All current usage are deleted and a new set assembled
   * and saved for all users that own files or folders. This can be
   * a lengthy process as it requires getting lists of all files
   * and folders, then looping through them to create metrics for
   * their owners.
   */
  public static function updateUsageAllUsers() {
    $usage = [];

    //
    // Count root folders
    // ------------------
    // Get all root folders and their user IDs. Count the number of
    // root folders owned by each unique user ID.
    $userIds = FolderShare::getAllRootFolderUserIds();

    foreach ($userIds as $uid) {
      // If the root folder's UID has not been encountered yet,
      // create an empty initial record.
      if (isset($usage[$uid]) === FALSE) {
        $usage[$uid] = [
          'nRootFolders' => 0,
          'nFolders'     => 0,
          'nFiles'       => 0,
          'nBytes'       => 0,
        ];
      }

      $usage[$uid]['nRootFolders']++;
    }

    //
    // Count folders
    // -------------
    // Get all folders and their user IDs. Count the number of
    // folders owned by each unique user ID.
    $userIds = array_merge(
      FolderShare::getAllFolderUserIds(),
      FolderShare::getAllRootFolderUserIds());

    foreach ($userIds as $uid) {
      // If the folder's UID has not been encountered yet,
      // create an empty initial record.
      if (isset($usage[$uid]) === FALSE) {
        $usage[$uid] = [
          'nRootFolders' => 0,
          'nFolders'     => 0,
          'nFiles'       => 0,
          'nBytes'       => 0,
        ];
      }

      $usage[$uid]['nFolders']++;
    }

    //
    // Count files and sizes
    // ---------------------
    // Get all files and their user IDs. Count the number of
    // files owned by each unique user ID.
    $userIdsAndSizes = FolderShare::getAllFileUserIdsAndSizes();

    foreach ($userIdsAndSizes as $ary) {
      $uid = $ary[0];
      $size = $ary[1];

      // If the file's UID has not been encountered yet,
      // create an empty initial record.
      if (isset($usage[$uid]) === FALSE) {
        $usage[$uid] = [
          'nRootFolders' => 0,
          'nFolders'     => 0,
          'nFiles'       => 0,
          'nBytes'       => 0,
        ];
      }

      $usage[$uid]['nFiles']++;
      $usage[$uid]['bytes'] += $size;
    }

    //
    // Delete current usage
    // --------------------
    // Delete all records.
    $connection = Database::getConnection();
    $connection->delete(self::USAGE_TABLE)
      ->execute();

    //
    // Update current usage
    // --------------------
    // Add all of the above usage info.
    $query = $connection->insert(self::USAGE_TABLE);

    foreach ($usage as $uid => $u) {
      $query->value([
        'uid'          => $uid,
        'nRootFolders' => $u['nRootFolders'],
        'nFolders'     => $u['nFolders'],
        'nFiles'       => $u['nFiles'],
        'nBytes'       => $u['nBytes'],
      ]);
    }

    $query->execute();
  }

}
