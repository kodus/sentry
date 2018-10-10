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

    public function captureEvent(Event $event): void
    {
        parent::captureEvent($event);
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

        eq($body["transaction"], __FILE__ . "#19", "can capture 'transaction' (filename and line-number)");

        eq($body["tags"]["server_name"], php_uname("n"), "reports local server-name in a tag");

        preg_match("#^\d+(\.\d+){2}#", PHP_VERSION, $version);

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
                    "version"         => $version[0],
                    "raw_description" => phpversion(),
                ],
            ],
            "defines basic OS and PHP run-time contexts"
        );

        eq(count($body["exception"]["values"]), 2, "can capture nested Exceptions");

        $inner = $body["exception"]["values"][0];

        eq($inner["type"], Exception::class, "can capture exception type");

        eq($inner["value"], "from inner: ouch", "can capture exception value (message)");

        $inner_frames = array_slice($inner["stacktrace"]["frames"], -4);

        eq($inner_frames[0]["filename"], __FILE__, "can capture filename");

        eq($inner_frames[0]["function"], TraceFixture::class . "->outer", "can capture function-references");
        eq($inner_frames[1]["function"], TraceFixture::class . "->inner");
        eq($inner_frames[2]["function"], TraceFixture::class . "->{closure}");
        eq($inner_frames[3]["filename"], __FILE__, "call site does not specify a function");

        eq($inner_frames[0]["lineno"], 37, "can capture line-numbers");
        eq($inner_frames[1]["lineno"], 17);
        eq($inner_frames[2]["lineno"], 29);
        eq($inner_frames[3]["lineno"], 26, "can capture line-number of failed call-site");

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

        $outer_frames = array_slice($outer["stacktrace"]["frames"], -3);

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

//        (new SentryClient(MockSentryClient::DSN))->captureException(new RuntimeException("boom"), $request);

//        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
);

