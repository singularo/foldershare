<?php

namespace FolderShare;

/**
 * Provides static functions that assist in specialized content formatting.
 *
 * Intended as a companion class to FolderShareConnect, this class provides
 * optional formatting utility functions to present returned content in
 * special ways.
 */
class FolderShareFormat {

  /*--------------------------------------------------------------------
   *
   * Key-value formatting.
   *
   *--------------------------------------------------------------------*/

  /**
   * Formats a nested key-value array as text.
   *
   * The keys and values of the array are returned as multi-line text in
   * two columns holding the key and value. Rows are optionally indented.
   *
   * Nested arrays are handled by recursing.
   *
   * @param array $response
   *   The key-value response to convert to text.
   * @param string $indent
   *   (optional, default = '  ') The indent for each key-value line in
   *   the result.
   *
   * @return string
   *   The string representation.
   */
  public static function formatAsText(
    array $response,
    string $indent = '') {

    //
    // Validate
    // --------
    // It is not an error to try and format an empty response array.
    // It could simply mean that there is no response.
    if (empty($response) === TRUE) {
      return '';
    }

    //
    // Prepare
    // -------
    // Make a first pass through all keys to find the longest key.
    // This will become the column width below.
    $maxLength = 0;
    foreach (array_keys($response) as $key) {
      $length = mb_strlen($key);
      if ($length > $maxLength) {
        $maxLength = $length;
      }
    }

    // Add one for a ':'.
    ++$maxLength;

    //
    // Generate output
    // ---------------
    // Make a second pass to format everything. Use the maximum key length
    // to set a uniform field size for outputing key names.
    $format = "$indent%-$maxLength" . "s %s\n";
    $text = '';
    foreach ($response as $key => $value) {
      // If the value is a scalar, convert it to a string and output.
      if (is_scalar($value) === TRUE) {
        $text .= sprintf($format, $key . ':', $value);
        continue;
      }

      if (empty($value) === TRUE) {
        $text .= sprintf($format, $key . ':', '');
        continue;
      }

      // Otherwise the value must be an array. Sweep through the array to
      // see if all of its values are scalars and its keys are numeric.
      // This is the case for simple lists.
      $simpleList = TRUE;
      foreach ($value as $k => $v) {
        if (is_int($k) === FALSE || is_scalar($v) === FALSE) {
          $simpleList = FALSE;
          break;
        }
      }

      // If the array is a simple list, format the array as a comma-separated
      // list.
      if ($simpleList === TRUE) {
        $text .= sprintf($format, $key . ':', implode(', ', $value));
        continue;
      }

      // Otherwise recurse.
      $indent2 = $indent . '  ';
      $text .= sprintf($format, $key . ':', '');
      $text .= self::formatAsText($value, $indent2);
    }

    return $text;
  }

  /*--------------------------------------------------------------------
   *
   * Linux-style formatting.
   *
   *--------------------------------------------------------------------*/

