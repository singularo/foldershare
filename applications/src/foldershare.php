<?php

/**
 * @file
 * Issues command-line requests to a host using the FolderShare module.
 */

require "FolderShareConnect.php";
require "FolderShareFormat.php";

use FolderShare\FolderShareConnect;
use FolderShare\FolderShareFormat;

/**
 * This application name.
 */
const NAME = "FolderShare application";

/**
 * This application version number.
 */
const VERSION = "0.4.0 (January 2018)";

/**
 * Describes commands and their options.
 *
 * The associative array's keys are command names available for use on the
 * command line (e.g. "ls" and "stat"). For each command, an array of values
 * describes the command, including its help text, a list of supported
 * flags, its return formats, synonyms, and finally the name of the function
 * to invoke to execute the command.
 *
 * Command help:
 *
 * - 'synopsis' = a one-line note on the command name and options, similar to
 *   the synopsis line of a traditional Linux/macOS/BSD man page.
 *
 * - 'description' = a multiparagraph description of the command.
 *
 * - 'flags' = an associative array that lists no-argument flags for the
 *   command. For each flag, the array key names the flag, including leading
 *   dashes ("-" or "--" or both). The key's value is an associative array
 *   with keys for:
 *   - 'brief' = a one-line description of the flag.
 *   - 'synonyms' = an array of equivalent flag names. Defaults to empty.
 *
 * - 'synonyms' = an array of alternate names for the command. Defaults to
 *   empty.
 *
 * Command return format keys:
 *
 * - 'formats' = an array of return format names supported by the command.
 *   Known values are: 'full', 'keyvalue', 'text', and 'linux'.
 *
 * - 'defaultFormat' = the default format.
 *
 * Execution keys:
 *
 * - 'function' = the name of the function to invoke for the command.
 */
const COMMANDS = [
  //
  // GET HTTP operations supported.
  //
  //
  // GET version numbers.
  //
  'version' => [
    'synopsis' => 'version [OPTIONS]',
    'brief' => 'Show version numbers.',
    'description' =>
"Show version numbers for client and server software.

If a host name is provided, the host is contacted and its software version
numbers reported along with those for the client software. But if no host
name is provided, only client software version numbers are reported.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
    ],
    'formats' => [
      'full',
      'keyvalue',
      'text',
    ],
    'defaultFormat' => 'text',
    'function' => 'runVersion',
  ],

  //
  // GET server configuration.
  //
  'config' => [
    'synopsis' => 'config [OPTIONS]',
    'brief' => 'Show the host administrator configuration.',
    'description' =>
"Summarize the administrator configuration settings for the host.

Reported configuration values include:
  - A list of HTTP verbs supported by the host.
  - The serialization formats supported for each HTTP verb.
  - The authentication providers supported for each HTTP verb.
  - The maximum file upload size and number.
  - The file name extensions supported, if restrictions are enabled.
  - Whether shared and public content is supported.

This information is primiarily of interest to host administrators and to those
developing client-server software. The reported values cannot be altered
through this client software. Administrators may adjust their host's
configuration using the web interface to the REST and FolderShare modules.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
    ],
    'synonyms' => [
      'configuration',
    ],
    'formats' => [
      'full',
      'keyvalue',
      'text',
    ],
    'defaultFormat' => 'text',
    'function' => 'runConfig',
  ],

  //
  // GET user's usage.
  //
  'usage' => [
    'synopsis' => 'usage [OPTIONS]',
    'brief' => 'Show the user\'s storage use.',
    'description' =>
"Report the number of top-level folders, subfolders, files, and bytes in use
by the user on the host.

The user must have permission to create and modify remote files and folders.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
    ],
    'formats' => [
      'full',
      'keyvalue',
      'text',
    ],
    'defaultFormat' => 'text',
    'function' => 'runUsage',
  ],

  //
  // GET file or folder status.
  //
  'stat' => [
    'synopsis' => 'stat [OPTIONS] REMOTE_PATHS...',
    'brief' => 'Show the status of files and folders.',
    'description' =>
"Show detailed status for remote files or folders.

Status information includes the item's name, remote folder path, owner, size,
MIME type, numeric entity ID, parent entity ID, top-level folder entity ID,
creation date, modified date, and whether the item is private, shared with
others, or shared with the public.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to view the remote files and folders.

This command is similar to the 'stat' command on Linux, BSD, and macOS.",
    'flags' => [
      '-t' => [
        'brief' => 'Show a terse one-line version of the information.',
        'synonyms' => [
          '--terse',
        ],
      ],
      '--help' => [
        'brief' => 'Show this help information.',
      ],
    ],
    'synonyms' => [
      'status',
    ],
    'formats' => [
      'full',
      'keyvalue',
      'linux',
      'text',
    ],
    'defaultFormat' => 'linux',
    'function' => 'runStat',
  ],

  //
  // GET folder contents.
  //
  'ls' => [
    'synopsis' => 'ls [OPTIONS] REMOTE_PATHS...',
    'brief' => 'List files and folders.',
    'description' =>
"List remote files and folders.

The command supports a default short form and a long form with the '-l' option.
Both forms default to sorting items by name:
  - The short form lists item names in multiple columns.
  - The long form lists item names and attributes in a single-column list.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to view the remote files and folders.

This command is similar to the 'ls' command on Linux, BSD, and macOS.",
    'flags' => [
      '-d' => [
        'brief' => 'List folders without recursion.',
      ],
      '-F' => [
        'brief' => 'Mark folders by adding a "/" suffix.',
      ],
      '-i' => [
        'brief' => 'Show the numeric entity ID for each item.',
      ],
      '-l' => [
        'brief' => 'Show a long form that lists more detail.',
      ],
      '-R' => [
        'brief' => 'Recurse to list folder contents.',
      ],
      '-s' => [
        'brief' => 'Show the size, in bytes, for each item.',
      ],
      '-S' => [
        'brief' => 'Sort by size instead of name.',
      ],
      '-t' => [
        'brief' => 'Sort by modification time instead of name.',
      ],
      '--help' => [
        'brief' => 'Show this help information.',
      ],
    ],
    'synonyms' => [
      'list',
      'dir',
    ],
    'formats' => [
      'full',
      'keyvalue',
      'linux',
      'text',
    ],
    'defaultFormat' => 'linux',
    'function' => 'runLs',
  ],

  //
  // DELETE file or folder.
  //
  'rm' => [
    'synopsis' => 'rm [OPTIONS] REMOTE_PATHS...',
    'brief' => 'Remove files or folders.',
    'description' =>
"Remove remote files and and folders.

Use 'rm' without options to remove remote files. Use 'rm -d' to also remove
empty folders. Use 'rm -r' to recursively remove files and non-empty folders
and their contents, recursively.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to remove the remote files and folders.

This command is similar to the 'rm' command on Linux, BSD, and macOS.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-d' => [
        'brief' => 'Delete folders as well as files.',
        'synonyms' => [],
      ],
      '-f' => [
        'brief' => 'Force deletion to continue even after errors.',
        'synonyms' => [],
      ],
      '-r' => [
        'brief' => 'Recursively remove folders and their contents.',
        'synonyms' => [
          '-R',
        ],
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is deleted.',
        'synonyms' => [],
      ],
    ],
    'synonyms' => [
      'remove',
      'del',
      'delete',
    ],
    'formats' => [],
    'defaultFormat' => '',
    'function' => 'runRm',
  ],

  //
  // DELETE folder.
  //
  'rmdir' => [
    'synopsis' => 'rmdir [OPTIONS] REMOTE_PATHS...',
    'brief' => 'Remove empty folders.',
    'description' =>
"Remove remote empty folders.

Use 'rmdir' to remove remote empty folders. It is an error to attempt to
remove a non-empty folder.

To remove files and non-empty folders, use the 'rm -r' command instead.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to remove the remote folders.

This command is similar to the 'rmdir' command on Linux, BSD, and macOS.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is deleted.',
        'synonyms' => [],
      ],
    ],
    'formats' => [],
    'defaultFormat' => '',
    'function' => 'runRmdir',
  ],

  //
  // POST new root folder or subfolder.
  //
  'mkdir' => [
    'synopsis' => 'mkdir [OPTIONS] REMOTE_PATHS...',
    'brief' => 'Make new folders.',
    'description' =>
"Make new remote empty folders.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to create the remote folders.

This command is similar to the 'mkdir' command on Linux, BSD, and macOS.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is created.',
        'synonyms' => [],
      ],
    ],
    'formats' => [
      'full',
      'keyvalue',
    ],
    'defaultFormat' => 'keyvalue',
    'function' => 'runMkdir',
  ],

  //
  // PATCH name and/or location.
  //
  'mv' => [
    'synopsis' => 'mv [OPTIONS] REMOTE_PATHS... REMOTE_DESTINATION_PATH',
    'brief' => 'Move files and folders.',
    'description' =>
"Move remote files or folders.

The command has two forms:
  - With two paths, the command moves the file or folder indicated by the
    first path into the destination selected by the second path.

  - With more than two paths, the command moves the files and folders indicated
    by all of the paths, except the last, into the destination folder selected
    by the last path.

With two paths, if the destination does not exist, but its parent folder does,
the moved item will be moved into the parent and renamed to use the name in
the destination path.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to modify the remote folders.

This command is similar to the 'mv' command on Linux, BSD, and macOS.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-n' => [
        'brief' => 'Do not overwrite an existing file.',
        'synonyms' => [],
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is moved.',
        'synonyms' => [],
      ],
    ],
    'synonyms' => [
      'move',
    ],
    'formats' => [
      'full',
      'keyvalue',
    ],
    'defaultFormat' => 'keyvalue',
    'function' => 'runMv',
  ],

  //
  // PATCH to create a copy.
  //
  'cp' => [
    'synopsis' => 'cp [OPTIONS] REMOTE_PATHS... REMOTE_DESTINATION_PATH',
    'brief' => 'Copy files and folders.',
    'description' =>
"Copy remote files or folders.

The command has two forms:
  - With two paths, the command copies the file or folder indicated by the
    first path into the destination selected by the second path.

  - With more than two paths, the command copies the files and folders
    indicated by all of the paths, except the last, into the destination folder
    selected by the last path.

With two paths, if the destination does not exist, but its parent folder does,
the copied item will be copied into the parent and renamed to use the name in
the destination path.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to create and modify the remote files and
folders.

This command is similar to the 'cp' command on Linux, BSD, and macOS.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-n' => [
        'brief' => 'Do not overwrite an existing file.',
        'synonyms' => [],
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is copied.',
        'synonyms' => [],
      ],
    ],
    'synonyms' => [
      'copy',
    ],
    'formats' => [
      'full',
      'keyvalue',
    ],
    'defaultFormat' => 'keyvalue',
    'function' => 'runCp',
  ],

  //
  // PATCH to update fields.
  //
  'update' => [
    'synopsis' => 'update [OPTIONS] FIELD_NAME FIELD_VALUE REMOTE_PATHS...',
    'brief' => 'Update fields on files and folders.',
    'description' =>
"Update the value for a named field on remote files or folders.

Fields that may be updated include:
  - 'name' = the name of the remote file or folder (see also the 'mv' command).
  - 'description' = the text description of the remote file or folder.

Additional fields may be supported by third-party extensions or customizations
made on specific hosts.

Field values that are more than one word (such as a description's text) or that
include special characters (such as in HTML) should be surrounded by quotes.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to modify the remote files and folders.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is changed.',
        'synonyms' => [],
      ],
    ],
    'formats' => [
      'full',
      'keyvalue',
      'text',
    ],
    'defaultFormat' => 'text',
    'function' => 'runUpdate',
  ],

  //
  // GET to download file.
  //
  'get' => [
    'synopsis' => 'get [OPTIONS] REMOTE_PATH [LOCAL_PATH]',
    'brief' => 'Download files and folders.',
    'description' =>
"Download a remote file or folder indicated to the local host.

The first path indicates a remote file or folder to download, while the second
path selects the local host location in which to save the downloaded item. If
the second path is omitted, the current directory is used and the new local
file has the same name as the remote file.

When downloading a remote folder, that folder and its contents are first
compressed into a ZIP archive and that archive is returned as a file saved onto
the local host. The ZIP archive is not automatically un-zipped.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to view the remote files and folders, and create
the local file.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is downloaded.',
        'synonyms' => [],
      ],
    ],
    'synonyms' => [
      'download',
    ],
    'formats' => [],
    'defaultFormat' => '',
    'function' => 'runGet',
  ],

  //
  // POST to upload file.
  //
  'put' => [
    'synopsis' => 'put [OPTIONS] LOCAL_PATH REMOTE_PATH',
    'brief' => 'Upload files and folders.',
    'description' =>
"Upload a local file to a remote folder.

The first path selects a local file to upload, while the second path indicates
the remote location for the uploaded file.

Paths to remote files and folders must start with '/'. Wild cards are not
supported.

The user must have permission to view the local file and create the remote
files and folders.",
    'flags' => [
      '--help' => [
        'brief' => 'Show this help information.',
      ],
      '-v' => [
        'brief' => 'Show the name of each item as it is uploaded.',
        'synonyms' => [],
      ],
    ],
    'synonyms' => [
      'upload',
    ],
    'formats' => [],
    'defaultFormat' => '',
    'function' => 'runPut',
  ],
];

