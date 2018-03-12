<?php

namespace Drupal\foldershare\Entity\Exception;

/**
 * Defines an exception that wraps a list of other exceptions.
 *
 * The exception contains an array of exceptions that each have an
 * associated item key. While the item key can be anything, it is often
 * a numeric index into a list passed to an operation that needed to
 * act upon each item in the list. When some items prove to be a problem,
 * those item indexes are used as keys here, along with an associated
 * exception reporting the problem with the item.
 *
 * @ingroup foldershare
 */
class MultiException extends \RuntimeException {
  /**
   * An array of exceptions.
   *
   * Array keys indicate the item causing the exception, and values
   * are the exeception objects.
   *
   * The exception array may be accessed directly to append or loop
   * over the recorded exceptions.
   *
   * @var \RuntimeException[]
   */
  public $itemExceptions = [];

}