  /**
   * Formats a single entity similar to the output of a Linux 'stat' command.
   *
   * The Linux 'stat' command prints information about a file, directory,
   * or device. It has two primary formats:
   *
   * - (default) A full format shows multiple lines of text with key-value
   *   pairs giving the file name, size, permissions, etc.
   *
   * - A terse format shows a single line of text that condenses the most
   *   important values, including the file name, size, permissions, etc.
   *
   * This method takes 'stat' key-value data from FolderShare and formats
   * it in a full-like or terse-like output that includes essential values
   * for a FolderShare entity. It does not support format control arguments.
   *
   * Linux, BSD, macOS, and others all have 'stat' commands, but their
   * formats differ. This method outputs in a format similar to Linux.
   *
   * The Linux 'stat' output is not particularly user-friendly. Linux users
   * are more likely to be familiar with 'ls' output, which provides similar
   * information.
   *
   * <B>Full mode (default)</B><BR>
   * The full multi-line output of Linux 'stat' includes:
   * - File name.
   * - Size.
   * - Number of blocks.
   * - IO block size.
   * - File type (e.g. "regular file" vs. "device").
   * - Device.
   * - Inode number.
   * - Number of hard links.
   * - Permissions mode as hex and text.
   * - User as ID and account name.
   * - Group as ID and group name.
   * - Time of last access as text.
   * - Time of last modification as text.
   * - Time of last status change as text.
   *
   * Some of these values have FolderShare equivalents, while others do not.
   * This method drops these values:
   * - Number of blocks.
   * - IO block size.
   * - Number of hard links.
   * - Group as ID and group name.
   * - Time of last access as text.
   *
   * And replaces these values:
   * - File type replaced with MIME type.
   * - Device replaced with host name.
   * - Inode number replaced with entity ID.
   * - Time of last status change replaced with creation date.
   *
   * This method also adds a few fields:
   * - Full path.
   * - Parent entity ID.
   * - Root entity ID.
   *
   * This method also slightly adjusts the Linux 'stat' names to be more
   * meaningful here:
   * - "Name" is used instead of the misleading "File", which Linux uses
   *   even for things that are not files.
   * - "Mime type" is used instead of the Linux "FileType".
   *
   * The output of this method looks like:
   * @code
   *   Path: "PATH"
   *   Name: "FILENAME"
   *   Size: SIZE         Mime type: TYPE
   *   Host: HOSTNAME     ID: ID  ParentID: ID  RootID: ID
   * Access: SHARING      Uid: (UID/ACCOUNT)
   * Modify: DATE
   * Create: DATE
   * @endcode
   *
   * <B>Terse mode</B><BR>
   * If the $terseMode argument is TRUE, a terse mode is returned instead
   * of the full version above.
   *
   * The terse output of Linux 'stat' is an alias for the format string:
   * - %n %s %b %f %u %g %D %i %h %t %T %X %Y %Z %W %o
   * where:
   * - %n = file name.
   * - %s = size.
   * - %b = number of blocks.
   * - %f = raw permission mode in hex.
   * - %u = user ID.
   * - %g = group ID.
   * - %D = device number in hex.
   * - %i = inode number.
   * - %h = number of hard links.
   * - %t = major device type in hex.
   * - %T = minor device type in hex.
   * - %X = time of last access as a timestamp.
   * - %Y = time of last modification as a timestamp.
   * - %Z = time of last status change as a timestamp.
   * - %o = optimal I/O transfer size hint.
   *
   * Some of these values have FolderShare equivalents, while others do not.
   * Rather than reduce the line to just the FolderShare values, this method
   * retains the columns of the Linux terse mode, but shows a '-' if the
   * value is not relevant.
   *
   * This method shows the following values:
   *   %n = file name
   *   %s = size
   *   %b = -
   *   %f = sharing status
   *   %u = user ID
   *   %g = -
   *   %D = host name
   *   %i = entity ID
   *   %h = -
   *   %t = -
   *   %T = -
   *   %X = -
   *   %Y = time of last modification as a timestamp
   *   %Z = time of creation as a timestamp
   *   %o = -
   *
   * @param array $response
   *   The key-value array response when querying a file or folder.
   * @param bool $terseMode
   *   (optional, default = FALSE) When TRUE, a terse one-line form of
   *   the information is created. Otherwise a multi-line form is created.
   *
   * @return string
   *   Returns a text representation of the response.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the $response array is not for an entity or
   *   list of entities.
   */
  public static function formatAsLinuxStat(
    array $response,
    bool $terseMode = FALSE) {
    //
    // Validate
    // --------
    // Make sure the given server response is key-value data for
    // a single entity or a list of entities.
    if (empty($response) === TRUE) {
      throw new \InvalidArgumentException(
        "Programmer error: Empty response array when formatting as 'stat'.");
    }

    if (isset($response['path']) === FALSE) {
      // The array is not for a single entity. Is it for a list of entities?
      foreach ($response as $r) {
        if (isset($r['path']) === FALSE) {
          throw new \InvalidArgumentException(
            "Programmer error: Response array is not in key-value form when formatting as 'stat'.");
        }
      }

      // Recurse to build the output for a list of entities.
      $text = '';
      foreach ($response as $r) {
        $text .= self::formatAsLinuxStat($r, $terseMode);
      }

      return $text;
    }

    //
    // Generate output
    // ---------------
    // Extract and format relevant values.
    //
    // The 'size' may not be present if a entity does not yet have a size
    // computed.
    //
    // The 'id' may not be present if the entity is a temporary false entity
    // for "/" or some other virtual entity.
    //
    // All other values should be present.
    //
    // Entity fields.
    $size     = (isset($response['size']) === TRUE) ?
      (string) $response['size'] : '-';
    $fileType = (isset($response['mime']) === TRUE) ?
      $response['mime'] : '-';
    $id       = (isset($response['id']) === TRUE) ?
      (string) $response['id'] : '-';
    $parentId = (isset($response['parentid']) === TRUE) ?
      (string) $response['parentid'] : '-';
    $rootId   = (isset($response['rootid']) === TRUE) ?
      (string) $response['rootid'] : '-';
    $modified = (isset($response['changed']) === TRUE) ?
      $response['changed'] : '-';
    $created  = (isset($response['created']) === TRUE) ?
      $response['created'] : '-';

    // Virtual fields.
    $path         = $response['path'];
    $modifiedDate = $response['changed-date'];
    $createdDate  = $response['created-date'];
    $permission   = $response['sharing-status'];
    $uid          = $response['user-id'];
    $accountName  = $response['user-account-name'];

    // Site info.
    $host = $response['host'];

    // Get the file name from the path. We cannot use PHP's 'basename'
    // since it uses the current OS's conventions ("locale") rather than
    // our generic conventions. We also need to be multi-byte safe.
    if ($path === '/') {
      $filename = $path;
    }
    else {
      $lastSlashPosition = mb_strrpos($path, '/');
      if ($lastSlashPosition === FALSE) {
        $filename = $path;
      }

      $filename = mb_substr($path, ($lastSlashPosition + 1));
    }

    // Format the output.
    $text = '';
    if ($terseMode === TRUE) {
      $text .= sprintf(
        "%s %s - %s %d - - %d - - - - %d %d -\n",
        $filename,
        $size,
        $permission,
        $uid,
        $id,
        $modified,
        $created);
    }
    else {
      $text .= sprintf(
        "%6s: \"%s\"\n",
        'Path',
        $path);
      $text .= sprintf(
        "%6s: \"%s\"\n",
        'Name',
        $filename);
      $text .= sprintf(
        "%6s: %-25s %s:     %s\n",
        'Size',
        $size,
        'Mime type',
        $fileType);
      $text .= sprintf(
        "%6s: %-25s %s: %-10s %s: %-10s %s: %-10s\n",
        'Host',
        $host,
        'ID',
        $id,
        'ParentID',
        $parentId,
        'RootID',
        $rootId);
      $text .= sprintf(
        "%6s: %-25s %s: (%d/%s)\n",
        'Mode',
        $permission,
        'Uid',
        $uid,
        $accountName);
      $text .= sprintf(
        "%6s: %s\n",
        'Modify',
        $modifiedDate);
      $text .= sprintf(
        "%6s: %s\n",
        'Create',
        $createdDate);
    }

    return $text;
  }