/*--------------------------------------------------------------------
 *
 * Help and error messages.
 *
 * These functions print error messages and help information.
 *
 *--------------------------------------------------------------------*/

/**
 * Prints help information for command line use.
 *
 * The printed information is written for users that add commands to
 * the Linux/macOS/BSD shell command line. The host, user name, password,
 * and other base options are listed.
 *
 * @param string $appPath
 *   The path to the application.
 */
function printCommandLineHelp(string $appPath) {
  $appName = basename($appPath);
  $helpStart = <<<EOT
Usage is: $appName [HOST_OPTIONS] [COMMAND] [OPTIONS]

Create, delete, change, and manage files and folders on a remote web site
that uses the FolderShare module.

Commands
--------

EOT;

  $helpEnd = <<<EOT

Add "--help" after any command to show its description and flags.

Host and account
----------------
All commands require a remote host and account:

  --host HOSTNAME   Select the host (and optional port) for the site.
  --username NAME   Specify the user login name on the site.
  --password PASS   Specify the user password on the site.

Example:
  $appName --host myhost -username me --password pw ls /

If the username and/or password are omitted, the command will prompt for them.

Additional options
------------------
  --help            Show this help information.
  --verbose         Be verbose while performing the operation.
  --version         Show application version numbers.
  --format type     Select how information is displayed:
                      auto     (default) Use an operation-specific default
                      full     Show full-detail nested-array output
                      keyvalue Show a table of key-value pairs
                      linux    Show a Linux-style output
                      text     Show key-value pairs as text

For Linux/macOS/BSD-style commands, the format type defaults to 'linux'
and output is formatted similar to those OS commands.

Example:
  $appName --host myhost --format full stat /myfolder

Paths
-----
Remote file and folder paths have the form:

  [SCHEME:][//USER]/PATH.

SCHEME is optional and one of:
  private  (default) Your files and folders.
  public   The host's public files and folders available to anyone.
  shared   The files and folders shared with you.

USER is optional and selects the user ID or account name. It is primarily
for the 'shared' scheme and defaults to the current user.

PATH is a file and folder path, separated by forward slashes. Wildcards are
not accepted. If a path contains spaces or special characters, the whole
path should be included in quotes.

Examples:
  $appName --host myhost ls /
  $appName --host myhost ls private:/
  $appName --host myhost ls public:/
  $appName --host myhost ls shared://johnson/

EOT;

  $help = $helpStart;

  $commandNames = array_keys(COMMANDS);
  sort($commandNames, (SORT_STRING | SORT_FLAG_CASE));

  foreach ($commandNames as $commandName) {
    $commandInfo = COMMANDS[$commandName];

    if (isset($commandInfo['brief']) === TRUE) {
      $brief = $commandInfo['brief'];
    }
    else {
      $brief = $commandName;
    }

    $help .= sprintf("  %-18s%s\n", $commandName, $brief);
  }

  $help .= $helpEnd;

  fwrite(STDOUT, $help);
}

/**
 * Prints help information for prompt use.
 *
 * The printed information is written for users that are using the interactive
 * prompt. The host, user name, password, and other base options are not listed
 * since they would already have been provided.
 *
 * @param string $appPath
 *   The path to the application.
 */
function printPromptHelp(string $appPath) {
  $helpStart = <<<EOT
Commands
--------

EOT;

  $helpEnd = <<<EOT

Add "--help" after any command to show its description and flags.

Paths
-----
Remote file and folder paths have the form:

  [SCHEME:][//USER]/PATH.

SCHEME is optional and one of:
  private  (default) Your files and folders.
  public   The host's public files and folders available to anyone.
  shared   The files and folders shared with you.

USER is optional and selects the user ID or account name. It is primarily
for the 'shared' scheme and defaults to the current user.

PATH is a file and folder path, separated by forward slashes. Wildcards are
not accepted. If a path contains spaces or special characters, the whole
path should be included in quotes.

Examples:
  ls /
  ls private:/
  ls public:/
  ls shared://johnson/

EOT;

  $help = $helpStart;

  $commandNames = array_keys(COMMANDS);
  $commandNames[] = 'help';
  $commandNames[] = 'quit';
  sort($commandNames, (SORT_STRING | SORT_FLAG_CASE));

  foreach ($commandNames as $commandName) {
    if ($commandName === 'help') {
      $brief = "Show this help information.";
    }
    elseif ($commandName === 'quit') {
      $brief = "Quit.";
    }
    else {
      $commandInfo = COMMANDS[$commandName];

      if (isset($commandInfo['brief']) === TRUE) {
        $brief = $commandInfo['brief'];
      }
      else {
        $brief = $commandName;
      }
    }

    $help .= sprintf("  %-18s%s\n", $commandName, $brief);
  }

  $help .= $helpEnd;

  fwrite(STDOUT, $help);
}

/**
 * Prints help information for a command.
 *
 * @param string $appName
 *   The name of this command-line application.
 * @param string $commandName
 *   The name of the command.
 */
function printCommandHelp(string $appName, string $commandName) {
  if (isset(COMMANDS[$commandName]) === FALSE) {
    // Unknown command. Print nothing.
    return;
  }

  $command = COMMANDS[$commandName];

  //
  // Print synopsis
  // --------------
  // Print a command-line template for the command.
  $synopsis = "Usage: $appName [HOST_OPTIONS] ";
  if (isset($command['synopsis']) === FALSE) {
    $synopsis .= $commandName;
  }
  else {
    $synopsis .= $command['synopsis'];
  }

  fwrite(STDOUT, "$synopsis\n");

  //
  // Print description
  // -----------------
  // Print a multi-line text description.
  $description = "\n";
  if (isset($command['description']) === TRUE) {
    $description .= $command['description'] . "\n";
  }

  fwrite(STDOUT, $description);

  //
  // Print flags (if any)
  // -----------
  // Print a list of flags and their descriptions.
  if (isset($command['flags']) === TRUE) {
    fwrite(STDOUT, "\nOptions:\n");

    // Get flag names then sort them.
    $flagNames = array_keys($command['flags']);
    sort($flagNames, (SORT_STRING | SORT_FLAG_CASE));

    // Print them out.
    $flagIndent = '  ';
    $flagHelpIndent = '      ';

    foreach ($flagNames as $flagName) {
      $flagInfo = &$command['flags'][$flagName];

      // Print the flag line, with synonyms.
      $flagLine = "$flagIndent$flagName";
      if (isset($flagInfo['synonyms']) === TRUE) {
        foreach ($flagInfo['synonyms'] as $flagSynonym) {
          $flagLine .= ", $flagSynonym";
        }
      }

      fwrite(STDOUT, "$flagLine\n");

      // Print the flag command.
      if (isset($flagInfo['brief']) === TRUE) {
        $helpLines = mb_split("\n", $flagInfo['brief']);
        foreach ($helpLines as $helpLine) {
          fwrite(STDOUT, "$flagHelpIndent$helpLine\n");
        }
      }
    }
  }

  //
  // Print command synonyms (if any)
  // ----------------------
  // Print a list of synonyms.
  if (isset($command['synonyms']) === TRUE) {
    $names = [$commandName];
    foreach ($command['synonyms'] as $synonym) {
      $names[] = $synonym;
    }
    sort($names, SORT_NATURAL);
    $text = implode(', ', $names);

    fwrite(STDOUT, "\nSynonyms:\n  $text\n");
  }
}

/**
 * Prints the client side version to STDERR and exits.
 */
function printVersionAndExit(array $options) {
  // Create a dummy server connection without a host or authentication.
  // Then get the version of the client side only.
  $dummyServer = new FolderShareConnect();
  $options['format'] = 'text';
  $text = runVersion($dummyServer, $options, FALSE);
  fwrite(STDERR, $text);
  exit(1);
}

/**
 * Prints an error message to STDERR and exits.
 *
 * @param string $appPath
 *   The path to the application.
 * @param string $message
 *   The error message.
 */
function printErrorAndExit(string $appPath, string $message) {
  $appName = basename($appPath, '.php');
  fwrite(STDERR, "$appName: $message\n");
  exit(1);
}

/*--------------------------------------------------------------------
 *
 * Command-line parsing.
 *
 * These functions parse the command line.
 *
 *--------------------------------------------------------------------*/

/**
 * Parses the command line and returns values.
 *
 * The command line is parsed and its values validated and returned in
 * an associative array.
 *
 * @param array $argv
 *   The array of command-line arguments.
 */
function parseCommandLine(array $argv) {
  //
  // Set initial values
  // ------------------
  // Get the application name and set up defaults for all values.
  $options = [];
  $appPath = $options['appPath'] = array_shift($argv);
  $appName = $options['appName'] = basename($appPath, '.php');

  // Connection options.
  $options['host'] = '';
  $options['username'] = '';
  $options['password'] = '';

  // Flags.
  $options['format'] = 'auto';
  $options['verbose'] = FALSE;

  // Command.
  $options['command'] = [];
  $options['paths'] = [];
  $options['flags'] = [];

  $showVersion = FALSE;

  $usage = "\nUse '--help' for a list of commands and options.";

  //
  // Parse arguments
  // ---------------
  // Loop through the arguments and look for options and commands.
  // When a command name is found, all further arguments are considered
  // part of that command.
  $n = count($argv);
  for ($i = 0; $i < $n; ++$i) {
    // The first argument that does not start with '-' is the command.
    // Add it to the command's argument list, along with all further
    // arguments. It is up to the command to parse them.
    $dash = mb_substr($argv[$i], 0, 1);
    if ($dash !== '-') {
      // Command found!
      $options['command'] = array_slice($argv, $i);
      break;
    }

    // Otherwise, parse generic options relating to the connection
    // or flags.
    //
    // Ignore one or two leading dashes while parsing options.
    $dashes = mb_substr($argv[$i], 0, 2);
    if ($dashes === '--') {
      $arg = mb_substr($argv[$i], 2);
    }
    else {
      $arg = mb_substr($argv[$i], 1);
    }

    switch ($arg) {
      // Immediate response options.
      case 'help':
        // Print help information and stop.
        printCommandLineHelp($appName);
        exit(0);

      case 'version':
        // Print application version and stop.
        $showVersion = TRUE;
        break;

      // Connection options.
      case 'host':
        if (($i + 1) >= $n) {
          printErrorAndExit(
            $appName,
            "A host name is required after '--$arg'.$usage");
        }

        ++$i;
        $options['host'] = $argv[$i];
        break;

      case 'user':
      case 'username':
        if (($i + 1) >= $n) {
          printErrorAndExit(
            $appName,
            "A user name is required after '--$arg'.$usage");
        }

        ++$i;
        $options['username'] = $argv[$i];
        break;

      case 'password':
        if (($i + 1) >= $n) {
          printErrorAndExit(
            $appName,
            "A password is required after '--$arg'.$usage");
        }

        ++$i;
        $options['password'] = $argv[$i];
        break;

      // Flags.
      case 'verbose':
        $options['verbose'] = TRUE;
        break;

      case 'format':
        if (($i + 1) >= $n) {
          printErrorAndExit(
            $appName,
            "A format name is required after '--$arg'.$usage");
        }

        ++$i;
        $value = $argv[$i];
        switch ($value) {
          // Principal values.
          case 'auto':
          case 'full':
          case 'keyvalue':
          case 'linux':
          case 'text':
            break;

          // Synonyms.
          case 'all':
          case 'raw':
            $value = 'full';
            break;

          case 'kv':
            $value = 'keyvalue';
            break;

          case 'macos':
          case 'osx':
          case 'unix':
          case 'bsd':
            $value = 'linux';
            break;

          case 'default':
            $value = 'auto';
            break;

          default:
            printErrorAndExit(
              $appName,
              "Unknown format name: '$value'.$usage");
            break;
        }

        $options['format'] = $value;
        break;

      default:
        // Otherwise the option is not generic.
        $a = $argv[$i];
        printErrorAndExit(
          $appName,
          "Unknown option: '--$a'.$usage");
        break;
    }
  }

  //
  // Handle version
  // --------------
  // The --version flag can be used with or without a host.
  //
  // Without a host, just show the client side's versions.
  //
  // With a host, map --version to the 'version' command.
  if ($showVersion === TRUE) {
    if (empty($options['host']) === TRUE) {
      printVersionAndExit($options);
    }

    // Override any additional command given and make it "version".
    $options['command'] = ["version"];
  }

  //
  // Validate
  // --------
  // Check that a host name has been given.
  if (empty($options['host']) === TRUE) {
    printErrorAndExit(
      $appName,
      "A host name is required. Use '--host hostname'.$usage");
  }

  return $options;
}

/**
 * Validates a command and its flags.
 *
 * @param array $options
 *   The command-line options array.
 * @param bool $exitOnError
 *   (optional, default = TRUE) When TRUE, on an error a message is output
 *   and the application exits. When FALSE, on an error a message is output
 *   and FALSE is returned.
 *
 * @return bool
 *   Returns TRUE on successful validation, and FALSE otherwise.
 */
function validateCommand(array &$options, bool $exitOnError = TRUE) {
  //
  // Recognize command
  // -----------------
  // Check if the command is recognized. It can be a primary command name
  // or one of the synonyms.
  $appName = $options['appName'];
  if ($exitOnError === TRUE) {
    $usage = "\nUse '--help' for a list of commands and options.";
  }
  else {
    $usage = "\nType 'help' for a list of commands and options.\n";
  }

  if (isset($options['command']) === FALSE) {
    if ($exitOnError === TRUE) {
      printErrorAndExit(
        $appName,
        "Missing command.$usage");
    }

    print("Missing command.$usage");
    return FALSE;
  }

  $commandName = reset($options['command']);
  $found = FALSE;

  if (isset(COMMANDS[$commandName]) === TRUE) {
    $found = TRUE;
  }
  else {
    // Look through the synonyms of all commands. If found, swap the
    // command name from the synonym to the primary name.
    foreach (COMMANDS as $name => &$info) {
      if (isset($info['synonyms']) === TRUE) {
        if (in_array($commandName, $info['synonyms']) === TRUE) {
          $found = TRUE;
          $commandName = $name;
          $options['command'][0] = $name;
          break;
        }
      }
    }
  }

  if ($found === FALSE) {
    if ($exitOnError === TRUE) {
      printErrorAndExit(
        $appName,
        "Unknown command: '$commandName'.$usage");
    }

    print("Unknown command: '$commandName'.$usage");
    return FALSE;
  }

  //
  // Check implementation
  // --------------------
  // Make sure the command has a function! If it doesn't, the command is
  // not yet implemented.
  if (isset(COMMANDS[$commandName]['function']) === FALSE) {
    if ($exitOnError === TRUE) {
      printErrorAndExit(
        $appName,
        "$commandName is not supported yet.");
    }

    print("$commandName is not supported yet.\n");
    return FALSE;
  }

  //
  // Handle return formats
  // ---------------------
  // Map the 'auto' return type to the actual type.
  if ($options['format'] === 'auto') {
    $options['format'] = COMMANDS[$commandName]['defaultFormat'];
  }

  // Check that only valid return types were provided.
  if (empty(COMMANDS[$commandName]['formats']) === FALSE &&
      in_array($options['format'], COMMANDS[$commandName]['formats']) === FALSE) {
    $rt = $options['format'];
    if ($exitOnError === TRUE) {
      printErrorAndExit(
        $appName,
        "Unsupported format name '$rt' for '$commandName'.$usage");
    }

    print("Unsupported format name '$rt' for '$commandName'.$usage");
    return FALSE;
  }

  //
  // Parse flags and paths
  // ---------------------
  // Check that the flags make sense.
  $pathsAndFlags = parseCommandFlags(
    $appName,
    $commandName,
    $options['command']);
  if ($pathsAndFlags === FALSE) {
    return FALSE;
  }

  $options['paths'] = $pathsAndFlags['paths'];
  $options['flags'] = $pathsAndFlags['flags'];

  return TRUE;
}

/**
 * Parses flags for a specific command.
 *
 * An array arguments for a command is split into:
 * - Flags that start with '-' or '--'.
 * - Paths or other non-dash arguments.
 *
 * Flags are parsed for the command and errors generated for unrecognized
 * flags.
 *
 * Flags with a single dash are presumed to be single-letter flags
 * (e.g. "-a"). If multiple letters are given, the flags are expanded
 * into multiple flags (e.g. "-abc" becomes "-a", "-b", and "-c).
 *
 * Flags with a double dash are presumed to be multi-letter word flags
 * and are not changed.
 *
 * Flag synonyms are transparently mapped to their primary names (e.g.
 * "--stuff" becomes "-s" if "-s" is the primary name).
 *
 * @param string $appName
 *   The name of the command-line application.
 * @param string $commandName
 *   The name of the command.
 * @param array $args
 *   The arguments array from the basic command-line parser.
 *
 * @return array|bool
 *   Returns an associative array with 'flags' and 'paths' keys whose
 *   values are each arrays. The 'paths' array contains a list of
 *   paths or non-flag arguments, in order. The 'flags' array contains
 *   a list of flags. Returns FALSE on error.
 */
function parseCommandFlags(string $appName, string $commandName, array $args) {
  //
  // Split args
  // ----------
  // Divide up the incoming arguments into paths and flags. A flag starts
  // with a '-' or '--', while a path does not.
  $paths = [];
  $flags = [];

  array_shift($args);
  foreach ($args as $arg) {
    // Look at the first character to decide if the argument is a flag
    // or a path.
    $dash = mb_substr($arg, 0, 1);
    if ($dash === '-') {
      $flags[] = $arg;
    }
    else {
      $paths[] = $arg;
    }
  }

  //
  // Expand flags
  // ------------
  // For single-dash options with multiple letters, split the letters into
  // separate flags. For instance "-abc" becomes "-a", "-b", and "-c".
  // Leave double-dash options alone.
  $expandedFlags = [];
  foreach ($flags as $flag) {
    // Don't expand double-dash flags.
    $firstTwo = mb_substr($flag, 0, 2);
    if ($firstTwo === '--') {
      $expandedFlags[] = $flag;
      continue;
    }

    // No need to expand flags that are one letter after '-'.
    $len = mb_strlen($flag);
    if ($len === 2) {
      $expandedFlags[] = $flag;
    }

    for ($i = 1; $i < $len; ++$i) {
      $f = mb_substr($flag, $i, 1);
      $expandedFlags[] = '-' . $f;
    }
  }

  $flags = $expandedFlags;
  unset($expandedFlags);

  //
  // Simplify flags
  // --------------
  // For all flags, map synonyms to their primary flag name.
  $flagsInfo = COMMANDS[$commandName]['flags'];

  $simplifiedFlags = [];
  foreach ($flags as $flag) {
    if (isset($flagsInfo[$flag]) === TRUE) {
      // The flag is a primary flag name.
      $simplifiedFlags[] = $flag;
    }
    else {
      $found = FALSE;
      foreach ($flagsInfo as $flagName => &$flagInfo) {
        if (isset($flagInfo['synonyms']) === TRUE) {
          foreach ($flagInfo['synonyms'] as $synonymName) {
            if ($flag === $synonymName) {
              // Map the synonym to the primary flag name.
              $simplifiedFlags[] = $flagName;
              $found = TRUE;
              break 2;
            }
          }
        }
      }

      // If the flag was not found as a synonym of anything, then
      // report an error.
      if ($found === FALSE) {
        print("$commandName: Unrecognized option \"$flag\".\n");
        return FALSE;
      }
    }
  }

  return [
    'paths' => $paths,
    'flags' => $simplifiedFlags,
  ];
}

/*--------------------------------------------------------------------
 *
 * Operations.
 *
 * These functions perform the main operations, such as getting
 * information about entities, settings, and usage, or creating
 * new entities, or changing existing entities.
 *
 *--------------------------------------------------------------------*/

/**
 * Prints host configuration parameters.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | --help        | Show help on command.                     |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 */
function runConfig(FolderShareConnect $server, array $options) {
  //
  // Execute
  // -------
  // Report configuration.
  try {
    $response = $server->getConfiguration();

    if ($options['format'] === 'text') {
      print(FolderShareFormat::formatAsConfiguration($response));
    }
    else {
      print_r($response);
    }
  }
  catch (\Exception $e) {
    $commandName = reset($options['command']);
    print("$commandName: " . $e->getMessage() . "\n");
    return FALSE;
  }

  return TRUE;
}

/**
 * Prints file, folder, and storage usage for a user.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | --help        | Show help on command.                     |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 */
function runUsage(FolderShareConnect $server, array $options) {
  //
  // Execute
  // -------
  // Report usage.
  try {
    $response = $server->getUsage();

    if ($options['format'] === 'text') {
      print(FolderShareFormat::formatAsUsage($response));
    }
    else {
      print_r($response);
    }
  }
  catch (\Exception $e) {
    $commandName = reset($options['command']);
    print("$commandName: " . $e->getMessage() . "\n");
    return FALSE;
  }

  return TRUE;
}

/**
 * Prints software version numbers.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | --help        | Show help on command.                     |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection. If the connection is NULL, only the client
 *   side's version information is returned.
 * @param array $options
 *   The command-line options array.
 * @param bool $doConnect
 *   (optional, default = TRUE) When TRUE, a request is sent to the server
 *   to get its version information, which is then merged with information
 *   about the client API and PHP version. When FALSE, no request is sent
 *   and the returned array only includes the client API and PHP version.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 */
function runVersion(
  FolderShareConnect $server,
  array $options,
  bool $doConnect = TRUE) {
  //
  // Execute
  // -------
  // Report configuration.
  try {
    $response = $server->getVersion($doConnect);

    if (is_array($response) === FALSE) {
      print_r($response);
    }
    else {
      if (isset($response['client']) === FALSE) {
        $response['client'] = [];
      }

      // Add an application entry to the front of the client version list.
      $response['client'] = array_merge([
        'application' => [
          'name'      => NAME,
          'version'   => VERSION,
        ],
      ], $response['client']);

      if ($options['format'] === 'text') {
        print(FolderShareFormat::formatAsVersion($response));
      }
      else {
        print_r($response);
      }
    }
  }
  catch (\Exception $e) {
    $commandName = reset($options['command']);
    print("$commandName: " . $e->getMessage() . "\n");
    return FALSE;
  }

  return TRUE;
}

/**
 * Prints file or folder status.
 *
 * This command emulates the Linux/macOS/BSD "stat" command.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -t     | Show a terse form of the information.            |
 * | --help | Show help on command.                            |
 * | --terse| Same as -t.                                      |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * Linux "stat" supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -c     | Use a custom output format.                      |
 * | -f     | Show file system status instead of file status.  |
 * | -L     | Follow symbolic links.                           |
 * | -t     | Show a terse form of the information.            |
 * | --dereference | Same as -L.                               |
 * | --filesystem  | Same as -f.                               |
 * | --format      | Same as -c.                               |
 * | --help        | Show help on command.                     |
 * | --terse       | Same as -t.                               |
 * | --version     | Show version information.                 |
 *
 * BSD and macOS "stat" support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -f     | Use a custom output format.                      |
 * | -F     | Add special symbols on files and folders.        |
 * | -l     | Same as "ls -lT".                                |
 * | -L     | Follow symbolic links.                           |
 * | -n     | Suppress newline after each item.                |
 * | -q     | Suppress error messages.                         |
 * | -r     | Show raw information from the 'stat' syscall.    |
 * | -s     | Show information in a shell compatible format.   |
 * | -t     | Display time stamps in a custom output format.   |
 * | -x     | Show a verbose output format similar to Linux.   |
 *
 * BSD supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -H     | Treat arguments as hex NFS file handles.         |
 *
 * There is no POSIX "stat" command.
 *
 * The set of flags for Linux and macOS/BSD are entirely disjoint -
 * there is nothing in common. Some flags, like -t, show up in both
 * versions of "stat", but with different meanings.
 */
function runStat(FolderShareConnect $server, array $options) {
  //
  // Parse flags
  // -----------
  // Synonyms have already been mapped to primary flag names.
  $terseMode = FALSE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-t':
        $terseMode = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // If there are no paths given, default to '/'.
  $remotePaths = $options['paths'];
  if (empty($remotePaths) === TRUE) {
    $remotePaths = ['/'];
  }

  //
  // Execute
  // -------
  // Get entity information.
  foreach ($remotePaths as $remotePath) {
    try {
      $response = $server->getFileOrFolder($remotePath);

      if (empty($response) === FALSE) {
        if ($options['format'] === 'linux') {
          print(FolderShareFormat::formatAsLinuxStat($response, $terseMode));
        }
        elseif ($options['format'] === 'text') {
          print(FolderShareFormat::formatAsText($response));
        }
        else {
          print_r($response);
        }
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Prints a list of files and folders.
 *
 * This command emulates the Linux/macOS/BSD "ls" command.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -d     | List directories without recursion.              |
 * | -F     | Add special symbols on files and folders.        |
 * | -i     | Include the entity ID.                           |
 * | -l     | Show a long listing format.                      |
 * | -R     | Recursively list directories.                    |
 * | -s     | Include the size of each item.                   |
 * | -S     | Sort by size.                                    |
 * | -t     | Sort by modification time.                       |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * POSIX, Linux, macOS, and BSD "ls" support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -1     | Force one-column output.                         |
 * | -a     | Show all entries, including "." entries.         |
 * | -c     | Show last modified time.                         |
 * | -C     | Force multi-column output.                       |
 * | -d     | List directories without recursion.              |
 * | -f     | Do not sort. Also turns on -a.                   |
 * | -F     | Add special symbols on files and folders.        |
 * | -g     | Like -l, skip owner names (just show groups).    |
 * | -i     | Include the inode number.                        |
 * | -l     | Show a long listing format.                      |
 * | -L     | Show info on symbolic link target.               |
 * | -o     | Like -l, skip group names (just show owners).    |
 * | -q     | Replace non-printing characters with ?.          |
 * | -r     | Reverse the sort order.                          |
 * | -R     | Recursively list directories.                    |
 * | -s     | Include the size of each item.                   |
 * | -t     | Sort by modification time.                       |
 * | -u     | Sort by last access time.                        |
 *
 * Linux, macOS, and BSD support the following additional flags,
 * but not POSIX:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -h     | Simplify numbers with byte suffixes (e.g. MB).   |
 *
 * Linux and macOS support the following additional flags, but
 * not BSD:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -A     | Same as -a, but skip "." and "..".               |
 * | -H     | Follow symbolic links.                           |
 * | -k     | Show sizes in kilobytes, not blocks.             |
 * | -m     | Full width output with comma separated entries.  |
 * | -n     | Like -l, show numeric IDs.                       |
 * | -p     | Append '/' to directory names.                   |
 * | -S     | Sort by size.                                    |
 * | -x     | Order values side-to-side, instead of up-and-down.|
 *
 * Linux supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -b     | Show non-printing characters with C escape codes.|
 * | -B     | Ignore backup files ending with "~".             |
 * | -D     | Generate output for Emacs dired mode.            |
 * | -G     | With -l, skip group names.                       |
 * | -I     | Suppress entries matching a pattern.             |
 * | -N     | Print raw names, including control characters.   |
 * | -Q     | Enclose names in double-quotes.                  |
 * | -T     | Set tab stops.                                   |
 * | -U     | Do not sort.                                     |
 * | -v     | Use a natural sort for numbers in text.          |
 * | -w     | Set output width.                                |
 * | -X     | Sort by file name extension.                     |
 * | --all        | Same as -a.                                |
 * | --almost-all | Same as -A.                                |
 * | --author     | With -l, print the author of each file.    |
 * | --escape     | Same as -b.                                |
 * | --block-size | Show sizes in blocks.                      |
 * | --classify   | Same as -F.                                |
 * | --color      | Colorize output.                           |
 * | --dereference| Same as -L.                                |
 * | --dereference-command-line | Same as -H.                  |
 * | --dereference-command-line-symlink-to-dir| Follow symbolic links to dirs.|
 * | --directory  | Same as -d.                                |
 * | --dired      | Same as -D.                                |
 * | --file-type  | Same as -F, but don't add '*' on executables.|
 * | --format     | Customize formatting.                      |
 * | --full-time  | Same as -l, but use full ISO times.        |
 * | --help       | Show help on command.                      |
 * | --hide       | Hide entries that match a pattern.         |
 * | --hide-control-chars| Same as -q.                         |
 * | --human-readable | Same as -h.                            |
 * | --ignore     | Same as -I.                                |
 * | --ignore-backups | Same as -B.                            |
 * | --indicator-style| Same as -p, --file-type, or -F.        |
 * | --inode      | Same as -i.                                |
 * | --literal    | Same as -N.                                |
 * | --no-group   | Same as -G.                                |
 * | --quote-name | Same as -Q.                                |
 * | --quoting-style| Same as -Q but specify quoting.          |
 * | --numeric-uid-gid | Same as -n.                           |
 * | --recursive  | Same as -R.                                |
 * | --reverse    | Same as -r.                                |
 * | --show-control-characters| Show non-printing characters as-is.|
 * | --si         | Same as -h, but use powers of 1000 not 1024.|
 * | --size       | Same as -s.                                |
 * | --sort       | Select the sort key.                       |
 * | --tabsize    | Same as -T.                                |
 * | --time       | Select different time value.               |
 * | --time-style | Select time formatting.                    |
 * | --width      | Same as -w.                                |
 * | --version    | Show version information.                  |
 *
 * macOS supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -@     | With -l, show extended attributes.               |
 * | -b     | Show non-printing characters with octal escapes. |
 * | -B     | Same as -b, but use C escape codes too.          |
 * | -e     | With -l, show ACLs.                              |
 * | -G     | Colorize output.                                 |
 * | -O     | With -l, include file flags.                     |
 * | -P     | Show in on symbolic link, not target.            |
 * | -T     | With -l, show full detailed time.                |
 * | -U     | Sort by creation time.                           |
 * | -v     | Show non-printing characters as-is.              |
 * | -w     | Force raw printing of non-printable characters.  |
 * | -W     | Show whiteouts when scanning directories.        |
 */
function runLs(FolderShareConnect $server, array $options) {
  //
  // Parse options
  // -------------
  // Synonyms have already been mapped to primary flag names.
  $recurse = FALSE;
  $formatFlags = [];

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-d':
        $recurse = FALSE;
        break;

      case '-R':
        $recurse = TRUE;
        break;

      case '-F':
      case '-i':
      case '-l':
      case '-s':
      case '-S':
      case '-t':
        $formatFlags[] = $flagName;
        break;
    }
  }

  //
  // Validate
  // --------
  // If there are no paths given, default to '/'.
  $remotePaths = $options['paths'];
  if (empty($remotePaths) === TRUE) {
    $remotePaths = ['/'];
  }

  if ($recurse === TRUE && $options['format'] !== 'linux') {
    $commandName = reset($options['command']);
    print("$commandName: Recursive lists must use a 'linux' output format.\n");
    return FALSE;
  }

  //
  // Execute
  // -------
  // Get a list of entities. Format the response using the flags, if any.
  while (empty($remotePaths) === FALSE) {
    $remotePath = array_shift($remotePaths);

    if ($recurse === TRUE) {
      print("$remotePath:\n");
    }

    // Get the list.
    try {
      switch ($remotePath) {
        case '/':
        case '/private':
          $response = $server->getRootFolders('private');
          break;

        case '/public':
          $response = $server->getRootFolders('public');
          break;

        case '/shared':
          $response = $server->getRootFolders('shared');
          break;

        default:
          $response = $server->getDescendants($remotePath);
          break;
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");
      return FALSE;
    }

    // Print the response.
    if (empty($response) === FALSE) {
      switch ($options['format']) {
        case 'linux':
          print(FolderShareFormat::formatAsLinuxLs($response, $formatFlags));
          break;

        case 'text':
          print(FolderShareFormat::formatAsText($response));
          break;

        default:
          print_r($response);
      }
    }

    // Update the paths array by adding child paths to the front.
    if ($recurse === TRUE) {
      $recursePaths = [];
      if (is_array($response) === TRUE) {
        // Loop through the response and create a path for each folder item.
        foreach ($response as $r) {
          if (isset($r['path']) === TRUE && isset($r['kind']) === TRUE) {
            $k = $r['kind'];
            if ($k === 'folder' || $k === 'rootfolder') {
              $recursePaths[] = $r['path'];
            }
          }
        }
      }

      // Add recursion paths to the front of the path list.
      // This causes us to do a depth-first traversal.
      $remotePaths = ($recursePaths + $remotePaths);

      if (empty($remotePaths) === FALSE) {
        print("\n");
      }
    }
  }

  return TRUE;
}

/**
 * Deletes files and folders.
 *
 * This command emulates the Linux/macOS/BSD "rm" command.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -d     | Remove directories as well as files.             |
 * | -f     | Don't prompt for comfirmation and ignore errors. |
 * | -R     | Recursively delete directory trees (implies -d). |
 * | -r     | Same as -R.                                      |
 * | -v     | Print the name of each item as it is deleted.    |
 * | --help | Show help on command.                            |
 *
 * By default, this method does not remove folders. Use "-d"
 * to remove a file or folder.
 *
 * By default, this method does not recurse and will not remove
 * folders that are not empty. Use "-r" to recurse.
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * POSIX, Linux, macOS, and BSD "rm" support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -f     | Don't prompt for comfirmation and ignore errors. |
 * | -i     | Interactively confirm each item before deletion. |
 * | -R     | Recursively delete directory trees (implies -d). |
 * | -r     | Same as -R.                                      |
 *
 * Linux, macOS, and BSD "rm" support the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Print the name of each item as it is deleted.    |
 *
 * macOS and BSD "rm" support the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -d     | Remove directories as well as files.             |
 * | -P     | Overwrite regular files before deleting them.    |
 * | -W     | Undelete white-out deleted files.                |
 *
 * BSD "rm" supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -I     | Interactively confirm deletes > 3 files or dirs. |
 * | -x     | Do not cross mount points during recursive delete.|
 *
 * All Linux versions of "rm" support the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -I     | Interactively confirm deletes > 3 files or dirs. |
 * | --force            | Same as -f.                          |
 * | --help             | Show help on command.                |
 * | --interactive=WHEN | Same as -i and -I.                   |
 * | --no-preserve-root | Don't prevent '/' delete.            |
 * | --one-file-system  | Same as BSD -x.                      |
 * | --preserve-root    | Do prevent '/' delete.               |
 * | --recursive        | Same as -R.                          |
 * | --verbose          | Same as -v.                          |
 * | --version          | Show version information.            |
 *
 * RedHat Linux "rm" supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -d     | Remove directories as well as files.             |
 * | --directory        | Same as -d.                          |
 *
 * CentOS Linux "rm" supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -d     | Remove directories as well as files.             |
 * | --dir  | Same as -d.                                      |
 */
function runRm(FolderShareConnect $server, array $options) {
  //
  // Parse flags
  // -----------
  // Synonyms have already been mapped to primary flag names.
  $verbose      = FALSE;
  $recurse      = FALSE;
  $fileOrFolder = FALSE;
  $ignoreErrors = FALSE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-d':
        $fileOrFolder = TRUE;
        break;

      case '-f':
        $ignoreErrors = TRUE;
        break;

      case '-r':
        $recurse = TRUE;
        $fileOrFolder = TRUE;
        break;

      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // There must be at least one path.
  if (empty($options['paths']) === TRUE) {
    $commandName = reset($options['command']);
    print("$commandName: Missing remote file path.\n");
    return FALSE;
  }

  //
  // Execute
  // -------
  // Remove each of the indicated entities.
  foreach ($options['paths'] as $remotePath) {
    if ($verbose === TRUE) {
      print("$remotePath\n");
    }

    try {
      // There is normally no response.
      if ($fileOrFolder === FALSE) {
        $response = $server->deleteFile($remotePath);
      }
      else {
        $response = $server->deleteFileOrFolder($remotePath, $recurse);
      }

      if (empty($response) === FALSE) {
        print_r($response);
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");

      if ($ignoreErrors === FALSE) {
        return FALSE;
      }
    }
  }

  return TRUE;
}

/**
 * Deletes empty folders.
 *
 * This command emulates the Linux/macOS/BSD "rmdir" command.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Show the name of each item deleted.              |
 * | --help | Show help on command.                            |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * POSIX, Linux, macOS, and BSD "rmdir" support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -p     | Remove every directory on the path.              |
 *
 * Linux supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Show the name of each item deleted.              |
 * | --help    | Show help on command.                         |
 * | --ignore-fail-on-non-empty | Ignore non-empty errors.     |
 * | --parents | Same as -p.                                   |
 * | --verbose | Same as -v.                                   |
 * | --version | Show version information.                     |
 *
 * BSD supports the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Show the name of each item deleted.              |
 */
function runRmdir(FolderShareConnect $server, array $options) {
  //
  // Parse flags
  // -----------
  // Synonyms have already been mapped to primary flag names.
  $verbose = FALSE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // There must be at least one path.
  if (empty($options['paths']) === TRUE) {
    $commandName = reset($options['command']);
    print("$commandName: Missing remote file path.\n");
    return FALSE;
  }

  //
  // Execute
  // -------
  // Remove each of the indicated entities.
  foreach ($options['paths'] as $remotePath) {
    if ($verbose === TRUE) {
      print("$remotePath\n");
    }

    try {
      // There is normally no response.
      $response = $server->deleteFolder($remotePath);
      if (empty($response) === FALSE) {
        print_r($response);
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Creates a new root folder or subfolder.
 *
 * This command emulates the Linux/macOS/BSD "mkdir" command.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Show the name of each item deleted.              |
 * | --help | Show help on command.                            |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * POSIX, Linux, macOS, and BSD "mkdir" support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -m     | Set the permissions mode for new directories.    |
 * | -p     | Create parent directories too, if needed.        |
 * | -v     | Show the name of each item created.              |
 *
 * Linux has the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | --help    | Show help on command.                         |
 * | --mode    | Same as -m.                                   |
 * | --parents | Same as -p.                                   |
 * | --verbose | Same as -v.                                   |
 * | --version | Show version information.                     |
 */
function runMkdir(FolderShareConnect $server, array $options) {
  //
  // Parse options
  // -------------
  // Synonyms have already been mapped to primary flag names.
  $verbose = FALSE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // There must be at least one path.
  if (empty($options['paths']) === TRUE) {
    $commandName = reset($options['command']);
    print("$commandName: Missing remote file path.\n");
    return FALSE;
  }

  //
  // Execute
  // -------
  // Create a series of directories.
  //
  // If the path is of the form /ROOT then create a root folder.
  // Otherwise if the path is of the form /ROOT/MORE... then create
  // a subfolder. This requires that we parse the path a bit here,
  // before calling the server.
  foreach ($options['paths'] as $remotePath) {
    $indexOfLastSlash = mb_strrpos($remotePath, '/');
    if ($indexOfLastSlash === FALSE) {
      // No '/' was found at all! Since paths must start with a slash,
      // this is an error.
      $commandName = reset($options['command']);
      print("$commandName: Folder paths must start with '/'.\n");
      return FALSE;
    }

    if ($indexOfLastSlash === 0) {
      // The path has only one '/' and it is at the start of the path.
      // The path therefore names a new root folder to create.
      //
      // Remove the '/' before creating the root folder.
      $name = mb_substr($remotePath, ($indexOfLastSlash + 1));
      $parentPath = '/';
    }
    else {
      // The path contains more than one '/'. Pull out the name after
      // the last '/' and the path before it, then create a subfolder.
      $name = mb_substr($remotePath, ($indexOfLastSlash + 1));
      $parentPath = mb_substr($remotePath, 0, $indexOfLastSlash);
    }

    if ($verbose === TRUE) {
      print("$remotePath\n");
    }

    try {
      // There is normally no response.
      if ($parentPath === '/') {
        $response = $server->newRootFolder($name);
      }
      else {
        $response = $server->newFolder($parentPath, $name);
      }

      if (empty($response) === FALSE) {
        print_r($response);
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Moves a file or folder to a new location.
 *
 * This command emulates the Linux/macOS/BSD "mv" command.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -n     | Do not overwrite an existing file.               |
 * | -v     | Show the name of each item moved.                |
 * | --help | Show help on command.                            |
 *
 * This command always recursively copies folders.
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * POSIX, Linux, macOS, and BSD "mv" support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -f     | Don't prompt for comfirmation and ignore errors. |
 * | -i     | Interactively confirm each item before moving.   |
 *
 * Linux, macOS, and BSD have the following additional flags, but
 * not POSIX:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -n     | Do not overwrite an existing file.               |
 * | -v     | Show the name of each item moved.                |
 *
 * BSD has the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -h     | Do not follow target symbolic links.             |
 *
 * Linux has the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -S     | Override the usual backup suffix.                |
 * | -t     | Move everything into the target directory.       |
 * | -T     | Treat the target as a file, not a directory.     |
 * | -u     | Only move if the source is newer.                |
 * | --force       | Same as -f.                               |
 * | --help    | Show help on command.                         |
 * | --interactive | Same as -i.                               |
 * | --no-clobber  | Same as -n.                               |
 * | --no-target-directory | Same as -T.                       |
 * | --suffix      | Same as -S.                               |
 * | --target-directory | Same as -t.                          |
 * | --update  | Same as -u.                                   |
 * | --verbose | Same as -v.                                   |
 * | --version | Show version information.                     |
 */
function runMv(FolderShareConnect $server, array $options) {
  //
  // Parse options
  // -------------
  // Synonyms have already been mapped to primary flag names.
  $verbose = FALSE;
  $overwrite = TRUE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-n':
        $overwrite = FALSE;
        break;

      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // The command has one of two forms:
  // - mv source destination
  // - mv source1 source2 source3... destination
  //
  // The first form is a degenerate case of the second that supports
  // optional renaming of the source. In that case the destination does
  // not refer to an entity that exists, yet.
  //
  // However, only the server knows whether a destination exists. So
  // we need to break this down into a loop of source-to-destination
  // operations and watch for failure.
  //
  // Confirm there are at least two paths given.
  switch (count($options['paths'])) {
    case 0:
      $commandName = reset($options['command']);
      print("$commandName: Missing remote file paths.\n");
      return FALSE;

    case 1:
      $commandName = reset($options['command']);
      print("$commandName: Missing remote destination file path.\n");
      return FALSE;

    default:
      $remotePaths = $options['paths'];
      $destinationPath = array_pop($remotePaths);
      break;
  }

  //
  // Execute
  // -------
  // Move a series of items to the same destination. Abort on the first error.
  foreach ($remotePaths as $remotePath) {
    if ($verbose === TRUE) {
      print("$remotePath\n");
    }

    try {
      // There is normally no response.
      $response = $server->move($remotePath, $destinationPath, $overwrite);
      if (empty($response) === FALSE) {
        print_r($response);
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Copies a file or folder to a new location.
 *
 * This command emulates the Linux/macOS/BSD "cp" command.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -n     | Do not overwrite existing files.                 |
 * | -v     | Show the name of each item copied.               |
 * | --help | Show help on command.                            |
 *
 * This command always recursively copies folders.
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * POSIX, Linux, macOS, and BSD "cp" support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -f     | Don't prompt for comfirmation and ignore errors. |
 * | -H     | With -R, follow command line symbolic links.     |
 * | -i     | Interactively confirm each item before copying.  |
 * | -L     | With -R, follow recursive symbolic links.        |
 * | -P     | With -R, don't follow symbolic links (default).  |
 * | -p     | Preserve dates, permissions, and owner on copy.  |
 * | -R     | Recursively copy trees.                          |
 *
 * Linux, macOS, and BSD have the following additional flags, but
 * not POSIX:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -a     | Same as -pPR.                                    |
 * | -n     | Do not overwrite existing files.                 |
 * | -v     | Show the name of each item copied.               |
 *
 * BSD has the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -l     | Hard link files instead of copying.              |
 * | -x     | Do not cross mount points during recursive copy. |
 *
 * macOS has the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -c     | Copy files useing clonefile.                     |
 * | -X     | Do not copy extended attributes.                 |
 *
 * Linux has the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -b     | Make a backup of each destination file.          |
 * | -d     | Same as -P --preserve=links.                     |
 * | -l     | Hard link files instead of copying.              |
 * | -n     | Do not overwrite existing files.                 |
 * | -r     | Same as -R.                                      |
 * | -s     | Symbolic link files instead of copying.          |
 * | -S     | Override backup file name suffix.                |
 * | -t     | Specify destination (target) directory.          |
 * | -T     | Treat destination as a file, not a directory.    |
 * | -u     | Copy only if the source file is newer.           |
 * | -x     | Do not cross mount points during recursive copy. |
 * | --archive| Same as -a.                                    |
 * | --backup | Same as -b.                                    |
 * | --copy-contents | Copy special files when recursive.      |
 * | --dereference   | Same as -L.                             |
 * | --force         | Same as -f.                             |
 * | --help | Show help on command.                            |
 * | --interactive   | Same as -i.                             |
 * | --link          | Same as -l.                             |
 * | --no-clobber    | Same as -n.                             |
 * | --no-dereference| Same as -P.                             |
 * | --no-preserve   | Don't preserve attributes on copy.      |
 * | --no-target-directory| Same as -T.                        |
 * | --parents       | Use full source file name under dir.    |
 * | --one-file-system| Same as -x.                            |
 * | --preserve      | Preserve specific attributes on copy.   |
 * | --recursive     | Same as -R.                             |
 * | --reflink       | Control clones.                         |
 * | --remove-directories| Remove existing destinations first. |
 * | --sparse        | Control sparse files.                   |
 * | --strip-trailing-slashes| Remove last slashes in source.  |
 * | --symbolic-link | Same as -s.                             |
 * | --suffix        | Same as -S.                             |
 * | --target-directory| Same as -t.                           |
 * | --update        | Same as -u.                             |
 * | --verbose       | Same as -v.                             |
 * | --version | Show version information.                     |
 */
function runCp(FolderShareConnect $server, array $options) {
  //
  // Parse options
  // -------------
  // Synonyms have already been mapped to primary flag names.
  $verbose = FALSE;
  $overwrite = TRUE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-n':
        $overwrite = FALSE;
        break;

      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // The command has one of two forms:
  // - cp source destination
  // - cp source1 source2 source3... destination
  //
  // The first form is a degenerate case of the second that supports
  // optional renaming of the copy. In that case the destination does
  // not refer to an entity that exists, yet.
  //
  // However, only the server knows whether a destination exists. So
  // we need to break this down into a loop of source-to-destination
  // operations and watch for failure.
  //
  // Confirm there are at least two paths given.
  switch (count($options['paths'])) {
    case 0:
      $commandName = reset($options['command']);
      print("$commandName: Missing remote file paths.\n");
      return FALSE;

    case 1:
      $commandName = reset($options['command']);
      print("$commandName: Missing remote destination file path.\n");
      return FALSE;

    default:
      $remotePaths = $options['paths'];
      $destinationPath = array_pop($remotePaths);
      break;
  }

  //
  // Execute
  // -------
  // Copy a series of items to the same destination. Abort on the first error.
  foreach ($remotePaths as $remotePath) {
    if ($verbose === TRUE) {
      print("$remotePath\n");
    }

    try {
      // There is normally no response.
      $response = $server->copy($remotePath, $destinationPath, $overwrite);
      if (empty($response) === FALSE) {
        print_r($response);
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Updates file and folder field values.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Show the name of each item modified.             |
 * | --help | Show help on command.                            |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * There is no standard Linux/macOS/BSD command for changing file metadata.
 *
 * The Linux 'getfattr' and 'setfattr' commands get and set arbitrary file
 * metadata:
 * - getfattr -d files...
 *   Print all metadata.
 *
 * - getfattr -n fieldname files...
 *   Print the metadata for the selected field.
 *
 * - setfattr -n fieldname -v fieldvalue files...
 *   Write the metadata for the selected field.
 *
 * - setfattr -x fieldname files...
 *   Delete the metadata for the selected field.
 *
 * The Linux commands support the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -e     | Set text encoding.                               |
 * | -h     | Apply to link, not target of link.               |
 * | -m     | Only show fields that match a pattern.           |
 * | -R     | Recurse into directories.                        |
 * | -L     | Follow symbolic links.                           |
 * | -P     | Do not follow symbolic links.                    |
 * | --absolute-name | Don't remove leading '/'.               |
 * | --dump    | Same as -d.                                   |
 * | --encoding| Same as -e.                                   |
 * | --help    | Show help on command.                         |
 * | --logical | Same as -L.                                   |
 * | --match   | Same as -m.                                   |
 * | --no-dereference| Same as -h.                             |
 * | --only-values | Only print out field values.              |
 * | --physical| Same as -P.                                   |
 * | --recursive| Same as -r.                                  |
 * | --version | Show version information.                     |
 *
 * The macOS 'xattr' command gets, sets, and deletes arbitrary file metadata:
 * - xattr files...
 *   Print all metadata.
 *
 * - xattr -p fieldname files...
 *   Print the metadata for the selected field.
 *
 * - xattr -w fieldname fieldvalue files...
 *   Write the metadata for the selected field.
 *
 * - xattr -d fieldname files...
 *   Delete the metadata for the selected field.
 *
 * - xattr -c files...
 *   Clear all metadata.
 *
 * The macOS commands support the following additional flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -l     | Long form that shows field names and values.     |
 * | -r     | Recurse into directories.                        |
 * | -s     | Apply to link, not target of link.               |
 * | -v     | Show the name of each item modified.             |
 * | -x     | Print output in hex.                             |
 *
 * The BSD 'getextattr', 'lsextattr', 'rmextattr', and 'setextattr' commands
 * get, list, delete, and set arbitrary file metadata:
 * - lsextattr namespace files...
 *   Print all metadata. 'namespace' is either 'user' or 'system'.
 *
 * - getextattr namespace fieldname files...
 *   Print the metadata for the selected field.
 *
 * - setextattr namespace fieldname fieldvalue files...
 *   Write the metadata for the selected field.
 *
 * - rmextattr namespace fieldname files...
 *   Delete the metadata for the selected field.
 *
 * The BSD commands support the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -f     | Don't prompt for comfirmation and ignore errors. |
 * | -h     | Apply to link, not target of link.               |
 * | -i     | Read attributes from stdin.                      |
 * | -n     | NULL terminate output data.                      |
 * | -q     | Be quite, don't print path name or errors.       |
 * | -s     | Convert non-printing characters to escapes.      |
 * | -x     | Print output in hex.                             |
 *
 * This FolderShare "update" supports a structure similar to the Linux,
 * macOS, and BSD commands, but not identical to any of them.
 *
 * - FolderShare does not have arbitrary metadata - the fields have
 *   to be predefined by the FolderShare module, by third-party
 *   modules, or by the site administrator. So Linux/macOS/BSD-style
 *   operations to create or delete metadata fields are not meaningful.
 *
 * - The 'stat' command already outputs all entity fields. All we need
 *   here is a command to update them.
 *
 * - The Linux, macOS, and BSD commands all have a form that gives
 *   the name of a field, the value, and a list of files. We use
 *   that same form.
 */
function runUpdate(FolderShareConnect $server, array $options) {
  //
  // Parse options
  // -------------
  // Synonyms have already been mapped to primary flag names.
  $verbose = FALSE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // The command must have at least:
  // - A field name.
  // - A field value.
  // - A path.
  //
  // All further items are paths for additional entities.
  switch (count($options['paths'])) {
    case 0:
      $commandName = reset($options['command']);
      print("$commandName: Missing field name, value, and remote file path.\n");
      return FALSE;

    case 1:
      $commandName = reset($options['command']);
      print("$commandName: Missing field value and remote file path.\n");
      return FALSE;

    case 2:
      $commandName = reset($options['command']);
      print("$commandName: Missing remote file path.\n");
      return FALSE;

    case 3:
      $remotePaths = $options['paths'];
      $fieldName = array_shift($remotePaths);
      $fieldValue = array_shift($remotePaths);
      break;

    default:
      $commandName = reset($options['command']);
      print("$commandName: Too many arguments.\n");
      return FALSE;
  }

  //
  // Execute
  // -------
  // Change a series of items.
  foreach ($remotePaths as $remotePath) {
    if ($verbose === TRUE) {
      print("$remotePath\n");
    }

    try {
      // There is normally no response.
      $response = $server->update($remotePath, $fieldName, $fieldValue);
      if (empty($response) === FALSE) {
        print_r($response);
      }
    }
    catch (\Exception $e) {
      $commandName = reset($options['command']);
      print("$commandName: " . $e->getMessage() . "\n");
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Downloads a file and folder.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Show the name of each item modified.             |
 * | --help | Show help on command.                            |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * There is no standard Linux/macOS/BSD command for downloading a file.
 */
function runGet(FolderShareConnect $server, array $options) {
  //
  // Parse options
  // -------------
  // Synonyms have already been mapped to primary flag names.
  $verbose = FALSE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // The command must have exactly two paths. The first is a path to a local
  // file and the second is a path to a server parent folder.
  switch (count($options['paths'])) {
    case 0:
      $commandName = reset($options['command']);
      print("$commandName: Missing remote file path.\n");
      return FALSE;

    case 1:
      // One path: Remote. Use "." as local path.
      $remotePath = $options['paths'][0];
      $localPath = ".";
      break;

    case 2:
      // Two paths: Remote & Local.
      $remotePath = $options['paths'][0];
      $localPath = $options['paths'][1];
      break;

    default:
      $commandName = reset($options['command']);
      print("$commandName: Too many file paths.\n");
      return FALSE;
  }

  //
  // Execute
  // -------
  // Upload the file.
  try {
    if ($verbose === TRUE) {
      print("$remotePath\n");
    }

    // There is normally no response.
    $response = $server->download($remotePath, $localPath);
    if (empty($response) === FALSE) {
      print_r($response);
    }
  }
  catch (\Exception $e) {
    $commandName = reset($options['command']);
    print("$commandName: " . $e->getMessage() . "\n");
    return FALSE;
  }

  return TRUE;
}

/**
 * Uploads a local file and folder.
 *
 * This method supports the following flags:
 * | Flag   | Meaning                                          |
 * | ------ | ------------------------------------------------ |
 * | -v     | Show the name of each item modified.             |
 * | --help | Show help on command.                            |
 *
 * @param \FolderShare\FolderShareConnect $server
 *   The server connection.
 * @param array $options
 *   The command-line options array.
 *
 * @return bool
 *   Returns TRUE on success, and FALSE on failure. On failure an error
 *   message has already been output.
 *
 * @internal
 * There is no standard Linux/macOS/BSD command for uploading a file.
 */
function runPut(FolderShareConnect $server, array $options) {
  //
  // Parse options
  // -------------
  // Synonyms have already been mapped to primary flag names.
  $verbose = FALSE;

  foreach ($options['flags'] as $flagName) {
    switch ($flagName) {
      case '-v':
        $verbose = TRUE;
        break;
    }
  }

  //
  // Validate
  // --------
  // The command must have exactly two paths. The first is a path to a local
  // file and the second is a path to a server parent folder.
  switch (count($options['paths'])) {
    case 0:
      $commandName = reset($options['command']);
      print("$commandName: Missing local and remote file paths.\n");
      return FALSE;

    case 1:
      $commandName = reset($options['command']);
      print("$commandName: Missing remote file path.\n");
      return FALSE;

    case 2:
      $localPath = $options['paths'][0];
      $remotePath = $options['paths'][1];
      break;

    default:
      $commandName = reset($options['command']);
      print("$commandName: Too many file paths.\n");
      return FALSE;
  }

  //
  // Execute
  // -------
  // Upload the file.
  try {
    if ($verbose === TRUE) {
      print("$localPath\n");
    }

    // There is normally no response.
    $response = $server->upload($localPath, $remotePath);
    if (empty($response) === FALSE) {
      print_r($response);
    }
  }
  catch (\Exception $e) {
    $commandName = reset($options['command']);
    print("$commandName: " . $e->getMessage() . "\n");
    return FALSE;
  }

  return TRUE;
}

/*--------------------------------------------------------------------
 *
 * Execute!
 *
 * Run the application.
 *
 *--------------------------------------------------------------------*/

//
// Parse the command line. There may not be a command.
//
$options = parseCommandLine($argv);
$appName = $options['appName'];


//
// Open server connection
// ----------------------
// If needed, prompt for the user name and password. Then use the given
// host and open a server connection and set the return format and
// verbosity level.
try {
  // Prompt for user name.
  if (empty($options['username']) === TRUE) {
    print("Username: ");
    $options['username'] = stream_get_line(STDIN, 1024, PHP_EOL);
  }

  // Prompt for password.
  if (empty($options['password']) === TRUE) {
    print("Password: ");
    $options['password'] = stream_get_line(STDIN, 1024, PHP_EOL);
  }

  // Open server connection.
  $server = new FolderShareConnect();

  // Enable/disable verbosity.
  if ($options['verbose'] === TRUE) {
    $server->setVerbose(TRUE);
  }

  // Set the host.
  $server->setHostName($options['host']);

  // Log in.
  //
  // Provide the user name and password to log in. This typically triggers
  // a connection. It will fail if the host, user name, or password are bad,
  // or if there are problems communicating with the host.
  if ($server->login($options['username'], $options['password']) === FALSE) {
    printErrorAndExit($appName, "Login failed.");
  }
}
catch (\Exception $e) {
  printErrorAndExit($appName, $e->getMessage());
}

//
// Execute single command
// ----------------------
// If a command was given on the command line, execute it.
if (empty($options['command']) === FALSE) {
  //
  // Validate command
  // ----------------
  // If a command is given, it must be valid.
  // - Check if the command is a primary name or a synonym.
  // - Check that there is a function for the command.
  // - Check that the return format is supported.
  // - Check that the given flags make sense.
  if (empty($options['command']) === FALSE) {
    validateCommand($options);
  }

  if (in_array('--help', $options['flags']) === TRUE) {
    printCommandHelp($appName, $options['command']);
    exit(0);
  }

  // Set the preferred return format.
  if ($options['format'] === 'linux' || $options['format'] === 'text') {
    $server->setReturnFormat('keyvalue');
  }
  else {
    $server->setReturnFormat($options['format']);
  }

  try {
    //
    // Dispatch
    // --------
    // Invoke the command.
    $commandName = reset($options['command']);
    $status = COMMANDS[$commandName]['function']($server, $options);
    exit(($status === TRUE) ? 0 : 1);
  }
  catch (\Exception $e) {
    printErrorAndExit($appName, $e->getMessage());
  }
}

//
// Execute multiple commands
// -------------------------
// Enter a prompt loop and execute commands as given.
print("Foldershare shell.\nType 'help' for a list of commands, 'quit' to exit.\n");
while (TRUE) {
  try {
    //
    // Prompt
    // ------
    // Get a single line of input.
    $prompt = "$appName> ";
    if (PHP_OS === 'WINNT') {
      $line = stream_get_line(STDIN, 1024, PHP_EOL);
    }
    else {
      $line = readline($prompt);
    }

    if ($line === FALSE) {
      // EOF.
      break;
    }

    if (empty($line) === TRUE) {
      continue;
    }

    if (PHP_OS !== 'WINNT') {
      readline_add_history($line);
    }

    //
    // Split into arguments
    // --------------------
    // Quotes require special handling to keep values together as a single
    // argument.
    $parts = mb_split('["\']', $line);
    $inQuoted = FALSE;
    $args = [];
    foreach ($parts as $part) {
      if ($inQuoted === TRUE) {
        // This part is a quoted string, with the quotes removed. Do not
        // trim or change in any way.
        $args[] = $part;
        $inQuoted = FALSE;
      }
      else {
        // The part is one or more non-quoted arguments. Remove redundant
        // white space, then split into arguments at white space boundaries.
        foreach (mb_split(' ', mb_ereg_replace('\s+', ' ', $part)) as $p) {
          if (empty($p) === FALSE) {
            $args[] = $p;
          }
        }
        $inQuoted = TRUE;
      }
    }

    // Build an options array.
    $localOptions = $options;
    $localOptions['command'] = $args;

    $commandName = reset($localOptions['command']);
    switch ($commandName) {
      case 'help':
        printPromptHelp($appName);
        continue 2;

      case 'quit':
        exit(0);
    }

    //
    // Validate command
    // ----------------
    // If a command is given, it must be valid.
    // - Check if the command is a primary name or a synonym.
    // - Check that there is a function for the command.
    // - Check that the return format is supported.
    // - Check that the given flags make sense.
    if (validateCommand($localOptions, FALSE) === FALSE) {
      continue;
    }

    $commandName = reset($localOptions['command']);

    if (in_array('--help', $localOptions['flags']) === TRUE) {
      printCommandHelp($appName, $commandName);
      continue;
    }

    // Set the preferred return format.
    if ($localOptions['format'] === 'linux' ||
        $localOptions['format'] === 'text') {
      $server->setReturnFormat('keyvalue');
    }
    else {
      $server->setReturnFormat($localOptions['format']);
    }

    //
    // Dispatch
    // --------
    // Invoke the command.
    COMMANDS[$commandName]['function']($server, $localOptions);
  }
  catch (\Exception $e) {
    print($e->getMessage());
  }
}
