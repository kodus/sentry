<?php

namespace Tests\Unit;

use Codeception\Example;
use ErrorException;
use Exception;
use Kodus\Sentry\Model\BufferedEventCapture;
use Kodus\Sentry\Model\Event;
use Kodus\Sentry\Model\Level;
use Kodus\Sentry\SentryClient;
use Nyholm\Psr7\ServerRequest;
use RuntimeException;
use Tests\Fixtures\ClassFixture;
use Tests\Fixtures\InvokableClassFixture;
use Tests\Fixtures\TraceFixture;
use Tests\Mocks\MockBreadcrumbLogger;
use Tests\Mocks\MockDirectEventCapture;
use Tests\Mocks\MockDSN;
use Tests\Mocks\MockExceptionReporter;
use Tests\Mocks\MockSentryClient;
use Tests\Support\UnitTester;

class SentryClientCest
{
    private const DATA_PROVIDER_EXPECTED = 'expected';
    private const DATA_PROVIDER_ACTUAL   = 'actual';
    private const DATA_PROVIDER_REASON   = 'reason';

    public function canSendHttpRequest(UnitTester $I): void
    {
        $client = new MockDirectEventCapture(new MockDSN());

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

        $I->assertEquals($data, $response_data["json"], 'Can see request body');
        $I->assertArrayHasKey('x-hello', $response_data["headers"], 'Can see request header');
    }

    /**
     * @dataProvider captureValues
     */
    public function canFormatCapturedValues(UnitTester $I, Example $data): void
    {
        $client = new MockExceptionReporter();

        $I->assertEquals($data[self::DATA_PROVIDER_EXPECTED], $client->testFormat($data[self::DATA_PROVIDER_ACTUAL]));
    }

    public function canFormatCapturedClosure(UnitTester $I): void
    {
        $client = new MockExceptionReporter();

        $I->assertEquals(
            '{Closure in ' . implode(DIRECTORY_SEPARATOR,
                [dirname(__DIR__ . '../'), 'Support', 'UnitTester.php(49)']) . '}',
            $client->testFormat($I->createEmptyClosure()),
        );
    }

    public function canFormatCapturedResources(UnitTester $I): void
    {
        $client = new MockExceptionReporter();

        $file = fopen("php://temp", "rw+"); // open file resources are should be recognized as "stream" types
        $I->assertEquals('{stream}', $client->testFormat($file), "reports open streams as '{stream}'");

        fclose($file);
        $I->assertEquals('{unknown type}', $client->testFormat($file), "reports closed streams as '{unknown type}'");
    }

    public function canMapToSentrySeverityLevel(UnitTester $I): void
    {
        $capture = new MockDirectEventCapture();

        $client = new MockSentryClient($capture);

        $client->captureException(new ErrorException("foo", 0, E_USER_NOTICE));

        $body = json_decode($capture->requests[0]->body, true);

        $I->assertEquals('info', $body["level"]);
    }

    public function canCaptureException(UnitTester $I): void
    {
        $dsn = new MockDSN();
        $capture = new MockDirectEventCapture($dsn);
        $client = new MockSentryClient($capture, null, []);
        $timestamp = $dsn->time;
        $sentry_key = substr(MockDSN::MOCK_DSN, strlen("https://"), 32);
        preg_match("#^\d+(\.\d+){2}#", PHP_VERSION, $version);

        $expected_headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            "X-Sentry-Auth: Sentry sentry_version=7, sentry_timestamp=$timestamp, sentry_key=$sentry_key, sentry_client=kodus-sentry/" . SentryClient::VERSION,
        ];
        $expected_context = [
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
        ];

        $client->captureException($I->createExceptionWith("ouch"));

        $request = $capture->requests[0];
        $body = json_decode($request->body, true);

