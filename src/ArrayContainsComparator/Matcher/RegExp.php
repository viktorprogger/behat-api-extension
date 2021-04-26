<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\ArrayContainsComparator\Matcher;

use InvalidArgumentException;

/**
 * Match a string against a regular expression pattern
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 */
class RegExp
{
    /**
     * Match the value of a string against a regular expression
     *
     * @param string|int|float $subject A string, integer or floating point value
     * @param string $pattern A valid regular expression pattern
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function __invoke($subject, string $pattern)
    {
        if (!in_array(gettype($subject), ['string', 'integer', 'double'])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Regular expression matching can only be applied to strings, integers or doubles, got "%s".',
                    gettype($subject)
                )
            );
        }

        $subject = (string) $subject;

        if (!preg_match($pattern, $subject)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Subject "%s" did not match pattern "%s".',
                    $subject,
                    $pattern
                )
            );
        }
    }
}
