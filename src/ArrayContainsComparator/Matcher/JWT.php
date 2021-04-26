<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\ArrayContainsComparator\Matcher;

use Firebase;
use Imbo\BehatApiExtension\ArrayContainsComparator as Comparator;
use InvalidArgumentException;

/**
 * Match a JWT token
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 */
class JWT
{
    /**
     * Comparator for the array
     *
     * @var Comparator
     */
    private Comparator $comparator;

    /**
     * JWT tokens present in the response body
     *
     * @var array
     */
    private array $jwtTokens = [];

    /**
     * Allowed algorithms for the JWT decoder
     *
     * @var string[]
     */
    protected array $allowedAlgorithms = [
        'HS256',
        'HS384',
        'HS512',
    ];

    /**
     * Class constructor
     *
     * @param Comparator $comparator
     */
    public function __construct(Comparator $comparator)
    {
        $this->comparator = $comparator;
    }

    /**
     * Add a JWT token that can be matched
     *
     * @param string $name String identifying the token
     * @param array $payload The payload
     * @param string $secret The secret used to sign the token
     *
     * @return self
     */
    public function addToken(string $name, array $payload, string $secret): self
    {
        $this->jwtTokens[$name] = [
            'payload' => $payload,
            'secret' => $secret,
        ];

        return $this;
    }

    /**
     * Match an array against a JWT
     *
     * @param string $jwt The encoded JWT
     * @param string $name The identifier of the decoded data, added using the addToken method
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function __invoke(string $jwt, string $name): void
    {
        if (!isset($this->jwtTokens[$name])) {
            throw new InvalidArgumentException(sprintf('No JWT registered for "%s".', $name));
        }

        $token = $this->jwtTokens[$name];
        $result = (array) Firebase\JWT\JWT::decode($jwt, $token['secret'], $this->allowedAlgorithms);

        if (!$this->comparator->compare($token['payload'], $result)) {
            throw new InvalidArgumentException('JWT mismatch.');
        }
    }
}