        $I->assertCount(1, $capture->requests, 'Request performed');
        $I->assertEquals('from outer: ouch', $body["message"], 'Can capture Exception message');
        $I->assertEquals($expected_headers, $request->headers);
        $I->assertEquals(MockSentryClient::MOCK_EVENT_ID, $body['event_id']);
        $I->assertEquals(gmdate(Event::DATE_FORMAT, $timestamp), $body['timestamp']);
        $I->assertEquals('php', $body['platform']);
        $I->assertEquals('error', $body['level']);
        $I->assertEquals(
            dirname(__DIR__ . '../') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR,
                ['Fixtures', 'TraceFixture.php#17']),
            $body["transaction"],
            "can capture 'transaction' (filename and line-number)"
        );
        $I->assertEquals(php_uname("n"), $body["tags"]["server_name"], "Reports local server-name in a tag");
        $I->assertEquals($expected_context, $body['contexts'], 'Defines basic OS and PHP run-time contexts');
    }

    public function canCaptureExceptionWithStacktraces(UnitTester $I): void
    {
        $dsn = new MockDSN();
        $capture = new MockDirectEventCapture($dsn);
        $client = new MockSentryClient($capture, null, []);

        $client->captureException($I->createExceptionWith("ouch"));

        $request = $capture->requests[0];
        $body = json_decode($request->body, true);

        $I->assertCount(2, $body["exception"]["values"], 'Can capture nested Exceptions');

        $inner = $body["exception"]["values"][0];

        $I->assertEquals(Exception::class, $inner['type']);
        $I->assertEquals('from inner: ouch', $inner['value']);

        $inner_frames = array_slice($inner["stacktrace"]["frames"], -4);

        $I->assertStringContainsString('UnitTester.php', $inner_frames[0]["filename"]);
        $I->assertEquals(
            TraceFixture::class . "->outer",
            $inner_frames[0]["function"],
            'Can capture function-references'
        );
        $I->assertEquals(
            TraceFixture::class . "->inner",
            $inner_frames[1]["function"],
            'Can capture function-references'
        );
        $I->assertEquals(
            TraceFixture::class . "->{closure:Tests\Fixtures\TraceFixture::inner():26}",
            $inner_frames[2]["function"],
            'Can capture function-references'
        );
        $I->assertStringContainsString(
            'TraceFixture.php',
            $inner_frames[3]["filename"],
            'Call site does not specify a function'
        );
        $I->assertEquals(39, $inner_frames[0]["lineno"], 'Can capture line-numbers');
        $I->assertEquals(15, $inner_frames[1]["lineno"], 'Can capture line-numbers');
        $I->assertEquals(30, $inner_frames[2]["lineno"], 'Can capture line-numbers');
        $I->assertEquals(27, $inner_frames[3]["lineno"], 'Can capture line-number of failed call-site');

        $I->assertEquals(
            [
                '    public function createExceptionWith($arg): ?Exception',
                '    {',
                '        $fixture = new TraceFixture();',
                '',
                '        try {',
            ],
            $inner_frames[0]["pre_context"],
            'Can capture pre_context'
        );
        $I->assertEquals(
            '            $fixture->outer($arg);',
            $inner_frames[0]["context_line"],
            'Can capture context_line'
        );
        $I->assertEquals(
            [
                '        } catch (Exception $exception) {',
                '            return $exception;',
                '        }',
                '',
                '        return null;',
            ],
            $inner_frames[0]["post_context"],
            'Can capture post_context'
        );

        $outer = $body["exception"]["values"][1];
        $outer_frames = array_slice($outer["stacktrace"]["frames"], -3);

        $I->assertEquals(
            UnitTester::class . '->createExceptionWith',
            $outer_frames[0]["function"],
            'Can capture stack-trace of inner Exception'
        );
        $I->assertEquals(TraceFixture::class . "->outer", $outer_frames[1]["function"]);
    }

    public function canCaptureExceptionWithoutStacktracesFromFilesMatchingAFilterPattern(UnitTester $I): void
    {
        $dsn = new MockDSN();
        $capture = new MockDirectEventCapture($dsn);
        $client = new MockSentryClient(
            $capture,
            null,
            [
                dirname(__DIR__ . '../') . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . '*.php',
            ]
        );

        $client->captureException($I->createExceptionWith("ouch"));

        $request = $capture->requests[0];
        $body = json_decode($request->body, true);

        $I->assertCount(2, $body["exception"]["values"], 'Can capture nested Exceptions');

        $inner = $body["exception"]["values"][0];

        $I->assertEquals(Exception::class, $inner['type']);
        $I->assertEquals('from inner: ouch', $inner['value']);

        $inner_frames = array_slice($inner["stacktrace"]["frames"], -4);

        $I->assertStringContainsString('UnitTester.php', $inner_frames[0]["filename"]);
        $I->assertEquals(
            TraceFixture::class . "->outer",
            $inner_frames[0]["function"],
            'Can capture function-references'
        );
        $I->assertEquals(
            TraceFixture::class . "->inner",
            $inner_frames[1]["function"],
            'Can capture function-references'
        );
        $I->assertEquals(
            TraceFixture::class . "->{closure:Tests\Fixtures\TraceFixture::inner():26}",
            $inner_frames[2]["function"],
            'Can capture function-references'
        );

        $I->assertStringContainsString(
            'TraceFixture.php',
            $inner_frames[3]["filename"],
            'Call site does not specify a function'
        );
        $I->assertEquals(39, $inner_frames[0]["lineno"], 'Can capture line-numbers');
        $I->assertEquals(15, $inner_frames[1]["lineno"], 'Can capture line-numbers');
        $I->assertEquals(30, $inner_frames[2]["lineno"], 'Can capture line-numbers');
        $I->assertEquals(27, $inner_frames[3]["lineno"], 'Can capture line-number of failed call-site');

        $I->assertFalse(isset($inner_frames[0]["pre_context"]), "Filtering removes pre_context");
        $I->assertEquals('### FILTERED FILE ###', $inner_frames[0]["context_line"], 'Filtering removes context_line');
        $I->assertFalse(isset($inner_frames[0]["post_context"]), 'filtering removes post_context');

        $outer = $body["exception"]["values"][1];
        $outer_frames = array_slice($outer["stacktrace"]["frames"], -3);

        $I->assertEquals(
            UnitTester::class . '->createExceptionWith',
            $outer_frames[0]["function"],
            'Can capture stack-trace of inner Exception'
        );
        $I->assertEquals(TraceFixture::class . "->outer", $outer_frames[1]["function"]);
    }

    public function canCaptureRequestDetails(UnitTester $I): void
    {
        $capture = new MockDirectEventCapture();
        $client = new MockSentryClient($capture);
        $user_agent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36";
        $request = new ServerRequest(
            "POST",
            "https://example.com/hello",
            [
                "Content-Type" => "application/json",
                "User-Agent"   => $user_agent,
            ],
            '{"foo":"bar"}'
        );

        $client->captureException(new RuntimeException("boom"), $request);

        $body = json_decode($capture->requests[0]->body, true);

        $I->assertEquals('example.com', $body["tags"]["site"], 'Can capture domain-name (site) from Request');
        $I->assertEquals(
            [
                "url"     => "https://example.com/hello",
                "method"  => "POST",
                "headers" => [
                    "Host"         => "example.com",
                    "Content-Type" => "application/json",
                    "User-Agent"   => $user_agent,
                ],
            ],
            $body["request"],
            'Can capture Request information'
        );
    }

    /**
     * @dataProvider regularContexts
     */
    public function canCaptureRegularBrowserContext(UnitTester $I, Example $data): void
    {
        $capture = new MockDirectEventCapture();
        $client = new MockSentryClient($capture);
        $request = new ServerRequest(
            "POST",
            "https://example.com/hello",
            [
                "Content-Type" => "application/json",
                "User-Agent"   => $data[self::DATA_PROVIDER_ACTUAL],
            ]
        );
        [$expected_browser, $expected_version, $expected_os] = explode("/", $data[self::DATA_PROVIDER_EXPECTED]);

        $client->captureException(new RuntimeException("boom"), $request);

        $body = json_decode($capture->requests[0]->body, true);

        $I->assertEquals(
            [
                "name"    => $expected_browser,
                "version" => "$expected_version/$expected_os",
            ],
            $body["contexts"]["browser"],
            "Applies browser name, version and OS for: {$data[self::DATA_PROVIDER_ACTUAL]}"
        );
    }

    /**
     * @dataProvider botContexts
     */
    public function canCaptureBotBrowserContext(UnitTester $I, Example $data): void
    {
        $capture = new MockDirectEventCapture();
        $client = new MockSentryClient($capture);
        $request = new ServerRequest(
            "POST",
            "https://example.com/hello",
            [
                "Content-Type" => "application/json",
                "User-Agent"   => $data[self::DATA_PROVIDER_ACTUAL],
            ]
        );
        [$expected_browser, $expected_version] = explode("/", $data[self::DATA_PROVIDER_EXPECTED]);

        $client->captureException(new RuntimeException("boom"), $request);

        $body = json_decode($capture->requests[0]->body, true);

        $I->assertEquals(
            [
                "name" => "$expected_browser/$expected_version",
            ],
            $body["contexts"]["browser"],
            "Can capture bot name for: {$data[self::DATA_PROVIDER_ACTUAL]}"
        );
    }

    public function canCaptureUnknownBrowserContext(UnitTester $I): void
    {
        $user_agent = 'Snagglepuss';
        $expected = [
            "name"    => 'unknown',
            "version" => $user_agent,
        ];

        $capture = new MockDirectEventCapture();
        $client = new MockSentryClient($capture);

        $request = new ServerRequest(
            "POST",
            "https://example.com/hello",
            [
                "Content-Type" => "application/json",
                "User-Agent"   => $user_agent,
            ]
        );

        $client->captureException(new RuntimeException("boom"), $request);

        $body = json_decode($capture->requests[0]->body, true);

        $I->assertEquals($expected, $body["contexts"]["browser"], "Unknown context captures full user-agent");
    }

    /**
     * @dataProvider ipAddressInfo
     */
    public function canDetectUserIpAddress(UnitTester $I, Example $data): void
    {
        $capture = new MockDirectEventCapture();

        $client = new MockSentryClient($capture);

        $request = new ServerRequest(
            "GET",
            "https://example.com/hello",
            $data[self::DATA_PROVIDER_ACTUAL]
        );

        $client->captureException(new RuntimeException("boom"), $request);

        $body = json_decode($capture->requests[0]->body, true);

        $I->assertEquals(
            $data[self::DATA_PROVIDER_EXPECTED],
            $body["user"]["ip_address"],
            "{$data[self::DATA_PROVIDER_REASON]} - headers: " . json_encode($data[self::DATA_PROVIDER_ACTUAL])
        );
    }

    public function canCaptureBreadcrumbsViaPsr3LoggerAdapter(UnitTester $I): void
    {
        $logger = new MockBreadcrumbLogger();
        $capture = new MockDirectEventCapture();
        $client = new MockSentryClient($capture, [$logger]);

        $logger->info("hello world", ["foo" => "bar"]);
        $logger->warning("Danger, Mr. Robinson!");

        $request = new ServerRequest(
            "GET",
            "https://example.com/hello"
        );

        $client->captureException(new RuntimeException("boom"), $request);

        $body = json_decode($capture->requests[0]->body, true);

        $I->assertEquals(
            [
                [
                    "timestamp" => $logger->time,
                    "level"     => Level::INFO,
                    "message"   => "[info] hello world",
                    "data"      => ["foo" => "bar"],
                ],
                [
                    "timestamp" => $logger->time,
                    "level"     => Level::WARNING,
                    "message"   => "[warning] Danger, Mr. Robinson!",
                ],
            ],
            $body["breadcrumbs"]["values"],
            "Can delegate log events to breadcrumbs"
        );
    }

    public function canBufferAndManuallyFlushEvents(UnitTester $I): void
    {
        $destination = new MockDirectEventCapture();
        $buffer = new BufferedEventCapture($destination);
        $client = new MockSentryClient($buffer);

        $client->captureException($I->createExceptionWith("boom"));
        $client->captureException($I->createExceptionWith("ouch"));

        $I->assertCount(0, $destination->requests);

        $buffer->flush();

        $I->assertCount(2, $destination->requests, "Can flush buffered Events");

        $destination->requests = [];

        $buffer->flush();

        $I->assertCount(0, $destination->requests, "Flushed events get cleared from the buffer");
    }

    protected function captureValues(): array
    {
        return [
            [
                self::DATA_PROVIDER_EXPECTED => 'array[3]',
                self::DATA_PROVIDER_ACTUAL   => [1, 2, 3],
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'array[2]',
                self::DATA_PROVIDER_ACTUAL   => ['foo' => 'bar', 'baz' => 'bat'],
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'true',
                self::DATA_PROVIDER_ACTUAL   => true,
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'false',
                self::DATA_PROVIDER_ACTUAL   => false,
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'null',
                self::DATA_PROVIDER_ACTUAL   => null,
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '123',
                self::DATA_PROVIDER_ACTUAL   => 123,
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '0.42',
                self::DATA_PROVIDER_ACTUAL   => 0.42,
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '~0.123457',
                self::DATA_PROVIDER_ACTUAL   => 0.12345678,
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '"hello"',
                self::DATA_PROVIDER_ACTUAL   => 'hello',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '"hell\"o"',
                self::DATA_PROVIDER_ACTUAL   => "hell\"o",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '{' . ClassFixture::class . '}',
                self::DATA_PROVIDER_ACTUAL   => new ClassFixture,
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '{' . ClassFixture::class . '}->instanceMethod()',
                self::DATA_PROVIDER_ACTUAL   => [new ClassFixture, 'instanceMethod'],
            ],
            [
                self::DATA_PROVIDER_EXPECTED => ClassFixture::class . '::staticMethod()',
                self::DATA_PROVIDER_ACTUAL   => [ClassFixture::class, 'staticMethod'],
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '{' . InvokableClassFixture::class . '}',
                self::DATA_PROVIDER_ACTUAL   => new InvokableClassFixture,
            ],
        ];
    }

    protected function regularContexts(): array
    {
        return [
            [
                self::DATA_PROVIDER_EXPECTED => "chrome/50.0.2661.102/Linux",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "chrome/41.0.2228.0/Windows 7",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "crios/19.0.1084.60/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPhone; U; CPU iPhone OS 5_1_1 like Mac OS X; en) AppleWebKit/534.46.0 (KHTML, like Gecko) CriOS/19.0.1084.60 Mobile/9B206 Safari/7534.48.3",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "firefox/46.0/Linux",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:46.0) Gecko/20100101 Firefox/46.0",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "firefox/40.1/Windows 7",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:40.0) Gecko/20100101 Firefox/40.1",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "fxios/1.0/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPad; CPU iPhone OS 8_3 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) FxiOS/1.0 Mobile/12F69 Safari/600.1.4",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "fxios/3.2/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPad; CPU iPhone OS 8_3 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) FxiOS/3.2 Mobile/12F69 Safari/600.1.4",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "edge/12.246/Windows 10",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36 Edge/12.246",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "edge/12.0/Windows 8.1",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Windows NT 6.3; Win64, x64; Touch) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.71 Safari/537.36 Edge/12.0 (Touch; Trident/7.0; .NET4.0E; .NET4.0C; .NET CLR 3.5.30729; .NET CLR 2.0.50727; .NET CLR 3.0.30729; HPNTDFJS; H9P; InfoPath",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ie/11.0/Windows 8.1",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Windows NT 6.3; WOW64; Trident/7.0; .NET4.0E; .NET4.0C; rv:11.0) like Gecko",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ie/11.0/Windows 10",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0; MSN 11.61; MSNbMSNI; MSNmen-us; MSNcOTH) like Gecko",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ie/10.6/Windows 7",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (compatible; MSIE 10.6; Windows NT 6.1; Trident/5.0; InfoPath.2; SLCC1; .NET CLR 3.0.4506.2152; .NET CLR 3.5.30729; .NET CLR 2.0.50727) 3gpp-gba UNTRUSTED/1.0",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ie/7.0/Windows Server 2003",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (compatible; MSIE 7.0; Windows NT 5.2; WOW64; .NET CLR 2.0.50727)",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "opera/9.80/Windows XP",
                self::DATA_PROVIDER_ACTUAL   => "Opera/9.80 (J2ME/MIDP; Opera Mini/5.0 (Windows; U; Windows NT 5.1; en) AppleWebKit/886; U; en) Presto/2.4.15",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "opera/9.25/Mac OS",
                self::DATA_PROVIDER_ACTUAL   => "Opera/9.25 (Macintosh; Intel Mac OS X; U; en)",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "opera/38.0.2220.31/Mac OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.84 Safari/537.36 OPR/38.0.2220.31",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "bb10/7.2.0.0/BlackBerry OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (BB10; Touch) AppleWebKit/537.10+ (KHTML, like Gecko) Version/7.2.0.0 Mobile Safari/537.10+",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "android/4.0.3/Android OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Linux; U; Android 4.0.3; ko-kr; LG-L160L Build/IML74K) AppleWebkit/534.30 (KHTML, like Gecko) Version/4.0 Mobile Safari/534.30",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ios/6.0/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPad; CPU OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A5355d Safari/8536.25",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ios/5.0.2/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPod; U; CPU iPhone OS 4_3_3 like Mac OS X; ja-jp) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "safari/7.0.3/Mac OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_3) AppleWebKit/537.75.14 (KHTML, like Gecko) Version/7.0.3 Safari/7046A194A",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "yandexbrowser/16.10.0.2774/Mac OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.116 YaBrowser/16.10.0.2774 Safari/537.36",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "vivaldi/1.2.490.43/Mac OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36 Vivaldi/1.2.490.43",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "kakaotalk/6.2.2/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Netscape 5.0 (iPhone; CPU iPhone OS 10_3 1 like Mac OS X) AppleWebKit/603.1.30 (KHTML, like Gecko) Mobile/14E304 KAKAOTALK 6.2.2",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "kakaotalk/6.2.2/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPhone; CPU iPhone OS  10_3 1 like Mac OS X) AppleWebKit/  603.1.30 (KHTML, like Gecko) Mobile/ 14E304 KAKAOTALK 6.2.2",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "phantomjs/2.1.1/Mac OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Macintosh; Intel Mac OS X) AppleWebKit/538.1 (KHTML, like Gecko) PhantomJS/2.1.1 Safari/538.1",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "aol/54.0.2848.0/Windows 10",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2841.00 Safari/537.36 AOLShield/54.0.2848.0",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "facebook/157.0.0.42.96/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPhone; CPU iPhone OS 11_2_5 like Mac OS X) AppleWebKit/604.5.6 (KHTML, like Gecko) Mobile/15D60 [FBAN/FBIOS;FBAV/157.0.0.42.96;FBBV/90008621;FBDV/iPhone9,1;FBMD/iPhone;FBSN/iOS;FBSV/11.2.5;FBSS/2;FBCR/Verizon;FBID/phone;FBLC/en_US;FBOP/5;FBRV/0]",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "instagram/8.4.0/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPhone; CPU iPhone OS 9_3_2 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Mobile/13F69 Instagram 8.4.0 (iPhone7,2; iPhone OS 9_3_2; nb_NO; nb-NO; scale=2.00; 750x1334",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ios-webview/533.17.9/iOS",
                self::DATA_PROVIDER_ACTUAL   => "User-Agent: Mozilla/5.0 (iPad; U; CPU OS 4_3_2 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Mobile",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "ios-webview/605.1.15/iOS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (iPad; CPU OS 11_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E216",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => "samsung/4.0/Android OS",
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (Linux; Android 5.0.2; SAMSUNG SM-G925F Build/LRX22G) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/4.0 Chrome/44.0.2403.133 Mobile Safari/537.36",
            ],
        ];
    }

    protected function botContexts(): array
    {
        return [
            [
                self::DATA_PROVIDER_EXPECTED => 'bot/ahrefsbot',
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (compatible; AhrefsBot/5.2; +http://ahrefs.com/robot/)",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'bot/googlebot',
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Safari/537.36",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'bot/yandex',
                self::DATA_PROVIDER_ACTUAL   => "Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)",
            ],
        ];
    }

    protected function ipAddressInfo(): array
    {
        return [
            [
                self::DATA_PROVIDER_EXPECTED => 'unknown',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'for=127.0.0.1'],
                self::DATA_PROVIDER_REASON   => 'Should reject loopback IP v4',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'unknown',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'for=10.0.0.1'],
                self::DATA_PROVIDER_REASON   => 'Rejects IP v4 in private range',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '8ab0:74b1:1c7c:117b:23e6:eb6f:dd4e:5a87',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'For="[8ab0:74b1:1c7c:117b:23e6:eb6f:dd4e:5a87]", for="[46d7:c4f2:d436:642e:ced9:2efe:8c4e:6113]"'],
                self::DATA_PROVIDER_REASON   => 'Should match the first of several valid IPs listed',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '8ab0:74b1:1c7c:117b:23e6:eb6f:dd4e:5a87',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'for=127.0.0.1, for="[8ab0:74b1:1c7c:117b:23e6:eb6f:dd4e:5a87]"'],
                self::DATA_PROVIDER_REASON   => 'Should match the first valid IP with invalid IP listed before it',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '8ab0:74b1:1c7c:117b:23e6:eb6f:dd4e:5a87',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'For="[8ab0:74b1:1c7c:117b:23e6:eb6f:dd4e:5a87]:81"'],
                self::DATA_PROVIDER_REASON   => 'Should strip port number from IP v6',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => '192.0.2.43',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'for=192.0.2.43:81, for=198.51.100.17'],
                self::DATA_PROVIDER_REASON   => 'Should strip port number from IP v4',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'unknown',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'for=10.0.0.1;proto=http;by=203.0.113.43'],
                self::DATA_PROVIDER_REASON   => "Should ignore IPs listed with keywords other than 'for'",
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'unknown',
                self::DATA_PROVIDER_ACTUAL   => ['Forwarded' => 'for=flurp'],
                self::DATA_PROVIDER_REASON   => 'Should reject this nonsense',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'unknown',
                self::DATA_PROVIDER_ACTUAL   => ['X-Forwarded-For' => '192.168.1.3, 192.0.2.43, 192.0.2.44'],
                self::DATA_PROVIDER_REASON   => 'Should match only the first listed IP address',
            ],
            [
                self::DATA_PROVIDER_EXPECTED => 'unknown',
                self::DATA_PROVIDER_ACTUAL   => ['X-Forwarded-For' => 'flurp'],
                self::DATA_PROVIDER_REASON   => 'Should reject this nonsense',
            ],
        ];
    }
}