  /**
   * Formats an entity list similar to the output of a Linux 'ls' command.
   *
   * The Linux 'ls' command prints a list of files and folders. In its
   * default mode, file and folder names are shown in multiple columns
   * sized to fit on the screen. Several common options modify this output
   * to list one file per line and include more information. Some of the
   * most useful options are:
   * - '-l' = show a long form that adds the file's permissions, number of
   *   hard links, owner name, group name, size (in blocks), modified date,
   *   and file name.
   *
   * - '-s' = add the file's size in bytes.
   *
   * - '-i' = add the Inode number.
   *
   * - '-o' = omit the group name with paired with '-l'..
   *
   * There are many more options to Linux 'ls', but they are more obscure
   * and not relevant here.
   *
   * For this method, several options are supported to create a few common
   * output modes:
   *
   * - Short mode. File and folder names are output in mulitple columns
   *   sized to fit within an 80-character wide window. Output is sorted
   *   by the file name. If '-s' or '-i' options are given, the size and
   *   entity ID are included before each name.
   *
   * - Long mode. File and folder names are output with one per line,
   *   including attributes in a Linux style. Lines are sorted by the
   *   file name. The '-s' option is ignored because sizes are always
   *   shown in bytes. If '-i' is given, the entity ID is included at
   *   the start of each line. The group name is always skipped, as if
   *   '-o' were given. The number of hard links is always skipped
   *   since there is no such thing in FolderShare.
   *
   * The following values are available to show in short or long modes:
   * - Entity ID in place of Inode number.
   * - Sharing status in place of of Linux permissions.
   * - User account name.
   * - Size (always in bytes).
   * - Modification date.
   * - Name.
   *
   * The following formatting flags are supported:
   * | Flag   | Meaning                                          |
   * | ------ | ------------------------------------------------ |
   * | -F     | Add special symbols on files and folders.        |
   * | -i     | Include the inode number.                        |
   * | -l     | Show a long listing format.                      |
   * | -s     | Include the size of each item.                   |
   * | -S     | Sort by size.                                    |
   * | -t     | Sort by modification time.                       |
   *
   * @param array $response
   *   The key-value array response when querying a file or folder.
   * @param array $flags
   *   (optional, default = []) Linux 'ls' style command-line flags.
   *
   * @return string
   *   Returns a text representation of the response.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the $response array is not for an entity or
   *   list of entities.
   */
  public static function formatAsLinuxLs(array $response, array $flags = []) {
    //
    // Validate
    // --------
    // Make sure the given server response is key-value data for
    // a single entity or a list of entities.
    if (empty($response) === TRUE) {
      // It is not an error to try and format an empty response array.
      // It could simply mean that a folder listing found nothing to list.
      return '';
    }

    if (isset($response['path']) === TRUE) {
      // Response is for a single entity. Make it an array of one entry
      // and continue.
      $response = [$response];
    }
    else {
      // The array is not for a single entity. Is it for a list of entities?
      foreach ($response as $r) {
        if (isset($r['path']) === FALSE) {
          throw new \InvalidArgumentException(
            "Programmer error: Response array is not in key-value form when formatting for 'ls'.");
        }
      }
    }

    //
    // Check options
    // -------------
    // Look for options we support.
    $longMode = FALSE;
    $showSize = FALSE;
    $showId = FALSE;
    $showSymbol = FALSE;
    $sortBy = 'name';

    foreach ($flags as $flag) {
      switch ($flag) {
        case '-F':
          $showSymbol = TRUE;
          break;

        case '-i':
          $showId = TRUE;
          break;

        case '-l':
          $longMode = TRUE;
          break;

        case '-s':
          $showSize = TRUE;
          break;

        case '-S':
          $sortBy = 'size';
          break;

        case '-t':
          $sortBy = 'changed';
          break;
      }
    }

    //
    // Sort
    // ----
    // For all modes, sort the response list by the file name. The sort is
    // case sensitive, so upper case names bubble to the top of the list.
    switch ($sortBy) {
      default:
      case 'name':
        usort(
          $response,
          function ($a, $b) {
            $aname = basename($a['path']);
            $bname = basename($b['path']);
            return ($aname < $bname) ? (-1) : 1;
          });
        break;

      case 'size':
        usort(
          $response,
          function ($a, $b) {
            if (isset($a['size']) === TRUE) {
              $asize = (float) $a['size'];
            }
            else {
              $asize = INF;
            }

            if (isset($b['size']) === TRUE) {
              $bsize = (float) $b['size'];
            }
            else {
              $bsize = INF;
            }

            return ($asize < $bsize) ? (-1) : 1;
          });
        break;

      case 'changed':
        usort(
          $response,
          function ($a, $b) {
            $atime = $a['changed'];
            $btime = $b['changed'];
            return ($atime < $btime) ? (-1) : 1;
          });
        break;
    }

    //
    // Generate output
    // ---------------
    // Extract and format relevant values.
    //
    // The 'size' may not be present if a folder does not yet have a size
    // computed. All other values are always present.
    if ($longMode === FALSE) {
      // Loop through the response and find the longest file name.
      $filenames = [];
      $maxLength = 0;
      foreach ($response as $r) {
        // Get the item name.
        $name = basename($r['path']);

        // Get a symbol suffix based on the kind, if any.
        if ($showSymbol === TRUE) {
          $kind = $r['kind'];
          if ($kind === 'folder' || $kind === 'rootfolder') {
            $name .= '/';
          }
        }

        // Save the name for the list below.
        $filenames[] = $name;

        // Keep track of the longest name.
        $length = mb_strlen($name);
        if ($length > $maxLength) {
          $maxLength = $length;
        }
      }

      // If the size and entity ID are included in the output, increase
      // the maximum length to account for the space they require.
      if ($showSize === TRUE) {
        $maxLength += (8 + 1);
      }

      if ($showId === TRUE) {
        $maxLength += (8 + 1);
      }

      // And add one for a space between columns.
      ++$maxLength;

      // Compute the number of columns of names in the output.
      // Allow a maximum of 80 characters to a line.
      //
      // (80 is an historical line length that dates back to Hollerith punch
      // card widths and FORTRAN, but it remains a common magic number of
      // terminal window widths).
      $n = count($response);
      $nColumns = ceil(80 / $maxLength);
      $nRows = (int) ($n / $nColumns);
      if (($nRows * $nColumns) !== $n) {
        ++$nRows;
      }

      // Generate output.
      $text = '';
      for ($i = 0; $i < $nRows; ++$i) {
        $rowText = '';
        for ($j = 0; $j < $nColumns; ++$j) {
          // Compute the index of the next response.
          $index = (($i * $nColumns) + $j);
          if ($index >= $n) {
            continue;
          }

          // Get values.
          $filename = $filenames[$index];
          $size = isset($response[$index]['size']) === TRUE ?
            (string) $response[$index]['size'] : '-';
          $id = $response[$index]['id'];

          // Format the item.
          $itemText = '';
          if ($showId === TRUE) {
            $itemText .= sprintf("%8d ", $id);
          }

          if ($showSize === TRUE) {
            $itemText .= sprintf("%8s ", $size);
          }

          $itemText .= sprintf("%-" . $maxLength . "s", $filename);
          if ($j === 0) {
            $rowText .= $itemText;
          }
          else {
            $rowText .= ' ' . $itemText;
          }
        }

        $text .= $rowText . "\n";
      }

      return $text;
    }

    // Generate output.
    $text = '';
    foreach ($response as $item) {
      // Get values.
      $filename     = basename($item['path']);
      $size         = isset($item['size']) === TRUE ? (string) $item['size'] : '-';
      $permission   = $item['sharing-status'];
      $accountName  = $item['user-account-name'];
      $id           = $item['id'];
      $modifiedDate = $item['changed-date'];

      // Get a symbol suffix based on the kind, if any.
      if ($showSymbol === TRUE) {
        $kind = $item['kind'];
        if ($kind === 'folder' || $kind === 'rootfolder') {
          $filename .= '/';
        }
      }

      // Format the row.
      $idText = '';
      if ($showId === TRUE) {
        $idText = sprintf("%8d ", $id);
      }

      $text .= sprintf("%s%7s %8s %8s %s %s\n",
        $idText,
        $permission,
        $accountName,
        $size,
        $modifiedDate,
        $filename);
    }

    return $text;
  }

