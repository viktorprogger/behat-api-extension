<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\Exception;

use Exception;
use JsonException;

/**
 * Array contains comparator exception
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 */
class ArrayContainsComparatorException extends AssertionFailedException {
    /**
     * Class constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param Exception|null $previous Previous exception in the stack
     * @param mixed|null $needle The needle in the comparison
     * @param mixed|null $haystack The haystack in the comparison
     *
     * @throws JsonException
     */
    public function __construct(
        $message,
        $code = 0,
        ?Exception $previous = null,
        $needle = null,
        $haystack = null
    ) {
        // Format the error message
        $message .= PHP_EOL . PHP_EOL . sprintf(
            <<<MESSAGE
                ================================================================================
                = Needle =======================================================================
                ================================================================================
                %s
                
                ================================================================================
                = Haystack =====================================================================
                ================================================================================
                %s
                
                MESSAGE
            ,
            json_encode($needle, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            json_encode($haystack, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );

        parent::__construct($message, $code, $previous);
    }
}
