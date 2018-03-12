<?php

namespace Drupal\foldershare\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Unicode;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use Drupal\foldershare\FileUtilities;
use Drupal\foldershare\Settings;
use Drupal\foldershare\Entity\FolderShare;

/**
 * Defines a class to handle downloading one or more FolderShare entities.
 *
 * <B>Warning:</B> This class is strictly internal to the FolderShare
 * module. The class's existance, name, and content may change from
 * release to release without any promise of backwards compatability.
 *
 * This class handles translating stored FolderShare content into a byte
 * stream to download to a browser via HTTP. It handles two cases:
 *
 * - Download a single FolderShare entity for a file.
 *
 * - Download one or more FolderShare entities for files and folders.
 *
 * In both cases, FolderShare entities for files wrap File entities, which
 * in turn reference stored files. For security reasons, those files are
 * stored with numeric file names and no extensions. To send them to a browser,
 * these files have to be processed to send their human-readable names,
 * file extensions, and MIME types so that a browser or client OS knows what
 * to do with them.
 *
 * When downloading a single FolderShare entity for a file, the file's data
 * is retrieved and sent to the browser as a file attachment.
 *
 * When downloading a single FolderShare entity for a folder, or multiple
 * FolderShare entities, the entities are compressed into a temporary ZIP
 * archive, and that archive's data sent to the browser as a file
 * attachment.
 *
 * @ingroup foldershare
 */
class FolderShareDownload extends ControllerBase {

  /*--------------------------------------------------------------------
   *
   * Constants.
   *
   *--------------------------------------------------------------------*/

  /**
   * The name of the ZIP archive downloaded for groups of entities.
   *
   * @var string
   */
  const DOWNLOAD_NAME = 'Download.zip';

  /*--------------------------------------------------------------------
   *
   * Download.
   *
   *--------------------------------------------------------------------*/

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
   * @param string $encoded
   *   A string containing a comma-separated list of entity IDs.
   *   NOTE: Because this function is the target of a route with a string
   *   argument, the name of the function argument here *must be* named
   *   after the argument name: 'encoded'.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   A binary transfer response is returned to send the file to the
   *   user's browser.
   *
   * @throws \Symfony\Component\HttpKernel\Exxception\AccessDeniedHttpException
   *   Thrown when the user does not have access to the entities.
   *
   * @throws \Symfony\Component\HttpKernel\Exxception\NotFoundHttpException
   *   Thrown if the URL argument is empty or malformed, if any entity ID
   *   in that encoded argument is invalid, if the entities don't all have
   *   the same parent, if the file's those entities refer to cannot be
   *   found, or if a ZIP archive of those entities could not be created.
   */
  public function download(
    Request $request,
    string $encoded = NULL) {

    //
    // Validate arguments
    // ------------------
    // Make sure the file argument loaded.
    if (empty($encoded) === TRUE) {
      throw new NotFoundHttpException();
    }

    // Decode into an array of FolderShare entity IDs.
    $entityIds = explode(',',$encoded);

    if (empty($entityIds) === TRUE) {
      throw new NotFoundHttpException();
    }

    // Load all of those entities and make sure the user has access
    // permission. Insure that all entities have the same parent.
    $entities = FolderShare::loadMultiple($entityIds);
    $parentId = (-1);
    foreach ($entities as $entity) {
      if ($entity === NULL) {
        throw new NotFoundHttpException();
      }

      if ($entity->access('view') === FALSE) {
        throw new AccessDeniedHttpException();
      }

      if ($parentId === (-1)) {
        $parentId = $entity->getParentFolderId();
      }
      elseif ($parentId !== $entity->getParentFolderId()) {
        throw new NotFoundHttpException();
      }
    }

    //
    // Prepare to download
    // -------------------
    // Get the file to download, creating it if needed.
    //
    // Note that downloading Media objects is not supported.
    $entity = reset($entities);
    if (count($entities) === 1 &&
        ($entity->isFile() === TRUE || $entity->isImage() === TRUE)) {
      // Download a single file.
      //
      // Get the file's URI, human-readable name, MIME type, and size.
      if ($entity->isFile() === TRUE) {
        $file = $entity->getFile();
      }
      else {
        $file = $entity->getImage();
      }

      $uri      = $file->getFileUri();
      $filename = $file->getFilename();
      $mimeType = Unicode::mimeHeaderEncode($file->getMimeType());
      $realPath = FileUtilities::realpath($uri);

      if ($realPath === FALSE || file_exists($realPath) === FALSE) {
        throw new NotFoundHttpException();
      }

      $filesize = FileUtilities::filesize($realPath);
    }
    else {
      // Download multiple files and/or folders. Create a ZIP archive.
      try {
        $uri      = FolderShare::createZipArchive($entities);
        $filename = self::DOWNLOAD_NAME;
        $filesize = FileUtilities::filesize($uri);
        $mimeType = Unicode::mimeHeaderEncode(
          FileUtilities::guessMimeType($filename));
      }
      catch (\Exception $e) {
        throw new NotFoundHttpException();
      }
    }

    //
    // Build header
    // ------------
    // Build an HTTP header for the file by getting the user-visible
    // file name and MIME type. Both of these are essential in the HTTP
    // header since they tell the browser what type of file it is getting,
    // and the name of the file if the user wants to save it their disk.
    $headers = [
      // Use the File object's MIME type.
      'Content-Type'        => $mimeType,

      // Use the human-visible file name.
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',

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

    $scheme = Settings::getFileScheme();
    $isPrivate = ($scheme == 'private');

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
