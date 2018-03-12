<?php

namespace Drupal\foldershare\Entity\Exception;

/**
 * Defines an exception indicating a system error.
 *
 * System errors are probably catastrophic and are best handled by
 * stopping whatever is going on.
 *
 * @ingroup foldershare
 */
class SystemException extends \RuntimeException {

}
