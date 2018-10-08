<?php

use Kodus\Sentry\Event;
use Kodus\Sentry\HttpClient;
use Kodus\Sentry\SentryClient;
use Nyholm\Psr7\ServerRequest;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * This provides a fixture for stack-trace test-cases
 */
class TraceFixture
{
    public function outer($arg)
    {
        try {
            $this->inner($arg);
        } catch (Exception $inner) {
            throw new Exception("from outer: {$arg}", 0, $inner);
        }
    }

    protected function inner($arg)
    {
        $closure = function () use ($arg) {
            throw new Exception("from inner: {$arg}");
        };

        $closure();
    }
}

function exception_with($arg): Exception {
    $fixture = new TraceFixture();

    try {
        $fixture->outer($arg);
    } catch (Exception $exception) {
        return $exception;
    }
}

/**
 * This model represents an HTTP Request for testing purposes
 */
class Request
{
    /**
     * @var string
     */
    public $body;

    /**
     * @var string[]
     */
    public $headers;

    /**
     * @param string   $body
     * @param string[] $headers
     */
    public function __construct(string $body, array $headers)
    {
        $this->body = $body;
        $this->headers = $headers;
    }
}

/**
 * Mock HTTP client that merely buffers Requests for inspection
 */
class MockHttpClient extends HttpClient
{
    /**
     * @var Request[]
     */
    public $requests = [];

    public function fetch(string $method, string $url, string $body, array $headers = []): string
    {
        $this->requests[] = new Request($body, $headers);

        return "";
    }
}

class MockSentryClient extends SentryClient
{
    const EVENT_ID = "a1f1cddefbd54085822f50ef14c7c9a8";

    const DSN = "https://a1f1cddefbd54085822f50ef14c7c9a8@sentry.io/1292571";

    public $time = 1538738714;

    protected function createTimestamp(): int
    {
        return $this->time;
    }

    protected function createEventID(): string
    {
        return self::EVENT_ID;
    }
}

test(
    "can send HTTP request",
    function () {
        return; // TODO

        $client = new HttpClient();

        $data = ["hello" => "world"];

        $url = "https://postman-echo.com/post";

        $response = $client->fetch(
            "POST",
            $url,
            json_encode($data),
            [
                "Content-Type: application/json",
                "Accept: application/json",
                "x-hello: world",
            ]
        );

        $response_data = json_decode($response, true);

        eq($response_data["json"], $data, "can send HTTP POST request");

        eq($response_data["headers"]["x-hello"], "world", "can post HTTP headers");
    }
);

test(
    "can capture Exception",
    function () {
        $http = new MockHttpClient();

        $client = new MockSentryClient(MockSentryClient::DSN, $http);

        $client->captureException(exception_with("ouch"));

        eq(count($http->requests), 1, "it performs a request");

        $EVENT_ID = MockSentryClient::EVENT_ID;

        $TIMESTAMP = $client->time;

        $EXPECTED_HEADERS = [
            "Accept: application/json",
            "Content-Type: application/json",
            "X-Sentry-Auth: Sentry sentry_version=7, sentry_timestamp={$TIMESTAMP}, sentry_key={$EVENT_ID}, sentry_client=kodus-sentry/1.0",
        ];

        eq($http->requests[0]->headers, $EXPECTED_HEADERS, "it submits the expected headers");

        $body = json_decode($http->requests[0]->body, true);

        eq($body["event_id"], $EVENT_ID);
        eq($body["timestamp"], gmdate(Event::DATE_FORMAT, $TIMESTAMP));
        eq($body["platform"], "php");

        echo json_encode(json_decode($http->requests[0]->body, true), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
);

//test(
//    "can capture Request details",
//    function () {
//        $http = new MockHttpClient();
//
//        $client = new MockSentryClient(MockSentryClient::DSN, $http);
//
//        $request = new ServerRequest(
//            "POST",
//            "https://example.com/hello",
//            ["Content-Type" => "application/json"],
//            '{"foo":"bar"}'
//        );
//
//        $client->captureException(new RuntimeException("ouch"), $request);
//
//        echo json_encode(json_decode($http->requests[0]->body, true), JSON_PRETTY_PRINT);
//    }
//);

//$client = new SentryClient("https://a1f1cddefbd54085822f50ef14c7c9a8@sentry.io/1292571");
//
//$request = new ServerRequest(
//    "POST",
//    "https://example.com/hello",
//    [
//        "Content-Type" => "application/json",
//        "User-Agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36",
//    ],
//    '{"foo":"bar"}'
//);
//
//$client->captureException(exception_with("ouch"), $request);

exit(run());
