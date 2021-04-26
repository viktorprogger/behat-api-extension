<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\Context;

use Assert\Assertion;
use Assert\AssertionFailedException as AssertionFailure;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use Imbo\BehatApiExtension\ArrayContainsComparator;
use Imbo\BehatApiExtension\ArrayContainsComparator\Matcher\Jwt as JwtMatcher;
use Imbo\BehatApiExtension\Exception\AssertionFailedException;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;

/**
 * Behat feature context that can be used to simplify testing of JSON-based RESTful HTTP APIs
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 */
class ApiContext implements ApiClientAwareContext, ArrayContainsComparatorAwareContext, SnippetAcceptingContext
{
    /**
     * Guzzle client
     *
     * @var ClientInterface
     */
    protected ClientInterface $client;

    /**
     * Request instance
     *
     * The request instance will be created once the client is ready to send it.
     *
     * @var RequestInterface
     */
    protected RequestInterface $request;

    /**
     * Request options
     *
     * Options to send with the request.
     *
     * @var array
     */
    protected array $requestOptions = [];

    /**
     * Response instance
     *
     * The response object will be set once the request has been made.
     *
     * @var ResponseInterface|null
     */
    protected ?ResponseInterface $response = null;

    /**
     * Instance of the comparator that handles matching of JSON
     *
     * @var ArrayContainsComparator
     */
    protected ArrayContainsComparator $arrayContainsComparator;

    /**
     * Does HTTP method has been manually set
     *
     * @var bool
     */
    protected bool $forceHttpMethod = false;

    /**
     * {@inheritdoc}
     */
    public function setClient(ClientInterface $client): self
    {
        $this->client = $client;
        $this->request = new Request('GET', $client->getConfig('base_uri'));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setArrayContainsComparator(ArrayContainsComparator $comparator): self
    {
        $this->arrayContainsComparator = $comparator;

        return $this;
    }

    /**
     * Attach a file to the request
     *
     * @param string $path Path to the image to add to the request
     * @param string $partName Multipart entry name
     *
     * @return self
     *
     * @Given I attach :path to the request as :partName
     * @throws InvalidArgumentException If the $path does not point to a file, an exception is
     *                                  thrown
     */
    public function addMultipartFileToRequest(string $path, string $partName): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(sprintf('File does not exist: "%s"', $path));
        }

