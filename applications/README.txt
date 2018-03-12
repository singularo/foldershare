                        "foldershare" command line tool

                      by the San Diego Supercomputer Center
                   at the University of California at San Diego


CONTENTS OF THIS FILE
---------------------
 * Introduction
 * Building the client application
 * Using the client application
 * Using the client API


INTRODUCTION
------------
The FolderShare Drupal module provides optional web services based upon Drupal
core's REST module. When enabled on a web site, client software running on a
local host can communicate with the web server and FolderShare module to upload,
download, and manage files and folders in FolderShare's virtual file system.

You can access web services in two ways:

  1. Use a client application, such as the one we provide.
  2. Write your own client application using our client API.


BUILDING THE CLIENT APPLICATION
-------------------------------
The client application is composed of three PHP files in the 'src' directory:

  - foldershare.php is the application.
  - FolderShareConnect.php is the client API.
  - FolderShareFormat.php provides a content formatting API.

If you like, you can run these from the 'src' directory:

  cd src
  php ./foldershare.php --help

However, executing the application in this way requires that the support
class files be in the same directory. This is not ideal.

Instead, you can build a stand-alone application that bundles these files
together. The result is a single file that you can move or copy anywhere
and execute from anywhere.

To build the stand-alone 'foldershare' application:

  ./build


USING THE CLIENT APPLICATION
----------------------------
To run the application:

  ./foldershare --help

The application's help pages describe how to use the application and send
requests to the server. All requests require a host name, user name, and
password. If you do not provide a user name and password, the application
will prompt for them.

For example, to list top-level folders:

  ./foldershare --host example.com ls /

If you omit everything after the host, the application will enter into a
mini-shell and prompt you for requests to send.


USING THE CLIENT API
--------------------
You can write your own PHP client applications by using the FolderShareConnect
and FolderShareFormat classes in the 'src' directory. Both classes have
extensive block comments that document functions and how they are used.

The simplest application goes through these steps:

  1. Create a connection object.
  2. Set the host.
  3. Login using a user name and password.
  4. Issue a request.
  5. Do something with the response.
  6. Optionally logout before exiting.

The PHP code to get version numbers would look like this:

  $server = new FolderShareConnect();
  $server->setHostName("example.com");
  $server->login("myname", "mypassword");
  $response = $server->getVersion();
  print_r($response);
  $server->logout();

Naturally, this code should start with 'require' and 'use' statements, and
each of the steps should check for errors (such as a host not responding or
invalid login credentials).

The FolderShareConnect class handles all communications. The FolderShareFormat
class provides some optional formatting functions. For instance, the above
code gets version numbers for client and server software. The version numbers
are returned as an array of key-value pairs. The FolderShareFormat class
provides 'formatAsVersion' to format these nicely for human consumption:

  print(FolderShareFormat::formatAsVersion($server->getVersion()));

You can read through 'foldershare.php' to see how these classes are used.
