<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\ArrayContainsComparator\Matcher;

use Imbo\BehatApiExtension\ArrayContainsComparator;
use Imbo\BehatApiExtension\ArrayContainsComparator\Exception\ArrayContainsComparatorException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\BehatApiExtension\ArrayContainsComparator\Matcher\JWT
 * @testdox JWT matcher
 */
class JWTTest extends TestCase
{
    /**
     * @var JWT
     */
    private $matcher;

    /**
     * Set up matcher instance
     */
    public function setup(): void
    {
        $this->matcher = new JWT(new ArrayContainsComparator());
    }

    /**
     * Data provider
     *
     * @return array[]
     */
    public function getJwt()
    {
        return [
            [
                'jwt' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiYWRtaW4iOnRydWV9.TJVA95OrM7E2cBab30RMHrHDcEfxjoYZgeFONFh7HgQ',
                'name' => 'my jwt',
                'payload' => [
                    'sub' => '1234567890',
                    'name' => 'John Doe',
                    'admin' => true,
                ],
                'secret' => 'secret',
            ],
            [
                'jwt' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJmb28iOiJiYXIifQ.xnzcLUO-0DuBw9Do3JqtQPyclUpJtdPSG8B8GsglLJAn-hMH-NIQD5eoMbctwEGrkL5bvynD8PZ5kq-sGJTIlg',
                'name' => 'my other jwt',
                'payload' => [
                    'foo' => 'bar',
                ],
                'secret' => 'supersecret',
            ],
        ];
    }

    /**
     *
     *
     * @covers ::__invoke
     */
    public function testThrowsExceptionWhenMatchingAgainstJwtThatDoesNotExist()
    {
        $this->expectExceptionMessage("No JWT registered for \"some name\".");
        $this->expectException(InvalidArgumentException::class);
        $matcher = $this->matcher;
        $matcher('some jwt', 'some name');
    }

    /**
     *
     * @expectedExceptionMessage Haystack object is missing the "some" key.
     * @covers ::addToken
     * @covers ::__invoke
     */
    public function testThrowsExceptionWhenJwtDoesNotMatch()
    {
        $this->expectException(Imbo\BehatApiExtension\Exception\ArrayContainsComparatorException::class);
        $matcher = $this->matcher->addToken('some name', ['some' => 'data'], 'secret', 'HS256');
        $matcher(
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiYWRtaW4iOnRydWV9.TJVA95OrM7E2cBab30RMHrHDcEfxjoYZgeFONFh7HgQ',
            'some name'
        );
    }

    /**
     * @covers ::__invoke
     * @dataProvider getJwt
     *
     * @param string $jwt
     * @param string $name
     * @param array $payload
     * @param string $secret
     */
    public function testCanMatchJwt($jwt, $name, array $payload, $secret)
    {
        $matcher = $this->matcher->addToken($name, $payload, $secret);
        $matcher(
            $jwt,
            $name
        );
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage JWT mismatch.
     * @covers ::__construct
     * @covers ::__invoke
     */
    public function testThrowsExceptionWhenComparatorDoesNotReturnSuccess()
    {
        $comparator = $this->createConfiguredMock(
            ArrayContainsComparator::class,
            [
                'compare' => false,
            ]
        );
        $matcher = (new JWT($comparator))->addToken(
            'token',
            [
                'sub' => '1234567890',
                'name' => 'John Doe',
                'admin' => true,
            ],
            'secret'
        );

        $matcher(
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiYWRtaW4iOnRydWV9.TJVA95OrM7E2cBab30RMHrHDcEfxjoYZgeFONFh7HgQ',
            'token'
        );
    }
}
