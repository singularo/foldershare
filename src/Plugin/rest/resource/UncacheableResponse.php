<?php

namespace Drupal\foldershare\Plugin\rest\resource;

use Drupal\rest\ResourceResponseInterface;
use Drupal\rest\ResourceResponseTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contains data for serialization before sending an uncacheable response.
 *
 * The REST module's default ResourceResponse is nearly identical, but it
 * also implements CacheableResponseInterface. Responses that implement
 * this interface always trigger the addition of a Drupal cacheable dependency
 * after the response is returned by a REST resource. This enables Drupal
 * to cache the response, keyed to the request's parameters. This is
 * unfortunate since it leaves no way to handle responses that should not
 * be cached.
 *
 * This class manages a response that is *not* to be cached within Drupal.
 */
class UncacheableResponse extends Response implements ResourceResponseInterface {
  use ResourceResponseTrait;

  /*--------------------------------------------------------------------
   *
   * Construction.
   *
   *--------------------------------------------------------------------*/

  /**
   * Constructs an UncacheableResponse used for REST responses.
   *
   * @param mixed $data
   *   (optional, default = NULL) The response's data that should be serialized.
   * @param int $status
   *   (optional, default = 200) The response's HTTP status code.
   * @param array $headers
   *   (optional, default = []) The response's HTTP headers.
   */
  public function __construct($data = NULL, int $status = 200, array $headers = []) {
    $this->responseData = $data;
    parent::__construct('', $status, $headers);
  }

}
