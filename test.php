<?php

use Kodus\Sentry\Event;
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

class ClassFixture
{
    public function instanceMethod()
    {
        // nothing here.
    }

    public static function staticMethod()
    {
        // nothing here.
    }
}

class InvokableClassFixture
{
    public function __invoke()
    {
        // nothing here.
    }
}

function empty_closure() {
    return function () {};
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

class MockSentryClient extends SentryClient
{
    const EVENT_ID = "a1f1cddefbd54085822f50ef14c7c9a8";

    const DSN = "https://a1f1cddefbd54085822f50ef14c7c9a8@sentry.io/1292571";

    public $time = 1538738714;

    public function __construct()
    {
        parent::__construct(self::DSN);
    }

    /**
     * @var Request[]
     */
    public $requests = [];

    protected function createTimestamp(): int
    {
        return $this->time;
    }

    protected function createEventID(): string
    {
        return self::EVENT_ID;
    }

    protected function fetch(string $method, string $url, string $body, array $headers = []): string
    {
        $this->requests[] = new Request($body, $headers);

        return "";
    }

    public function testFetch(string $method, string $url, string $body, array $headers): string
    {
        return parent::fetch($method, $url, $body, $headers);
    }

    public function testFormat($value): string
    {
        return $this->formatValue($value);
    }
}

test(
    "can send HTTP request",
    function () {
        return; // TODO

        $client = new MockSentryClient();

        $data = ["hello" => "world"];

        $url = "https://postman-echo.com/post";

        $response = $client->testFetch(
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
    "can format captured values",
    function () {
        $client = new MockSentryClient();

        $file = fopen("php://temp", "rw+"); // open file resources are should be recognized as "stream" types

        eq($client->testFormat([1, 2, 3]), "array[3]");
        eq($client->testFormat(['foo' => 'bar', 'baz' => 'bat']), 'array[2]');
        eq($client->testFormat(true), "true");
        eq($client->testFormat(false), "false");
        eq($client->testFormat(null), "null");
        eq($client->testFormat(123), "123");
        eq($client->testFormat(0.42), "0.42");
        eq($client->testFormat(0.12345678), "~0.123457");
        eq($client->testFormat("hello"), '"hello"');
        eq($client->testFormat("hell\"o"), '"hell\"o"');
        eq($client->testFormat(new \stdClass()), '{object}');
        eq($client->testFormat(new ClassFixture()), '{' . ClassFixture::class . '}');
        eq($client->testFormat([new ClassFixture(), 'instanceMethod']), '{' . ClassFixture::class . '}->instanceMethod()');
        eq($client->testFormat(['ClassFixture', 'staticMethod']), ClassFixture::class . '::staticMethod()');
        eq($client->testFormat(empty_closure()), '{Closure in ' . __FILE__ . '(65)}');
        eq($client->testFormat(new InvokableClassFixture()), '{' . InvokableClassFixture::class . '}');

        eq($client->testFormat($file), '{stream}', "reports open streams as '{stream}'");

        fclose($file);

        eq($client->testFormat($file), '{unknown type}', "reports closed streams as '{unknown type}'");
    }
);

test(
    "can capture Exception",
    function () {
        $client = new MockSentryClient();

        $client->captureException(exception_with("ouch"));

        eq(count($client->requests), 1, "it performs a request");

        $EVENT_ID = MockSentryClient::EVENT_ID;

        $TIMESTAMP = $client->time;

        $EXPECTED_HEADERS = [
            "Accept: application/json",
            "Content-Type: application/json",
            "X-Sentry-Auth: Sentry sentry_version=7, sentry_timestamp={$TIMESTAMP}, sentry_key={$EVENT_ID}, sentry_client=kodus-sentry/1.0",
        ];

        eq($client->requests[0]->headers, $EXPECTED_HEADERS, "it submits the expected headers");

        $body = json_decode($client->requests[0]->body, true);

        eq($body["event_id"], $EVENT_ID);

        eq($body["timestamp"], gmdate(Event::DATE_FORMAT, $TIMESTAMP));

        eq($body["platform"], "php");

        eq($body["level"], "error");

        eq($body["message"], "from outer: ouch", "can capture Exception message");

        eq($body["tags"]["server_name"], php_uname("n"), "reports local server-name in a tag");

        eq(
            $body["contexts"],
            [
                "os"      => [
                    "name"    => php_uname("s"),
                    "version" => php_uname("v"),
                    "build"   => php_uname("r"),
                ],
                "runtime" => [
                    "name"            => "php",
                    "version"         => PHP_VERSION,
                    "raw_description" => phpversion(),
                ],
            ],
            "defines basic OS and PHP run-time contexts"
        );

        eq(count($body["exception"]["values"]), 2, "can capture nested Exceptions");

        $inner = $body["exception"]["values"][0];

        eq($inner["type"], Exception::class, "can capture exception type");

        eq($inner["value"], "from inner: ouch", "can capture exception value (message)");

        $inner_frames = array_slice($inner["stacktrace"]["frames"], -3);

        eq($inner_frames[0]["filename"], __FILE__, "can capture filename");

        eq($inner_frames[0]["function"], TraceFixture::class . "->outer", "can capture function-references");
        eq($inner_frames[1]["function"], TraceFixture::class . "->inner");
        eq($inner_frames[2]["function"], TraceFixture::class . "->{closure}");

        eq($inner_frames[0]["lineno"], 37, "can capture line-numbers");
        eq($inner_frames[1]["lineno"], 17);
        eq($inner_frames[2]["lineno"], 29);

        eq(
            $inner_frames[0]["context_line"],
            '        $fixture->outer($arg);',
            "can capture context line"
        );

        eq(
            $inner_frames[0]["pre_context"],
            [
                '',
                'function exception_with($arg): Exception {',
                '    $fixture = new TraceFixture();',
                '',
                '    try {'
            ],
            "can capture pre_context"
        );

        eq(
            $inner_frames[0]["post_context"],
            [
                '    } catch (Exception $exception) {',
                '        return $exception;',
                '    }',
                '}',
                ''
            ],
            "can capture post_context"
        );

        eq($inner_frames[0]["vars"], ['$arg' => '"ouch"'], "can capture arguments");

        $outer = $body["exception"]["values"][1];

        $outer_frames = array_slice($outer["stacktrace"]["frames"], -2);

        eq($outer_frames[0]["function"], "exception_with", "can capture stack-trace of inner Exception");
        eq($outer_frames[1]["function"], TraceFixture::class . "->outer");

//        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
);

test(
    "can capture Request details",
    function () {
        $client = new MockSentryClient();

        $USER_AGENT_STRING = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36";

        $request = new ServerRequest(
            "POST",
            "https://example.com/hello",
            [
                "Content-Type" => "application/json",
                "User-Agent" => $USER_AGENT_STRING,
            ],
            '{"foo":"bar"}'
        );

        $client->captureException(new RuntimeException("boom"), $request);

        $body = json_decode($client->requests[0]->body, true);

        eq($body["tags"]["site"], "example.com", "can capture domain-name (site) from Request");

        eq(
            $body["contexts"]["browser"],
            [
                "version" => $USER_AGENT_STRING,
            ],
            "can capture browser context"
        );

        eq(
            $body["request"],
            [
                "url" => "https://example.com/hello",
                "method" => "POST",
                "headers" => [
                    "Host"         => "example.com",
                    "Content-Type" => "application/json",
                    "User-Agent" => $USER_AGENT_STRING,
                ],
            ],
            "can capture Request information"
        );

//        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
);

exit(run());
