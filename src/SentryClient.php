<?php

namespace Kodus\Sentry;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use RuntimeException;
use SplFileObject;
use Throwable;

class SentryClient
{
    /**
     * @var string[] map where PHP error-level => Sentry Event error-level
     *
     * @link http://php.net/manual/en/errorfunc.constants.php
     *
     * @link https://docs.sentry.io/clientdev/attributes/#optional-attributes
     */
    public $error_levels = [
        E_DEPRECATED        => EventLevel::WARNING,
        E_USER_DEPRECATED   => EventLevel::WARNING,
        E_WARNING           => EventLevel::WARNING,
        E_USER_WARNING      => EventLevel::WARNING,
        E_RECOVERABLE_ERROR => EventLevel::WARNING,
        E_ERROR             => EventLevel::FATAL,
        E_PARSE             => EventLevel::FATAL,
        E_CORE_ERROR        => EventLevel::FATAL,
        E_CORE_WARNING      => EventLevel::FATAL,
        E_COMPILE_ERROR     => EventLevel::FATAL,
        E_COMPILE_WARNING   => EventLevel::FATAL,
        E_USER_ERROR        => EventLevel::ERROR,
        E_NOTICE            => EventLevel::INFO,
        E_USER_NOTICE       => EventLevel::INFO,
        E_STRICT            => EventLevel::INFO,
    ];

    /**
     * @var string[] map where regular expression pattern => browser name
     */
    public $browser_patterns = [
        "/AOLShield\/([0-9\._]+)/"                           => "aol",
        "/Edge\/([0-9\._]+)/"                                => "edge",
        "/YaBrowser\/([0-9\._]+)/"                           => "yandexbrowser",
        "/Vivaldi\/([0-9\.]+)/"                              => "vivaldi",
        "/KAKAOTALK\s([0-9\.]+)/"                            => "kakaotalk",
        "/SamsungBrowser\/([0-9\.]+)/"                       => "samsung",
        "/(?!Chrom.*OPR)Chrom(?:e|ium)\/([0-9\.]+)(:?\s|$)/" => "chrome",
        "/PhantomJS\/([0-9\.]+)(:?\s|$)/"                    => "phantomjs",
        "/CriOS\/([0-9\.]+)(:?\s|$)/"                        => "crios",
        "/Firefox\/([0-9\.]+)(?:\s|$)/"                      => "firefox",
        "/FxiOS\/([0-9\.]+)/"                                => "fxios",
        "/Opera\/([0-9\.]+)(?:\s|$)/"                        => "opera",
        "/OPR\/([0-9\.]+)(:?\s|$)$/"                         => "opera",
        "/Trident\/7\.0.*rv\:([0-9\.]+).*\).*Gecko$/"        => "ie",
        "/MSIE\s([0-9\.]+);.*Trident\/[4-7].0/"              => "ie",
        "/MSIE\s(7\.0)/"                                     => "ie",
        "/BB10;\sTouch.*Version\/([0-9\.]+)/"                => "bb10",
        "/Android\s([0-9\.]+)/"                              => "android",
        "/Version\/([0-9\._]+).*Mobile.*Safari.*/"           => "ios",
        "/Version\/([0-9\._]+).*Safari/"                     => "safari",
        "/FBAV\/([0-9\.]+)/"                                 => "facebook",
        "/Instagram\s([0-9\.]+)/"                            => "instagram",
        "/AppleWebKit\/([0-9\.]+).*Mobile/"                  => "ios-webview",

        "/(nuhk|slurp|ask jeeves\/teoma|ia_archiver|alexa|crawl|crawler|crawling|facebookexternalhit|feedburner|google web preview|nagios|postrank|pingdom|slurp|spider|yahoo!|yandex|\w+bot)/i" => "bot",
    ];