  /*--------------------------------------------------------------------
   *
   * Usage report formatting.
   *
   *--------------------------------------------------------------------*/

  /**
   * Formats a usage report as text.
   *
   * The keys and values of the array are interpreted as a usage report
   * and formatted as a text table of values.
   *
   * @param array $response
   *   The key-value response to convert to text.
   *
   * @return string
   *   The string representation.
   */
  public static function formatAsUsage(array $response) {
    //
    // Validate
    // --------
    // It is not an error to try and format an empty response array.
    // It could simply mean that there is no response.
    if (empty($response) === TRUE) {
      return '';
    }

    // Make sure the response does appear to be usage information.
    if (isset($response['nRootFolders']) === FALSE) {
      throw new \InvalidArgumentException(
        "Programmer error: Response array is not in key-value form when formatting for usage data.");
    }

    //
    // Generate output
    // ---------------
    // Pull out user and host information and output that first.
    // Then map the remaining usage information to friendlier names
    // and output that as a key-value list.
    //
    // Get host and user.
    $host = (isset($response['host']) === TRUE) ?
      $response['host'] : 'Unknown host';

    if (isset($response['user-account-name']) === TRUE) {
      $userName = $response['user-account-name'];
    }
    elseif (isset($response['user-id']) === TRUE) {
      $userName = $response['user-id'];
    }
    else {
      $userName = 'Unknown user';
    }

    $text = "$host usage for $userName:\n";

    // Create an alternate usage array with user-friendly names.
    $alt = [];
    foreach ($response as $k => $r) {
      switch ($k) {
        case 'user-id':
        case 'user-account-name':
        case 'user-display-name':
        case 'host':
          break;

        case 'nRootFolders':
          $alt['Top folders'] = $r;
          break;

        case 'nFolders':
          $alt['Subfolders'] = $r;
          break;

        case 'nFiles':
          $alt['Files'] = $r;
          break;

        case 'nBytes':
          $alt['Bytes'] = $r;
          break;

        default:
          $alt[$k] = $r;
          break;
      }
    }

    $text .= self::formatAsText($alt, '  ');
    return $text;
  }