        return $this->addMultipartPart(
            [
                'name' => $partName,
                'contents' => fopen($path, 'rb'),
                'filename' => basename($path),
            ]
        );
    }

    /**
     * Add an element to the multipart array
     *
     * @param array $part The part to add
     *
     * @return self
     */
    private function addMultipartPart(array $part): self
    {
        if (!isset($this->requestOptions['multipart'])) {
            $this->requestOptions['multipart'] = [];
        }

        $this->requestOptions['multipart'][] = $part;

        return $this;
    }

    /**
     * Add multipart form parameters to the request
     *
     * @param TableNode $table Table with name / value pairs
     *
     * @return self
     *
     * @Given the following multipart form parameters are set:
     */
    public function setRequestMultipartFormParams(TableNode $table): self
    {
        foreach ($table as $row) {
            $this->addMultipartPart(
                [
                    'name' => $row['name'],
                    'contents' => $row['value'],
                ]
            );
        }

        return $this;
    }

    /**
     * Set basic authentication information for the next request
     *
     * @param string $username The username to authenticate with
     * @param string $password The password to authenticate with
     *
     * @return self
     *
     * @Given I am authenticating as :username with password :password
     */
    public function setBasicAuth(string $username, string $password): self
    {
        $this->requestOptions['auth'] = [$username, $password];

        return $this;
    }

    /**
     * Set a HTTP request header
     *
     * If the header already exists it will be overwritten
     *
     * @param string $header The header name
     * @param string $value The header value
     *
     * @return self
     *
     * @Given the :header request header is :value
     */
    public function setRequestHeader(string $header, string $value): self
    {
        $this->request = $this->request->withHeader($header, $value);

        return $this;
    }

    /**
     * Set/add a HTTP request header
     *
     * If the header already exists it will be converted to an array
     *
     * @param string $header The header name
     * @param string $value The header value
     *
     * @return self
     *
     * @Given the :header request header contains :value
     */
    public function addRequestHeader(string $header, string $value): self
    {
        $this->request = $this->request->withAddedHeader($header, $value);

        return $this;
    }

    /**
     * Set form parameters
     *
     * @param TableNode $table Table with name / value pairs
     *
     * @return self
     *
     * @Given the following form parameters are set:
     */
    public function setRequestFormParams(TableNode $table): self
    {
        if (!isset($this->requestOptions['form_params'])) {
            $this->requestOptions['form_params'] = [];
        }

        foreach ($table as $row) {
            $name = $row['name'];
            $value = $row['value'];

            if (isset($this->requestOptions['form_params'][$name]) && !is_array(
                    $this->requestOptions['form_params'][$name]
                )) {
                $this->requestOptions['form_params'][$name] = [$this->requestOptions['form_params'][$name]];
            }

            if (isset($this->requestOptions['form_params'][$name])) {
                $this->requestOptions['form_params'][$name][] = $value;
            } else {
                $this->requestOptions['form_params'][$name] = $value;
            }
        }

        return $this;
    }

    /**
     * Set the request body to a string
     *
     * @param resource|string|PyStringNode $string The content to set as the request body
     *
     * @return self
     *
     * @Given the request body is:
     * @throws InvalidArgumentException If form_params or multipart is used in the request options
     *                                  an exception will be thrown as these can't be combined.
     */
    public function setRequestBody($string): self
    {
        if (!empty($this->requestOptions['multipart']) || !empty($this->requestOptions['form_params'])) {
            throw new InvalidArgumentException(
                'It\'s not allowed to set a request body when using multipart/form-data or form parameters.'
            );
        }

        $this->request = $this->request->withBody(Psr7\stream_for($string));

        return $this;
    }

    /**
     * Set the request body to a read-only resource pointing to a file
     *
     * This step will open a read-only resource to $path and attach it to the request body. If the
     * file does not exist or is not readable the method will end up throwing an exception. The
     * method will also set the Content-Type request header. mime_content_type() is used to get the
     * mime type of the file.
     *
     * @param string $path Path to a file
     *
     * @return self
     *
     * @Given the request body contains :path
     * @throws InvalidArgumentException
     */
    public function setRequestBodyToFileResource(string $path): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(sprintf('File does not exist: "%s"', $path));
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('File is not readable: "%s"', $path));
        }

        // Set the Content-Type request header and the request body
        return $this
            ->setRequestHeader('Content-Type', mime_content_type($path))
            ->setRequestBody(fopen($path, 'rb'));
    }

    /**
     * Add a JWT token to the matcher
     *
     * @param string $name String identifying the token
     * @param string $secret The secret used to sign the token
     * @param PyStringNode $payload The payload for the JWT
     *
     * @return self
     *
     * @Given the response body contains a JWT identified by :name, signed with :secret:
     * @throws RuntimeException
     */
    public function addJwtToken(string $name, string $secret, PyStringNode $payload): self
    {
        $jwtMatcher = $this->arrayContainsComparator->getMatcherFunction('jwt');

        if (!($jwtMatcher instanceof JwtMatcher)) {
            throw new RuntimeException(
                sprintf(
                    'Matcher registered for the @jwt() matcher function must be an instance of %s',
                    JwtMatcher::class
                )
            );
        }

        $jwtMatcher->addToken($name, $this->jsonDecode((string) $payload), $secret);

        return $this;
    }

    /**
     * Request a path
     *
     * @param string $path The path to request
     * @param string|null $method The HTTP method to use
     *
     * @return self
     *
     * @When I request :path
     * @When I request :path using HTTP :method
     */
    public function requestPath(string $path, ?string $method = null): self
    {
        $this->setRequestPath($path);

        if (null === $method) {
            $this->setRequestMethod('GET', false);
        } else {
            $this->setRequestMethod($method);
        }

        return $this->sendRequest();
    }

    /**
     * Assert the HTTP response code
     *
     * @param int $code The HTTP response code
     *
     * @return void
     *
     * @Then the response code is :code
     * @throws AssertionFailedException
     */
    public function assertResponseCodeIs(int $code): void
    {
        $this->requireResponse();

        try {
            Assertion::same(
                $actual = $this->response->getStatusCode(),
                $expected = $this->validateResponseCode($code),
                sprintf('Expected response code %d, got %d.', $expected, $actual)
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert the HTTP response code is not a specific code
     *
     * @param int $code The HTTP response code
     *
     * @return void
     *
     * @Then the response code is not :code
     * @throws AssertionFailedException
     */
    public function assertResponseCodeIsNot(int $code): void
    {
        $this->requireResponse();

        try {
            Assertion::notSame(
                $actual = $this->response->getStatusCode(),
                $this->validateResponseCode($code),
                sprintf('Did not expect response code %d.', $actual)
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the HTTP response reason phrase equals a given value
     *
     * @param string $phrase Expected HTTP response reason phrase
     *
     * @return void
     *
     * @Then the response reason phrase is :phrase
     * @throws AssertionFailedException
     */
    public function assertResponseReasonPhraseIs(string $phrase): void
    {
        $this->requireResponse();

        try {
            Assertion::same(
                $phrase,
                $actual = $this->response->getReasonPhrase(),
                sprintf(
                    'Expected response reason phrase "%s", got "%s".',
                    $phrase,
                    $actual
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the HTTP response reason phrase does not equal a given value
     *
     * @param string $phrase Reason phrase that the HTTP response should not equal
     *
     * @return void
     *
     * @Then the response reason phrase is not :phrase
     * @throws AssertionFailedException
     */
    public function assertResponseReasonPhraseIsNot(string $phrase): void
    {
        $this->requireResponse();

        try {
            Assertion::notSame(
                $phrase,
                $this->response->getReasonPhrase(),
                sprintf(
                    'Did not expect response reason phrase "%s".',
                    $phrase
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the HTTP response reason phrase matches a regular expression
     *
     * @param string $pattern Regular expression pattern
     *
     * @return void
     *
     * @Then the response reason phrase matches :expression
     * @throws AssertionFailedException
     */
    public function assertResponseReasonPhraseMatches(string $pattern): void
    {
        $this->requireResponse();

        try {
            Assertion::regex(
                $actual = $this->response->getReasonPhrase(),
                $pattern,
                sprintf(
                    'Expected the response reason phrase to match the regular expression "%s", got "%s".',
                    $pattern,
                    $actual
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the HTTP response status line equals a given value
     *
     * @param string $line Expected HTTP response status line
     *
     * @return void
     *
     * @Then the response status line is :line
     * @throws AssertionFailedException
     */
    public function assertResponseStatusLineIs(string $line): void
    {
        $this->requireResponse();

        try {
            $actualStatusLine = sprintf(
                '%d %s',
                $this->response->getStatusCode(),
                $this->response->getReasonPhrase()
            );

            Assertion::same(
                $line,
                $actualStatusLine,
                sprintf(
                    'Expected response status line "%s", got "%s".',
                    $line,
                    $actualStatusLine
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the HTTP response status line does not equal a given value
     *
     * @param string $line Value that the HTTP response status line must not equal
     *
     * @return void
     *
     * @Then the response status line is not :line
     * @throws AssertionFailedException
     */
    public function assertResponseStatusLineIsNot(string $line): void
    {
        $this->requireResponse();

        try {
            $actualStatusLine = sprintf(
                '%d %s',
                $this->response->getStatusCode(),
                $this->response->getReasonPhrase()
            );

            Assertion::notSame(
                $line,
                $actualStatusLine,
                sprintf(
                    'Did not expect response status line "%s".',
                    $line
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the HTTP response status line matches a regular expression
     *
     * @param string $pattern Regular expression pattern
     *
     * @return void
     *
     * @Then the response status line matches :expression
     * @throws AssertionFailedException
     */
    public function assertResponseStatusLineMatches(string $pattern): void
    {
        $this->requireResponse();

        try {
            $actualStatusLine = sprintf(
                '%d %s',
                $this->response->getStatusCode(),
                $this->response->getReasonPhrase()
            );

            Assertion::regex(
                $actualStatusLine,
                $pattern,
                sprintf(
                    'Expected the response status line to match the regular expression "%s", got "%s".',
                    $pattern,
                    $actualStatusLine
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Checks if the HTTP response code is in a group
     *
     * Allowed groups are:
     *
     * - informational
     * - success
     * - redirection
     * - client error
     * - server error
     *
     * @param string $group Name of the group that the response code should be in
     *
     * @return void
     *
     * @Then the response is :group
     * @throws AssertionFailedException
     */
    public function assertResponseIs(string $group): void
    {
        $this->requireResponse();
        $range = $this->getResponseCodeGroupRange($group);
        $code = $this->response->getStatusCode();

        try {
            Assertion::range($code, $range['min'], $range['max']);
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException(
                sprintf(
                    'Expected response group "%s", got "%s" (response code: %d).',
                    $group,
                    $this->getResponseGroup($code),
                    $code
                )
            );
        }
    }

    /**
     * Checks if the HTTP response code is *not* in a group
     *
     * Allowed groups are:
     *
     * - informational
     * - success
     * - redirection
     * - client error
     * - server error
     *
     * @param string $group Name of the group that the response code is not in
     *
     * @return void
     *
     * @Then the response is not :group
     * @throws AssertionFailedException
     */
    public function assertResponseIsNot(string $group): void
    {
        try {
            $this->assertResponseIs($group);
        } catch (AssertionFailedException $e) {
            // As expected, return
            return;
        }

        throw new AssertionFailedException(
            sprintf(
                'Did not expect response to be in the "%s" group (response code: %d).',
                $group,
                $this->response->getStatusCode()
            )
        );
    }

    /**
     * Assert that a response header exists
     *
     * @param string $header Then name of the header
     *
     * @return void
     *
     * @Then the :header response header exists
     * @throws AssertionFailedException
     */
    public function assertResponseHeaderExists(string $header): void
    {
        $this->requireResponse();

        try {
            Assertion::true(
                $this->response->hasHeader($header),
                sprintf('The "%s" response header does not exist.', $header)
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that a response header does not exist
     *
     * @param string $header Then name of the header
     *
     * @return void
     *
     * @Then the :header response header does not exist
     * @throws AssertionFailedException
     */
    public function assertResponseHeaderDoesNotExist(string $header): void
    {
        $this->requireResponse();

        try {
            Assertion::false(
                $this->response->hasHeader($header),
                sprintf('The "%s" response header should not exist.', $header)
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Compare a response header value against a string
     *
     * @param string $header The name of the header
     * @param string $value The value to compare with
     *
     * @return void
     *
     * @Then the :header response header is :value
     * @throws AssertionFailedException
     */
    public function assertResponseHeaderIs(string $header, string $value): void
    {
        $this->requireResponse();

        try {
            Assertion::same(
                $actual = $this->response->getHeaderLine($header),
                $value,
                sprintf(
                    'Expected the "%s" response header to be "%s", got "%s".',
                    $header,
                    $value,
                    $actual
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that a response header is not a value
     *
     * @param string $header The name of the header
     * @param string $value The value to compare with
     *
     * @return void
     *
     * @Then the :header response header is not :value
     * @throws AssertionFailedException
     */
    public function assertResponseHeaderIsNot(string $header, string $value): void
    {
        $this->requireResponse();

        try {
            Assertion::notSame(
                $this->response->getHeaderLine($header),
                $value,
                sprintf(
                    'Did not expect the "%s" response header to be "%s".',
                    $header,
                    $value
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Match a response header value against a regular expression pattern
     *
     * @param string $header The name of the header
     * @param string $pattern The regular expression pattern
     *
     * @return void
     *
     * @Then the :header response header matches :pattern
     * @throws AssertionFailedException
     */
    public function assertResponseHeaderMatches(string $header, string $pattern): void
    {
        $this->requireResponse();

        try {
            Assertion::regex(
                $actual = $this->response->getHeaderLine($header),
                $pattern,
                sprintf(
                    'Expected the "%s" response header to match the regular expression "%s", got "%s".',
                    $header,
                    $pattern,
                    $actual
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body contains an empty JSON object
     *
     * @return void
     *
     * @Then the response body is an empty JSON object
     * @throws AssertionFailedException
     */
    public function assertResponseBodyIsAnEmptyJsonObject(): void
    {
        $this->requireResponse();
        $body = $this->getResponseBody();

        $encoded = $this->jsonEncode($body, 'Expected response body to be an empty JSON object, got "%s".');

        try {
            Assertion::isInstanceOf($body, 'stdClass', 'Expected response body to be a JSON object.');
            Assertion::same(
                '{}',
                $encoded,
                sprintf(
                    'Expected response body to be an empty JSON object, got "%s".',
                    $encoded
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body contains an empty JSON array
     *
     * @return void
     *
     * @Then the response body is an empty JSON array
     * @throws AssertionFailedException
     */
    public function assertResponseBodyIsAnEmptyJsonArray(): void
    {
        $this->requireResponse();

        try {
            Assertion::same(
                [],
                $body = $this->getResponseBodyArray(),
                sprintf(
                    'Expected response body to be an empty JSON array, got "%s".',
                    $this->jsonEncode($body)
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body contains an array with a specific length
     *
     * @param int $length The length of the array
     *
     * @return void
     *
     * @Then the response body is a JSON array of length :length
     * @throws AssertionFailedException
     */
    public function assertResponseBodyJsonArrayLength(int $length): void
    {
        $this->requireResponse();

        try {
            Assertion::count(
                $body = $this->getResponseBodyArray(),
                $length,
                sprintf(
                    'Expected response body to be a JSON array with %d entr%s, got %d: "%s".',
                    $length,
                    $length === 1 ? 'y' : 'ies',
                    count($body),
                    $this->jsonEncode($body)
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body contains an array with a length of at least a given length
     *
     * @param int $length The length to use in the assertion
     *
     * @return void
     *
     * @Then the response body is a JSON array with a length of at least :length
     * @throws AssertionFailedException
     */
    public function assertResponseBodyJsonArrayMinLength(int $length): void
    {
        $this->requireResponse();
        $body = $this->getResponseBodyArray();

        try {
            Assertion::min(
                $bodyLength = count($body),
                $length,
                sprintf(
                    'Expected response body to be a JSON array with at least %d entr%s, got %d: "%s".',
                    $length,
                    $length === 1 ? 'y' : 'ies',
                    $bodyLength,
                    $this->jsonEncode($body)
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body contains an array with a length of at most a given length
     *
     * @param int $length The length to use in the assertion
     *
     * @return void
     *
     * @Then the response body is a JSON array with a length of at most :length
     * @throws AssertionFailedException
     */
    public function assertResponseBodyJsonArrayMaxLength(int $length): void
    {
        $this->requireResponse();
        $body = $this->getResponseBodyArray();

        try {
            Assertion::max(
                $bodyLength = count($body),
                $length,
                sprintf(
                    'Expected response body to be a JSON array with at most %d entr%s, got %d: "%s".',
                    $length,
                    $length === 1 ? 'y' : 'ies',
                    $bodyLength,
                    $this->jsonEncode($body)
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }


    /**
     * Assert that the response body matches some content
     *
     * @param PyStringNode $node The content to match the response body against
     *
     * @return void
     *
     * @Then the response body is:
     * @throws AssertionFailedException
     */
    public function assertResponseBodyIs(PyStringNode $node): void
    {
        $this->requireResponse();
        $content = (string) $node;

        try {
            Assertion::same(
                $body = (string) $this->response->getBody(),
                $content,
                sprintf(
                    'Expected response body "%s", got "%s".',
                    $content,
                    $body
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body does not match some content
     *
     * @param PyStringNode $node The content that the response body should not match
     *
     * @return void
     *
     * @Then the response body is not:
     * @throws AssertionFailedException
     */
    public function assertResponseBodyIsNot(PyStringNode $node): void
    {
        $this->requireResponse();
        $content = (string) $node;

        try {
            Assertion::notSame(
                (string) $this->response->getBody(),
                $content,
                sprintf(
                    'Did not expect response body to be "%s".',
                    $content
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body matches some content using a regular expression
     *
     * @param PyStringNode $node The regular expression pattern to use for the match
     *
     * @return void
     *
     * @Then the response body matches:
     * @throws AssertionFailedException
     */
    public function assertResponseBodyMatches(PyStringNode $node): void
    {
        $this->requireResponse();
        $pattern = (string) $node;

        try {
            Assertion::regex(
                $body = (string) $this->response->getBody(),
                $pattern,
                sprintf(
                    'Expected response body to match regular expression "%s", got "%s".',
                    $pattern,
                    $body
                )
            );
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException($e->getMessage());
        }
    }

    /**
     * Assert that the response body contains all keys / values in the parameter
     *
     * @param PyStringNode $node
     *
     * @return void
     *
     * @Then the response body contains JSON:
     * @throws AssertionFailedException
     */
    public function assertResponseBodyContainsJson(PyStringNode $node): void
    {
        $this->requireResponse();

        // Decode the parameter to the step as an array and make sure it's valid JSON
        $contains = $this->jsonDecode((string) $node);

        // Get the decoded response body and make sure it's decoded to an array
        $body = $this->jsonDecode($this->jsonEncode($this->getResponseBody()));

        try {
            // Compare the arrays, on error this will throw an exception
            Assertion::true($this->arrayContainsComparator->compare($contains, $body));
        } catch (AssertionFailure $e) {
            throw new AssertionFailedException(
                'Comparator did not return in a correct manner. Marking assertion as failed.'
            );
        }
    }

    /**
     * Send the current request and set the response instance
     *
     * @return self
     * @throws RequestException
     */
    protected function sendRequest(): self
    {
        if (!empty($this->requestOptions['form_params']) && !$this->forceHttpMethod) {
            $this->setRequestMethod('POST');
        }

        if (!empty($this->requestOptions['multipart']) && !empty($this->requestOptions['form_params'])) {
            // We have both multipart and form_params set in the request options. Take all
            // form_params and add them to the multipart part of the option array as it's not
            // allowed to have both.
            foreach ($this->requestOptions['form_params'] as $name => $contents) {
                if (is_array($contents)) {
                    // The contents is an array, so use array notation for the part name and store
                    // all values under this name
                    $name .= '[]';

                    foreach ($contents as $content) {
                        $this->requestOptions['multipart'][] = [
                            'name' => $name,
                            'contents' => $content,
                        ];
                    }
                } else {
                    $this->requestOptions['multipart'][] = [
                        'name' => $name,
                        'contents' => $contents,
                    ];
                }
            }

            // Remove form_params from the options, otherwise Guzzle will throw an exception
            unset($this->requestOptions['form_params']);
        }

        try {
            $this->response = $this->client->send(
                $this->request,
                $this->requestOptions
            );
        } catch (RequestException $e) {
            $this->response = $e->getResponse();

            if (!$this->response) {
                throw $e;
            }
        }

        return $this;
    }

    /**
     * Require a response object
     *
     * @throws RuntimeException
     */
    protected function requireResponse(): void
    {
        if (!$this->response) {
            throw new RuntimeException('The request has not been made yet, so no response object exists.');
        }
    }

    /**
     * Get the min and max values for a response body group
     *
     * @param string $group The name of the group
     *
     * @return array An array with two keys, min and max, which represents the min and max values
     *               for $group
     * @throws InvalidArgumentException
     */
    protected function getResponseCodeGroupRange(string $group): array
    {
        switch ($group) {
            case 'informational':
                $min = 100;
                $max = 199;
                break;
            case 'success':
                $min = 200;
                $max = 299;
                break;
            case 'redirection':
                $min = 300;
                $max = 399;
                break;
            case 'client error':
                $min = 400;
                $max = 499;
                break;
            case 'server error':
                $min = 500;
                $max = 599;
                break;
            default:
                throw new InvalidArgumentException(sprintf('Invalid response code group: %s', $group));
        }

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Get the "response group" based on a status code
     *
     * @param int $code The response code
     *
     * @return string
     */
    protected function getResponseGroup(int $code): string
    {
        switch (true) {
            case $code >= 500:
                return 'server error';
            case $code >= 400:
                return 'client error';
            case $code >= 300:
                return 'redirection';
            case $code >= 200:
                return 'success';
            default:
                return 'informational';
        }
    }

    /**
     * Validate a response code
     *
     * @param int $code
     *
     * @return int
     * @throws InvalidArgumentException
     */
    protected function validateResponseCode(int $code): int
    {
        try {
            Assertion::range(
                $code,
                100,
                599,
                sprintf('Response code must be between 100 and 599, got %d.', $code)
            );
        } catch (AssertionFailure $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        return $code;
    }

    /**
     * Update the path of the request
     *
     * @param string $path The path to request
     *
     * @return self
     */
    protected function setRequestPath(string $path): self
    {
        // Resolve the path with the base_uri set in the client
        $uri = Psr7\Uri::resolve($this->client->getConfig('base_uri'), Psr7\uri_for($path));
        $this->request = $this->request->withUri($uri);

        return $this;
    }

    /**
     * Update the HTTP method of the request
     *
     * @param string $method The HTTP method
     * @param boolean $force Force the HTTP method. If set to false the method set CAN be
     *                       overridden (this occurs for instance when adding form parameters to the
     *                       request, and not specifying HTTP POST for the request)
     *
     * @return self
     */
    protected function setRequestMethod(string $method, bool $force = true): self
    {
        $this->request = $this->request->withMethod($method);
        $this->forceHttpMethod = $force;

        return $this;
    }

    /**
     * Get the JSON-encoded array or stdClass from the response body
     *
     * @return array|stdClass
     * @throws InvalidArgumentException
     */
    protected function getResponseBody()
    {
        $body = $this->jsonDecode(
            (string) $this->response->getBody(),
            'The response body does not contain valid JSON data.'
        );

        if (!is_array($body) && !($body instanceof stdClass)) {
            throw new InvalidArgumentException('The response body does not contain a valid JSON array / object.');
        }

        return $body;
    }

    /**
     * Get the response body as an array
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function getResponseBodyArray(): array
    {
        if (!is_array($body = $this->getResponseBody())) {
            throw new InvalidArgumentException('The response body does not contain a valid JSON array.');
        }

        return $body;
    }

    /**
     * Convert some variable to a JSON-array
     *
     * @param string $value The value to decode
     * @param string|null $errorMessage Optional error message
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function jsonDecode(string $value, ?string $errorMessage = null): array
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                $errorMessage ?: 'The supplied parameter is not a valid JSON object.'
            );
        }
    }

    /**
     * @param array|stdClass $body
     * @param string|null $message
     *
     * @return false|string
     * @throws AssertionFailedException
     */
    private function jsonEncode($body, string $message = 'Expected a json-encodable parameter, got "%s"')
    {
        try {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new AssertionFailedException(
                strpos($message, '%s') === false
                    ? $message
                    : sprintf(
                    $message,
                    is_object($body) ? get_class($body) : var_export($body)
                )
            );
        }

        return $encoded;
    }
}