    /**
     * @var string[] map where regular expression pattern => OS name
     */
    public $os_patterns = [
        "/iP(hone|od|ad)/"                    => "iOS",
        "/Android/"                           => "Android OS",
        "/BlackBerry|BB10/"                   => "BlackBerry OS",
        "/IEMobile/"                          => "Windows Mobile",
        "/Kindle/"                            => "Amazon OS",
        "/Win16/"                             => "Windows 3.11",
        "/(Windows 95)|(Win95)|(Windows_95)/" => "Windows 95",
        "/(Windows 98)|(Win98)/"              => "Windows 98",
        "/(Windows NT 5.0)|(Windows 2000)/"   => "Windows 2000",
        "/(Windows NT 5.1)|(Windows XP)/"     => "Windows XP",
        "/(Windows NT 5.2)/"                  => "Windows Server 2003",
        "/(Windows NT 6.0)/"                  => "Windows Vista",
        "/(Windows NT 6.1)/"                  => "Windows 7",
        "/(Windows NT 6.2)/"                  => "Windows 8",
        "/(Windows NT 6.3)/"                  => "Windows 8.1",
        "/(Windows NT 10.0)/"                 => "Windows 10",
        "/Windows ME/"                        => "Windows ME",
        "/OpenBSD/"                           => "Open BSD",
        "/SunOS/"                             => "Sun OS",
        "/(Linux)|(X11)/"                     => "Linux",
        "/(Mac_PowerPC)|(Macintosh)/"         => "Mac OS",
        "/QNX/"                               => "QNX",
        "/BeOS/"                              => "BeOS",
        "/OS\/2/"                             => "OS/2",
    ];

    /**
     * @var string Sentry API endpoint
     */
    private $url;

    /**
     * @var string X-Sentry authentication header template
     */
    private $auth_header;

    /**
     * @var string
     */
    private $dsn;

    /**
     * @var OSContext
     */
    private $os;

    /**
     * @var RuntimeContext
     */
    private $runtime;

    /**
     * @param string $dsn Sentry DSN
     */
    public function __construct(string $dsn)
    {
        $this->dsn = $dsn;

        $url = parse_url($this->dsn);

        $auth_tokens = implode(
            ", ",
            [
                "Sentry sentry_version=7",
                "sentry_timestamp=%s",
                "sentry_key={$url['user']}",
                "sentry_client=kodus-sentry/1.0",
            ]
        );

        $this->auth_header = "X-Sentry-Auth: " . $auth_tokens;

        $this->url = "{$url['scheme']}://{$url['host']}/api{$url['path']}/store/";

        $this->runtime = $this->createRuntimeContext();

        $this->os = $this->createOSContext();
    }

    /**
     * Create and capture details about a given {@see Throwable} and (optionally) an
     * associated {@see ServerRequestInterface}.
     *
     * @param Throwable                   $exception the Exception to be logged
     * @param ServerRequestInterface|null $request   the related PSR-7 Request (if applicable)
     */
    public function captureException(Throwable $exception, ?ServerRequestInterface $request = null): void
    {
        $event = $this->createEvent($exception, $request);

        $this->captureEvent($event);
    }

    /**
     * Create an {@see Event} instance with details about a given {@see Throwable} and
     * (optionally) an associated {@see ServerRequestInterface}.
     *
     * @param Throwable                   $exception the Exception to be logged
     * @param ServerRequestInterface|null $request   the related PSR-7 Request (if applicable)
     *
     * @return Event
     */
    public function createEvent(Throwable $exception, ?ServerRequestInterface $request = null): Event
    {
        $timestamp = $this->createTimestamp();

        $event_id = $this->createEventID();

        $event = new Event($event_id, $timestamp, $exception->getMessage());

        $event->exception = $this->createExceptionList($exception);

        $event->addContext($this->os);

        $event->addContext($this->runtime);

        $event->addTag("server_name", php_uname('n'));

        if ($request) {
            $this->addRequestDetails($event, $request);
        }

        return $event;
    }