  /*--------------------------------------------------------------------
   *
   * Configuration report formatting.
   *
   *--------------------------------------------------------------------*/

  /**
   * Formats a configuration report as text.
   *
   * The keys and values of the array are interpreted as a configuration report
   * and formatted as a text table of values.
   *
   * @param array $response
   *   The key-value response to convert to text.
   *
   * @return string
   *   The string representation.
   */
  public static function formatAsConfiguration(array $response) {
    //
    // Validate
    // --------
    // It is not an error to try and format an empty response array.
    // It could simply mean that there is no response.
    if (empty($response) === TRUE) {
      return '';
    }

    //
    // Generate output
    // ---------------
    // Show the host first, then map the remaining information into
    // friendlier names and a better structure. Then output that as a
    // key-value list.
    //
    // Get host and user.
    $host = (isset($response['host']) === TRUE) ?
      $response['host'] : 'Unknown host';

    // Create an alternate configuration array with user-friendly names.
    $alt = [];
    $alt['Web services'] = [];
    $alt['File handling'] = [];
    $alt['Sharing'] = [];

    $showExtensions = TRUE;

    foreach ($response as $k => $r) {
      switch ($k) {
        case 'host':
          // Already handled.
          break;

        case 'GET':
        case 'POST':
        case 'DELETE':
        case 'PATCH':
          $webalt = [];
          foreach ($r as $kk => $rr) {
            switch ($kk) {
              case 'serializer-formats':
                $webalt['Formats'] = $rr;
                break;

              case 'authentication-providers':
                $webalt['Authentication'] = $rr;
                break;

              default:
                $webalt[$kk] = $rr;
            }
          }

          $alt['Web services'][$k] = $webalt;
          break;

        case 'file-restrict-extensions':
          $alt['File handling']['Restrict extensions'] = $r;
          if (is_bool($r) === TRUE && $r === FALSE) {
            $showExtensions = FALSE;
          }
          elseif (is_scalar($r) === TRUE &&
              (((string) $r) === "false") || ((string) $r) === "FALSE") {
            $showExtensions = FALSE;
          }
          break;

        case 'file-allowed-extensions':
          $alt['File handling']['Allowed extensions'] = $r;
          break;

        case 'file-maximum-upload-size':
          $alt['File handling']['Max upload size'] = $r;
          break;

        case 'file-maximum-upload-number':
          $alt['File handling']['Max upload number'] = $r;
          break;

        case 'sharing-allowed':
          $alt['Sharing']['Sharing enabled'] = $r;
          break;

        case 'sharing-allowed-with-anonymous':
          $alt['Sharing']['Public enabled'] = $r;
          break;

        default:
          if (isset($alt['Other']) === FALSE) {
            $alt['Other'] = [];
          }

          $alt['Other'][$k] = $r;
          break;
      }
    }

    if ($showExtensions === FALSE) {
      unset($alt['File handling']['Allowed extensions']);
    }

    $text = "$host configuration:\n";
    $text .= self::formatAsText($alt, '  ');
    return $text;
  }

