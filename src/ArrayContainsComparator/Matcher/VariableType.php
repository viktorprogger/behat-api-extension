<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\ArrayContainsComparator\Matcher;

use InvalidArgumentException;

/**
 * Match the type of a value
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 */
class VariableType
{
    /**
     * Valid types
     *
     * @var string[]
     */
    protected const VALID_TYPES = [
        'int',
        'integer',
        'bool',
        'boolean',
        'float',
        'double',
        'string',
        'array',
        'object',
        'null',
        'scalar',
    ];

    /**
     * Match a variable type
     *
     * @param mixed $variable A variable
     * @param string $expectedType The expected type of $variable
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function __invoke($variable, string $expectedType)
    {
        $expectedType = $this->normalizeType($expectedType);

        if (!in_array($expectedType, self::VALID_TYPES)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported variable type: "%s".',
                    $expectedType
                )
            );
        }

        if ($expectedType === 'scalar' && is_scalar($variable)) {
            return;
        }

        // Encode / decode the value to easier check for objects
        $variable = json_decode(json_encode($variable, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        // Get the actual type of the value
        $actualType = strtolower(gettype($variable));

        if ($expectedType !== $actualType) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected variable type "%s", got "%s".',
                    $expectedType,
                    $actualType
                )
            );
        }
    }

    /**
     * Normalize the type
     *
     * @param string $type The type from the scenario
     *
     * @return string Returns a normalized type
     */
    protected function normalizeType(string $type): string
    {
        return strtolower(
            preg_replace(
                ['/^bool$/i', '/^int$/i', '/^float$/i'],
                ['boolean', 'integer', 'double'],
                $type
            )
        );
    }
}
