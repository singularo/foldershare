<?php

namespace Drupal\foldershare\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Drupal\Component\Utility\Unicode;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use Drupal\foldershare\FileUtilities;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Defines a class to handle downloading a file in a folder.
 *
 * This download controller is used by the FolderShareStream wrapper for
 * external URLs to access individual files managed by FolderShare.
 * The controller is used for both private and public file systems in
 * order to do access control checks before download.
 *
 * This class has two entry points:
 *
 * - show() sends the file with an HTTP header that allows the browser to
 *   present the file, if it knows how.
 *
 * - download() sends the file with an HTTP header that browsers interpret
 *   as a file download. Browsers may present a 'save-as' dialog, or default
 *   to saving the file in a well-known 'downloads' folder on the user's
 *   system.
 *
 * @ingroup foldershare
 */
class FileDownload extends ControllerBase {

  /*--------------------------------------------------------------------
   *
   * Download.
   *
   *--------------------------------------------------------------------*/

  /**
   * Sends and possibly shows the file by transfering the file in binary.
   *
   * The file is sent with a custom HTTP header that includes the full
   * human-readable name of the file and its MIME type. Browsers that
   * can display the MIME type will typically show the file themselves.
   * If they do not know the MIME type, they will usually present the user
   * with the option to save the file.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object that contains the entity ID of the
   *   file being requested. The entity ID is included in the URL
   *   for links to the file.
   * @param \Drupal\file\Entity\File $file
   *   (optional) The file object to download, parsed from the URL by using an
   *   embedded entity ID. If the entity ID is not valid, the function
   *   receives a NULL argument.  NOTE:  Because this function is the
   *   target of a route with a file argument, the name of the function
   *   argument here *must be* named after the argument name: 'file'.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   A binary transfer response is returned to send the file to the
   *   user's browser.
   *
   * @throws \Symfony\Component\HttpKernel\Exxception\AccessDeniedHttpException
   *   Thrown when the user does not have access to the file.
   *
   * @throws \Symfony\Component\HttpKernel\Exxception\NotFoundHttpException
   *   Thrown when the entity ID is invalid, for a file not managed
   *   by this module, or any other access problem occurs.
   */
  public function show(
    Request $request,
    File $file = NULL) {
    return $this->download($request, $file, 'show');
  }

  /**
   * Downloads the file by transfering the file in binary.
   *
   * The file is sent with a custom HTTP header that includes the full
   * human-readable name of the file and its MIME type. If the $style argument
   * is "show", the file is sent so that a browser may display the file
   * directly. If the $style argument is "download" (the default), the file
   * is sent with a special HTTP header to encourage the browser to save
   * the file instead of displaying it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object that contains the entity ID of the
   *   file being requested. The entity ID is included in the URL
   *   for links to the file.
   * @param \Drupal\file\Entity\File $file
   *   (optional) The file object to download, parsed from the URL by using an
   *   embedded entity ID. If the entity ID is not valid, the function
   *   receives a NULL argument.  NOTE:  Because this function is the
   *   target of a route with a file argument, the name of the function
   *   argument here *must be* named after the argument name: 'file'.
   * @param string $style
   *   (optional) Whether to send the file with the correct MIME type,
   *   or force a browser download and save-as by using a generic MIME type.
   *   The value is 'send' to send with a MIME type, and 'download' to
   *   send without one.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   A binary transfer response is returned to send the file to the
   *   user's browser.
   *
   * @throws \Symfony\Component\HttpKernel\Exxception\AccessDeniedHttpException
   *   Thrown when the user does not have access to the file.
   *
   * @throws \Symfony\Component\HttpKernel\Exxception\NotFoundHttpException
   *   Thrown when the entity ID is invalid, for a file not managed
   *   by this module, or any other access problem occurs.
   */
  public function download(
    Request $request,
    File $file = NULL,
    string $style = 'download') {

    //
    // Validate arguments
    // ------------------
    // Make sure the file argument loaded.
    if ($file === NULL) {
      throw new NotFoundHttpException();
    }

    // Make sure the file is in a folder.
    $folderId = FolderShare::getFileParentFolderId($file);
    if ($folderId === -1) {
      throw new NotFoundHttpException();
    }

    // Make sure the folder is loadable.
    $folder = FolderShare::load($folderId);
    if ($folder === NULL) {
      throw new NotFoundHttpException();
    }

    //
    // Validate permission
    // -------------------
    // Make sure the folder allows VIEW operations for this user.
    if ($folder->access('view') === FALSE) {
      throw new AccessDeniedHttpException();
    }

    //
    // Prepare to download
    // -------------------
    // Get the file to download.
    $uri      = $file->getFileUri();
    $filename = $file->getFilename();
    $mimeType = Unicode::mimeHeaderEncode($file->getMimeType());
    $realPath = FileUtilities::realpath($uri);

    if ($realPath === FALSE || file_exists($realPath) === FALSE) {
      throw new NotFoundHttpException();
    }

    $filesize = FileUtilities::filesize($realPath);

    //
    // Build header
    // ------------
    // Build an HTTP header for the file by getting the user-visible
    // file name and MIME type. Both of these are essential in the HTTP
    // header since they tell the browser what type of file it is getting,
    // and the name of the file if the user wants to save it their disk.
    //
    // Get the content disposition.
    if ($style === 'show') {
      // To send the file to the browser and enable the browser to display
      // the file directly, just give the file name in the content
      // disposition.
      $disposition = 'filename="' . $filename . '"';
    }
    else {
      // To encourage the browser to download and save the file, without
      // displaying it, mark the file as an attachment.
      $disposition = 'attachment; filename="' . $filename . '"';
    }

    $headers = [
      // Use the File object's MIME type.
      'Content-Type'        => $mimeType,

      // Use the human-visible file name.
      'Content-Disposition' => $disposition,

      // Use the saved file size, in bytes.
      'Content-Length'      => $filesize,

      // To be safe, transfer everything in binary. This will work
      // for text-based files as well.
      'Content-Transfer-Encoding' => 'binary',

      // Don't cache the file because permissions and content may
      // change.
      'Pragma'              => 'no-cache',
      'Cache-Control'       => 'must-revalidate, post-check=0, pore-check=0',
      'Expires'             => '0',
      'Accept-Ranges'       => 'bytes',
    ];

    //
    // Invoke hooks
    // ------------
    // For a private scheme only, invoke the file_download hooks to
    // allow other modules to override the headers.
    $scheme = Settings::getFileScheme();
    $isPrivate = ($scheme == 'private');
    if ($isPrivate === TRUE) {
      $override = $this->moduleHandler()->invokeAll('file_download', [$uri]);
      $headers = array_merge($headers, $override);
    }

    //
    // Respond
    // -------
    // \Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()
    // sets response as not cacheable if the Cache-Control header is not
    // already modified. We pass in FALSE for non-private schemes for the
    // $public parameter to make sure we don't change the headers.
    return new BinaryFileResponse($uri, 200, $headers, !$isPrivate);
  }

}