test(
    "can capture browser context",
    function () {
        $user_agents = [
            "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36"                                                                                                                                                           => "chrome/50.0.2661.102/Linux",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36"                                                                                                                                                                => "chrome/41.0.2228.0/Windows 7",
            "Mozilla/5.0 (iPhone; U; CPU iPhone OS 5_1_1 like Mac OS X; en) AppleWebKit/534.46.0 (KHTML, like Gecko) CriOS/19.0.1084.60 Mobile/9B206 Safari/7534.48.3"                                                                                                            => "crios/19.0.1084.60/iOS",
            "Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:46.0) Gecko/20100101 Firefox/46.0"                                                                                                                                                                                        => "firefox/46.0/Linux",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:40.0) Gecko/20100101 Firefox/40.1"                                                                                                                                                                                            => "firefox/40.1/Windows 7",
            "Mozilla/5.0 (iPad; CPU iPhone OS 8_3 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) FxiOS/1.0 Mobile/12F69 Safari/600.1.4"                                                                                                                                   => "fxios/1.0/iOS",
            "Mozilla/5.0 (iPad; CPU iPhone OS 8_3 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) FxiOS/3.2 Mobile/12F69 Safari/600.1.4"                                                                                                                                   => "fxios/3.2/iOS",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36 Edge/12.246"                                                                                                                                     => "edge/12.246/Windows 10",
            "Mozilla/5.0 (Windows NT 6.3; Win64, x64; Touch) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.71 Safari/537.36 Edge/12.0 (Touch; Trident/7.0; .NET4.0E; .NET4.0C; .NET CLR 3.5.30729; .NET CLR 2.0.50727; .NET CLR 3.0.30729; HPNTDFJS; H9P; InfoPath"     => "edge/12.0/Windows 8.1",
            "Mozilla/5.0 (Windows NT 6.3; WOW64; Trident/7.0; .NET4.0E; .NET4.0C; rv:11.0) like Gecko"                                                                                                                                                                            => "ie/11.0/Windows 8.1",
            "Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0; MSN 11.61; MSNbMSNI; MSNmen-us; MSNcOTH) like Gecko"                                                                                                                                                      => "ie/11.0/Windows 10",
            "Mozilla/5.0 (compatible; MSIE 10.6; Windows NT 6.1; Trident/5.0; InfoPath.2; SLCC1; .NET CLR 3.0.4506.2152; .NET CLR 3.5.30729; .NET CLR 2.0.50727) 3gpp-gba UNTRUSTED/1.0"                                                                                          => "ie/10.6/Windows 7",
            "Mozilla/5.0 (compatible; MSIE 7.0; Windows NT 5.2; WOW64; .NET CLR 2.0.50727)"                                                                                                                                                                                       => "ie/7.0/Windows Server 2003",
            "Opera/9.80 (J2ME/MIDP; Opera Mini/5.0 (Windows; U; Windows NT 5.1; en) AppleWebKit/886; U; en) Presto/2.4.15"                                                                                                                                                        => "opera/9.80/Windows XP",
            "Opera/9.25 (Macintosh; Intel Mac OS X; U; en)"                                                                                                                                                                                                                       => "opera/9.25/Mac OS",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.84 Safari/537.36 OPR/38.0.2220.31"                                                                                                                           => "opera/38.0.2220.31/Mac OS",
            "Mozilla/5.0 (BB10; Touch) AppleWebKit/537.10+ (KHTML, like Gecko) Version/7.2.0.0 Mobile Safari/537.10+"                                                                                                                                                             => "bb10/7.2.0.0/BlackBerry OS",
            "Mozilla/5.0 (Linux; U; Android 4.0.3; ko-kr; LG-L160L Build/IML74K) AppleWebkit/534.30 (KHTML, like Gecko) Version/4.0 Mobile Safari/534.30"                                                                                                                         => "android/4.0.3/Android OS",
            "Mozilla/5.0 (iPad; CPU OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A5355d Safari/8536.25"                                                                                                                                      => "ios/6.0/iOS",
            "Mozilla/5.0 (iPod; U; CPU iPhone OS 4_3_3 like Mac OS X; ja-jp) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5"                                                                                                                  => "ios/5.0.2/iOS",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_3) AppleWebKit/537.75.14 (KHTML, like Gecko) Version/7.0.3 Safari/7046A194A"                                                                                                                                             => "safari/7.0.3/Mac OS",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.116 YaBrowser/16.10.0.2774 Safari/537.36"                                                                                                                    => "yandexbrowser/16.10.0.2774/Mac OS",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36 Vivaldi/1.2.490.43"                                                                                                                        => "vivaldi/1.2.490.43/Mac OS",
            "Netscape 5.0 (iPhone; CPU iPhone OS 10_3 1 like Mac OS X) AppleWebKit/603.1.30 (KHTML, like Gecko) Mobile/14E304 KAKAOTALK 6.2.2"                                                                                                                                    => "kakaotalk/6.2.2/iOS",
            "Mozilla/5.0 (iPhone; CPU iPhone OS  10_3 1 like Mac OS X) AppleWebKit/  603.1.30 (KHTML, like Gecko) Mobile/ 14E304 KAKAOTALK 6.2.2"                                                                                                                                 => "kakaotalk/6.2.2/iOS",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X) AppleWebKit/538.1 (KHTML, like Gecko) PhantomJS/2.1.1 Safari/538.1"                                                                                                                                                          => "phantomjs/2.1.1/Mac OS",
            "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2841.00 Safari/537.36 AOLShield/54.0.2848.0"                                                                                                                                 => "aol/54.0.2848.0/Windows 10",
            "Mozilla/5.0 (iPhone; CPU iPhone OS 11_2_5 like Mac OS X) AppleWebKit/604.5.6 (KHTML, like Gecko) Mobile/15D60 [FBAN/FBIOS;FBAV/157.0.0.42.96;FBBV/90008621;FBDV/iPhone9,1;FBMD/iPhone;FBSN/iOS;FBSV/11.2.5;FBSS/2;FBCR/Verizon;FBID/phone;FBLC/en_US;FBOP/5;FBRV/0]" => "facebook/157.0.0.42.96/iOS",
            "Mozilla/5.0 (iPhone; CPU iPhone OS 9_3_2 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Mobile/13F69 Instagram 8.4.0 (iPhone7,2; iPhone OS 9_3_2; nb_NO; nb-NO; scale=2.00; 750x1334"                                                                       => "instagram/8.4.0/iOS",
            "User-Agent: Mozilla/5.0 (iPad; U; CPU OS 4_3_2 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Mobile"                                                                                                                                                => "ios-webview/533.17.9/iOS",
            "Mozilla/5.0 (iPad; CPU OS 11_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E216"                                                                                                                                                                => "ios-webview/605.1.15/iOS",
            "Mozilla/5.0 (Linux; Android 5.0.2; SAMSUNG SM-G925F Build/LRX22G) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/4.0 Chrome/44.0.2403.133 Mobile Safari/537.36"                                                                                               => "samsung/4.0/Android OS",
            "Mozilla/5.0 (compatible; AhrefsBot/5.2; +http://ahrefs.com/robot/)"                                                                                                                                                                                                  => "bot/ahrefsbot",
            "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Safari/537.36"                                                                                                                                        => "bot/googlebot",
            "Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)"                                                                                                                                                                                                    => "bot/yandex",
            "Snagglepuss"                                                                                                                                                                                                                                                         => "unknown",
        ];

        foreach ($user_agents as $user_agent => $expected) {
            $client = new MockSentryClient();

            $request = new ServerRequest(
                "POST",
                "https://example.com/hello",
                [
                    "Content-Type" => "application/json",
                    "User-Agent" => $user_agent,
                ]
            );

            $client->captureException(new RuntimeException("boom"), $request);

            $body = json_decode($client->requests[0]->body, true);

            @list($expected_browser, $expected_version, $expected_os) = explode("/", $expected);

            if ($expected_browser === "bot") {
                eq(
                    $body["contexts"]["browser"],
                    [
                        "name" => "{$expected_browser}/{$expected_version}"
                    ],
                    "can capture bot name for: {$user_agent}"
                );
            } else {
                eq(
                    $body["contexts"]["browser"],
                    [
                        "name"    => $expected_browser,
                        "version" => $expected_browser === "unknown"
                            ? $user_agent // unknown agents fall back to the full User-Agent string
                            : "{$expected_version}/{$expected_os}", // all known agents specify version/OS
                    ],
                    "applies browser name, version and OS for: {$user_agent}"
                );
            }
        }
    }
);

exit(run());