  /*--------------------------------------------------------------------
   *
   * Version report formatting.
   *
   *--------------------------------------------------------------------*/

  /**
   * Formats a version number report as text.
   *
   * The keys and values of the array are interpreted as a version number
   * report and formatted as a text table of values.
   *
   * @param array $response
   *   The key-value response to convert to text.
   *
   * @return string
   *   The string representation.
   */
  public static function formatAsVersion(array $response) {
    //
    // Validate
    // --------
    // It is not an error to try and format an empty response array.
    // It could simply mean that there is no response. But it is unlikely.
    if (empty($response) === TRUE) {
      return '';
    }

    // Confirm that this does seem to be a version response.
    if (isset($response['client']) === FALSE) {
      throw new \InvalidArgumentException(
        "Programmer error: Response array is not in key-value form when formatting version data.");
    }

    //
    // Generate output
    // ---------------
    // The output has several rows:
    // - The application name and version.
    // - The client API name and version.
    // - The host server name.
    // - A list of server item names and versions.
    //
    // Normally all of these are present in a version request's response.
    // But to be robust we need to check for each one and have a fallback.
    $text = '';
    $indent = '  ';

    //
    // Print versions.
    //
    foreach (['client', 'server'] as $section) {
      if (isset($response[$section]) === FALSE) {
        continue;
      }

      if ($section === 'client') {
        $text .= "Client software\n";
      }
      elseif ($section === 'server') {
        if (isset($response['host']) === FALSE) {
          // If there is no host, then there cannot be server side version
          // numbers. Anything that might be present is bogus. Skip it.
          break;
        }

        $hostname = $response['host'];
        $text .= "\nWeb services at $hostname\n";
      }

      foreach ($response[$section] as $k => $v) {
        $name = $k;
        $version = '';

        if (isset($response[$section][$k]['name']) === TRUE) {
          $name = $response[$section][$k]['name'];
        }

        if (isset($response[$section][$k]['version']) === TRUE) {
          $version = ', version ' . $response[$section][$k]['version'];
        }

        $text .= "$indent$name$version\n";
      }
    }

    return $text;
  }

}