    /**
     * Capture (HTTP `POST`) a given Event to Sentry.
     *
     * @param Event $event
     */
    public function captureEvent(Event $event): void
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            $this->createAuthHeader($event->timestamp),
        ];

        $response = $this->fetch("POST", $this->url, $body, $headers);

        $data = json_decode($response, true);

        $event->event_id = $data["id"];
    }

    /**
     * @return int current time
     */
    protected function createTimestamp(): int
    {
        return time();
    }

    /**
     * @return string UUID v4 without the "-" separators (as required by Sentry)
     */
    protected function createEventID(): string
    {
        $bytes = unpack('C*', random_bytes(16));

        return sprintf(
            '%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x',
            $bytes[1], $bytes[2], $bytes[3], $bytes[4],
            $bytes[5], $bytes[6],
            $bytes[7] & 0x0f | 0x40, $bytes[8],
            $bytes[9] & 0x3f | 0x80, $bytes[10],
            $bytes[11], $bytes[12], $bytes[13], $bytes[14], $bytes[15], $bytes[16]
        );
    }

    /**
     * Creates the `X-Sentry-Auth` header.
     *
     * @param int $timestamp
     *
     * @return string
     */
    private function createAuthHeader(int $timestamp)
    {
        return sprintf($this->auth_header, $timestamp);
    }

    /**
     * Populates the given {@see Event} instance with information about the given {@see ServerRequestInterface}.
     *
     * @param Event                  $event
     * @param ServerRequestInterface $request
     */
    protected function addRequestDetails(Event $event, ServerRequestInterface $request)
    {
        $event->addTag("site", $request->getUri()->getHost());

        $event->request = new Request($request->getUri()->__toString(), $request->getMethod());

        $event->request->query_string = $request->getUri()->getQuery();

        $event->request->cookies = $request->getCookieParams();

        $headers = [];

        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }

        $event->request->headers = $headers;

        if ($request->hasHeader("User-Agent")) {
            $this->applyBrowserContext($event, $request->getHeaderLine("User-Agent"));
        }

        // TODO populate $data with post-data (in whatever format is given)
        // TODO populate $env ?
    }

    /**
     * Creates an {@see ExceptionList} instance from a given {@see Throwable}.
     *
     * @param Throwable $exception
     *
     * @return ExceptionList
     */
    protected function createExceptionList(Throwable $exception): ExceptionList
    {
        $items = [];

        while ($exception) {
            $items[] = $this->createExceptionInfo($exception);

            $exception = $exception->getPrevious();
        }

        return new ExceptionList(array_reverse($items));
    }

    /**
     * Creates an {@see ExceptionInfo} intsance from a given {@see Throwable}.
     *
     * @param Throwable $exception
     *
     * @return ExceptionInfo
     */
    protected function createExceptionInfo(Throwable $exception): ExceptionInfo
    {
        $info = new ExceptionInfo(get_class($exception), $exception->getMessage());

        $info->stacktrace = $this->createStackTrace($exception->getTrace());

        return $info;
    }

    /**
     * Creates a {@see StackTrace} instance from a given PHP stack-trace.
     *
     * @param array $trace PHP stack-trace
     *
     * @return StackTrace
     */
    protected function createStackTrace(array $trace): StackTrace
    {
        $frames = [];

        foreach ($trace as $index => $entry) {
            $frames[] = $this->createStackFrame($entry);
        }

        return new StackTrace(array_reverse($frames));
    }

    /**
     * Creates a {@see StackFrame} instance from a given PHP stack-trace entry.
     *
     * @param array $entry PHP stack-trace entry
     *
     * @return StackFrame
     */
    protected function createStackFrame(array $entry): StackFrame
    {
        $filename = isset($entry["file"])
            ? $entry["file"]
            : "{no file}";

        $function = isset($entry["class"])
            ? $entry["class"] . @$entry["type"] . @$entry["function"]
            : @$entry["function"];

        $lineno = array_key_exists("line", $entry)
            ? (int) $entry["line"]
            : 0;

        $frame = new StackFrame($filename, $function, $lineno);

        if ($filename !== "{no file}") {
            $this->loadContext($frame, $filename, $lineno, 5);
        }

        if (isset($entry['args'])) {
            $frame->vars = $this->extractVars($entry);
        }

        return $frame;
    }

    /**
     * Attempts to load lines of source-code "context" from a PHP script to a {@see StackFrame} instance.
     *
     * @param StackFrame $frame     Sentry Client StackFrame to populate
     * @param string     $filename  path to PHP script
     * @param int        $lineno
     * @param int        $num_lines number of lines of context
     */
    protected function loadContext(StackFrame $frame, string $filename, int $lineno, int $num_lines)
    {
        if (! is_file($filename) || ! is_readable($filename)) {
            return;
        }

        $target = max(0, ($lineno - ($num_lines + 1)));

        $currentLineNumber = $target + 1;

        try {
            $file = new SplFileObject($filename);

            $file->seek($target);

            while (! $file->eof()) {
                $line = rtrim($file->current(), "\r\n");

                if ($currentLineNumber == $lineno) {
                    $frame->context_line = $line;
                } elseif ($currentLineNumber < $lineno) {
                    $frame->pre_context[] = $line;
                } elseif ($currentLineNumber > $lineno) {
                    $frame->post_context[] = $line;
                }

                $currentLineNumber += 1;

                if ($currentLineNumber > $lineno + $num_lines) {
                    break;
                }

                $file->next();
            }
        } catch (\Exception $ex) {
            return;
        }
    }

    /**
     * Extracts a map of parameters names to human-readable values from a given stack-frame.
     *
     * @param array $entry PHP stack-frame entry
     *
     * @return string[] map where parameter name => human-readable value string
     */
    protected function extractVars(array $entry)
    {
        $reflection = $this->getReflection($entry);

        $names = $reflection
            ? $this->getParameterNames($reflection)
            : [];

        $vars = [];

        $values = $this->formatValues($entry['args']);

        foreach ($values as $index => $value) {
            $vars[$names[$index] ?? "#" . ($index + 1)] = $value;
        }

        return $vars;
    }

    /**
     * Attempts to obtain a Function Reflection for a given stack-frame.
     *
     * @param array $entry PHP stack-frame entry
     *
     * @return ReflectionFunctionAbstract|null
     */
    protected function getReflection(array $entry): ?ReflectionFunctionAbstract
    {
        try {
            if (isset($entry["class"])) {
                if (method_exists($entry["class"], $entry["function"])) {
                    return new ReflectionMethod($entry["class"], $entry["function"]);
                } elseif ("::" === $entry["type"]) {
                    return new ReflectionMethod($entry["class"], "__callStatic");
                } else {
                    return new ReflectionMethod($entry["class"], "__call");
                }
            } elseif (function_exists($entry["function"])) {
                return new ReflectionFunction($entry["function"]);
            }
        } catch (ReflectionException $exception) {
            return null;
        }

        return null;
    }

    /**
     * Creates a list of parameter-names for a given Function Reflection.
     *
     * @param ReflectionFunctionAbstract $reflection
     *
     * @return string[] list of parameter names
     */
    protected function getParameterNames(ReflectionFunctionAbstract $reflection): array
    {
        $names = [];

        foreach ($reflection->getParameters() as $param) {
            $names[] = "$" . $param->getName();
        }

        return $names;
    }

    /**
     * Formats an array of raw PHP values as human-readable strings
     *
     * @param mixed[] $values raw PHP values
     *
     * @return string[] formatted values
     */
    protected function formatValues(array $values): array
    {
        $formatted = [];

        foreach ($values as $value) {
            $formatted[] = $this->formatValue($value);
        }

        return $formatted;
    }

    /**
     * @var int maximum length of formatted string-values
     *
     * @see formatValue()
     */
    const MAX_STRING_LENGTH = 200;

    /**
     * Formats any given PHP value as a human-readable string
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function formatValue($value): string
    {
        $type = is_array($value) && is_callable($value)
            ? "callable"
            : strtolower(gettype($value));

        switch ($type) {
            case "boolean":
                return $value ? "true" : "false";

            case "integer":
                return number_format($value, 0, "", "");

            case "double": // (for historical reasons "double" is returned in case of a float, and not simply "float")
                $formatted = sprintf("%.6g", $value);

                return $value == $formatted
                    ? "{$formatted}"
                    : "~{$formatted}";

            case "string":
                $string = strlen($value) > self::MAX_STRING_LENGTH
                    ? substr($value, 0, self::MAX_STRING_LENGTH) . "...[" . strlen($value) . "]"
                    : $value;

                return '"' . addslashes($string) . '"';

            case "array":
                return "array[" . count($value) . "]";

            case "object":
                if ($value instanceof Closure) {
                    $reflection = new ReflectionFunction($value);

                    return "{Closure in " . $reflection->getFileName() . "({$reflection->getStartLine()})}";
                }

                return "{" . ($value instanceof \stdClass ? "object" : get_class($value)) . "}";

            case "resource":
                return "{" . get_resource_type($value) . "}";

            case "resource (closed)":
                return "{unknown type}";

            case "callable":
                return is_object($value[0])
                    ? '{' . get_class($value[0]) . "}->{$value[1]}()"
                    : "{$value[0]}::{$value[1]}()";

            case "null":
                return "null";
        }

        return "{{$type}}"; // "unknown type" and possibly unsupported (future) types
    }

    /**
     * Perform an HTTP request and return the response body.
     *
     * The request must return a 200 status-code.
     *
     * @param string $method HTTP method ("GET", "POST", etc.)
     * @param string $url
     * @param string $body
     * @param array  $headers
     *
     * @return string response body
     */
    protected function fetch(string $method, string $url, string $body, array $headers = []): string
    {
        $context = stream_context_create([
            "http" => [
                // http://docs.php.net/manual/en/context.http.php
                "method"        => $method,
                "header"        => implode("\r\n", $headers),
                "content"       => $body,
                "ignore_errors" => true,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        /**
         * @var array $http_response_header materializes out of thin air
         */

        $status_line = $http_response_header[0];

        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);

        $status = $match[1];

        if ($status !== "200") {
            throw new RuntimeException("unexpected response status: {$status_line} ({$method} {$url})");
        }

        return $response;
    }

    /**
     * Create run-time context information about this PHP installation.
     *
     * @return RuntimeContext
     */
    private function createRuntimeContext(): RuntimeContext
    {
        $name = "php";

        $raw_description = PHP_VERSION;

        preg_match("#^\d+(\.\d+){2}#", $raw_description, $version);

        return new RuntimeContext($name, $version[0], $raw_description);
    }

    /**
     * Create the OS context information about this Operating System.
     *
     * @return OSContext
     */
    private function createOSContext(): OSContext
    {
        $name = php_uname("s");
        $version = php_uname("v");
        $build = php_uname("r");

        return new OSContext($name, $version, $build);
    }

    /**
     * Create the Browser context information based on the `User-Agent` string.
     *
     * @link https://github.com/DamonOehlman/detect-browser
     *
     * @param Event  $event
     * @param string $user_agent
     */
    private function applyBrowserContext(Event $event, string $user_agent)
    {
        $browser_name = "unknown";

        foreach ($this->browser_patterns as $pattern => $name) {
            if (preg_match($pattern, $user_agent, $browser_matches) === 1) {
                $browser_name = $name;

                break;
            }
        }

        $browser_version = $browser_name;

        if (isset($browser_matches[1])) {
            $version = strtolower(implode(".", preg_split('/[._]/', $browser_matches[1])));

            $event->addTag("browser.{$browser_name}", $version);

            $browser_version = "{$browser_version}/{$version}";
        }

        if ($browser_version === "unknown") {
            $browser_version = $user_agent; // TODO maybe fall back on a User-Agent hash for brevity?
        } else if ($browser_version !== "bot") {
            foreach ($this->os_patterns as $pattern => $os) {
                if (preg_match($pattern, $user_agent) === 1) {
                    $event->addTag("browser.os", $os);

                    $browser_version = "{$browser_version}/{$os}";

                    break;
                }
            }
        }

        $event->addContext(new BrowserContext($browser_name, $browser_version));
    }
}
