<?php

namespace Drupal\foldershare\Entity\Exception;

/**
 * Defines an exception indicating a content validation problem.
 *
 * In addition to standard exception parameters (such as the message),
 * a validation exception includes an optional item number that indicates
 * when the exception applies to a specific item in a list of items.
 *
 * @ingroup foldershare
 */
class ValidationException extends \RuntimeException {
  /*--------------------------------------------------------------------
   * Fields
   *--------------------------------------------------------------------*/

  /**
   * The optional item number to which this exception applies.
   *
   * @var int
   */
  private $itemNumber = -1;

  /*--------------------------------------------------------------------
   * Methods
   *--------------------------------------------------------------------*/

  /**
   * Returns the item number.
   *
   * This may be used to indicate when an exception applies to a single
   * faulty item in a list of items.
   *
   * @return int
   *   The list index of the item causing the exception, or a -1
   *   if an index is not pertinent.
   */
  public function getItemNumber() {
    return $this->itemNumber;
  }

  /**
   * Sets the item number.
   *
   * This may be used to indicate when an exception applies to a single
   * faulty item in a list of items.
   *
   * @param int $num
   *   The list index of the item causing the exception, or a -1
   *   if an index is not pertinent.
   */
  public function setItemNumber(int $num) {
    $this->itemNumber = (($num <= (-1)) ? (-1) : $num);
  }

}
