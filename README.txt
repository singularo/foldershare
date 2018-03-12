                        "foldershare" module for Durpal 8

                      by the San Diego Supercomputer Center
                   at the University of California at San Diego


WARNING: This is an early ALPHA release. This module has known bugs, it does
not have a unit test suite yet, and the API documentation is preliminary.
This module is suitable for early testing but it should *not* be used in a
production site. The module's API and database schema may change in a future
release and there is no guarantee of a migration path to that release.


CONTENTS OF THIS FILE
---------------------
 * Introduction
 * Requirements
 * Recommended modules
 * Installation
 * Configuration
 * Troubleshooting
 * Maintainers


INTRODUCTION
------------
The FolderShare module for Drupal 8 manages shared folders and their files and
subfolders. Folders may be nested to create a folder tree like that provided
by Windows, Mac, and Linux systems. Access controls enable users to restrict
folder tree access to themselves or a selected group of users, or to publish
content for anonymous access.


REQUIREMENTS
------------
The FolderShare module requires Drupal 8.4 or greater.

The module does not require any third-party contributed modules or libraries.
The module only requires modules included in Drupal core and already enabled by
most web sites.

Required modules in the "Core" group:
 * Field
 * Filter
 * Media
 * User
 * Views

Required modules in the "Field types" group:
 * DateTime
 * File
 * Image
 * Link
 * Options
 * Text


RECOMMENDED MODULES
-------------------
The FolderShare module will provide additional features if any of these
Drupal core modules are enabled:

 * Comment
 * Help
 * RESTful Web Services
 * Search
 * Serialization

Site administrators that wish to customize the layout of module pages and tables
may enable these Drupal core modules:

 * Field UI
 * Views UI

A few third-party modules are useful for configuring and using features
related to the FolderShare module:

 * REST UI
 * Tokens


INSTALLATION
------------
Install the module as you would normally install a contributed Drupal module.
Visit:
  https://www.drupal.org/docs/user_guide/en/config-install.html


CONFIGURATION
-------------
The FolderShare module may store its files in the site's public or private file
system. The private file system is recommended. This needs to be set up for the
site before configuring the FolderShare module. Visit:
 https://www.drupal.org/docs/8/core/modules/file/overview

After enabling the module, you may adjust module settings by selecting
"FolderShare" under the administrator's "Structure" menu. A few key items are
important to configure before creating any files or folders:

 * Select whether files are stored in the public or private file system.
 * Select a subdirectory into which to place stored files.
 * Enable or disable file name extension restrictions.

You also may wish to:

 * Enable or disable sharing.
 * Enable or disable REST services.
 * Set limits on file uploads.

If you have the Field UI module installed, you may add fields to FolderShare
files and folders and control how FolderShare pages are displayed.

If you have the View UI module installed, you may adjust the rows and columns of
file and folder lists created by FolderShare.

If you have the Comment module installed, you may adjust how comments are
attached and displayed on FolderShare files and folders.

By default the module creates a "Folders" link in the site's "Tools" menu. You
may wish to move, disable, or change this link depending upon your site's menu
structure.

The FolderShare module defines permissions that you may assign to the site's
user roles:

 * View permission lets users view file and folder content.

 * Author permission lets users create file and folder content.

 * Create & share permission lets users create top-level folders and
   share them with other users.

 * Administer permission lets content moderators access and change all
   content.

A typical site configuration enables view permission for anonymous and
authenticated users, gives author and create & share permission to
authenticated users, and gives administer permission to trusted users with the
job of moderating content.


TROUBLESHOOTING
---------------
The FolderShare module installs defaults for its fields, views, and search
features. These may be modified by a site administrator. If they are
accidentally deleted or broken, the default settings may be restored by going to
the "FolderShare" menu item under the administrator's "Structure" menu. Then
click on the appropriate "Restore original settings" button to:

 * Restore form display settings
 * Restore field display settings
 * Restore the allowed file name extension list
 * Restore views
 * Restore search settings


MAINTAINERS
-----------
Current maintainers:
 * David Nadeau
   San Diego Supercomputer Center
   University of California at San Diego

This project has been sponsored by:
 * The National Science Foundation. See NSF.txt
