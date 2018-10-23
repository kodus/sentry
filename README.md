`kodus/sentry`
==============

Lightweight [Sentry](https://sentry.io/welcome/) client with no dependencies.

[![PHP Version](https://img.shields.io/badge/php-7.1%2B-blue.svg)](https://packagist.org/packages/kodus/sentry)

### About

This package is an alternative to the [official PHP client](https://github.com/getsentry/sentry-php) for Sentry.

The client is (by and large) a single class backed by a bunch of simple model objects that match the shape
of the Sentry ingestion API end-point it gets posted to.

The API deviates from the [Unified API](https://docs.sentry.io/clientdev/unified-api/) recommendation - our
goal is to log details exceptions and logged events leading up to those exceptions, with as little coupling
and dependency on the client as possible.

With most members declared as `protected`, you can further extend and override/enhance various aspects
of exception/error/request-processing with simple code that modifies the (fully type-hinted) model.

### Features

Most of the useful features of the official client - plus some useful extras.

  * Detailed stack-traces with source-code context, paths/filenames, line-numbers.
  * Logging of "breadcrumbs" via the included [PSR-3](https://www.php-fig.org/psr/psr-3/) logger-adapter.
  * Parses `User-Agent` for client (browser or bot) name/version/OS and adds useful tags.
  * Parses `X-Forwarded-For` and `Forwarded` headers for User IP logging behind proxies.
  * Reports PHP and OS versions, server name, site name, etc.
  * Severity of `ErrorException` mappings identical to the official (2.0) client.

Non-features:

  * No built-in error-handler: your framework/stack/app probably has one, and this client should be
    very easy to integrate just about anywhere.
  * No post-data recording: scrubbing/sanitization is unreliable. (if you're willing to take the risk,
    the fields are there in the model, and you can implement that easily.)

### Usage

Most modern frameworks by now have some sort of DI container and an error-handler.

To avoid getting caught up in the specifics of various frameworks, in this section, we'll demonstrate
how to bootstrap and integrate the client independently of any specific framework.

Bootstrapping the client itself is trivial - the
[Sentry DSN](https://docs.sentry.io/learn/configuration/?platform=node#dsn) is the only dependency:

```php
$client = new SentryClient("https://a1f1cddefbd54085822f50ef14c7c9a8@sentry.io/1292571");
```

There are additional options, which will be discussed in the [Configuration](#configuration) section.

To capture PHP errors, we'll need to add an error-handler that maps errors to instances of the
built-in [`ErrorException`](http://php.net/manual/en/class.errorexception.php) class - the following
simplified error-handler throws for all error-levels except `E_NOTICE` and `E_WARNING`, which it
silently captures to the `$client` instance we created above:

```php
set_error_handler(
    function ($severity, $message, $file, $line) use ($client) {
        $error = new ErrorException($message, 0, $severity, $file, $line);
        
        if ($severity & (E_ALL & ~E_NOTICE & ~E_WARNING)) {
            throw $error;
        } else {
            $client->captureException($error);
        }
    },
);
```

(Note that most frameworks and error-handlers already have something like this built-in - an existing
error-handler likely is designed to be the only global error-handler, and will likely offer an abstraction
and some sort of API over `set_error_handler()`.)

Now that we have `ErrorException` being thrown for PHP errors, we can handle any error/exception
consistently with a `try`/`catch`-statement surrounding any statements in the top-level script:

```php
try {
    // ... dispatch your router or middleware-stack, etc. ...
} catch (Throwable $error) {
    $client->captureException($error);
    
    // ... render an apology page for disappointed users ...
}
```

We now have basic error-handling and silent capture of warnings and notices.

In a [PSR-15](https://www.php-fig.org/psr/psr-7/) middleware context, we can capture useful additional
information about the `RequestInterface` instance that initiated the error - typically, we'll do this
with a simple middleware at the top of the middleware-stack, which will simply delegate to the next
middleware in a `try`/`catch`-statement.

Here's a basic example of an anonymous middleware being added to an array of middlewares:

```php
$middlewares = [
    new class ($client) implements MiddlewareInterface {
        /**
         * @var SentryClient
         */
        private $client;
    
        public function __construct(SentryClient $client)
        {
            $this->client = $client;
        }
    
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            try {
                return $handler->handle($request);
            } catch (Throwable $error) {
                $this->client->captureException($error, $request);
    
                // ... return an apology response for disappointed users ...
            }
        }
    },
    // ... the rest of your middleware stack ...
];
```

Any error caught by this middleware *won't* bubble to our first `try`/`catch`-statement in the
top-level script - instead, the call to `captureException()` from the middleware includes both the
`$error` and the `$request` (which doesn't exist yet in the top-level script) from which the client
can capture a lot more detail.

In addition, your application may log incidental useful information (such as database queries) during
normal operation - on failure, this information can help you diagnose the events leading up to an error.

These log entries can be captured as so-called "breadcrumbs" using the provided `BreadcrumbLogger`,
which is just a simple [PSR-3](https://www.php-fig.org/psr/psr-3/) `LoggerInterface` adapter for the client:

```php
$logger = new BreadcrumbLogger($client);
```

You *might* want to bootstrap this as your primary logger - maybe you only care about log-entries that
lead to an error condition. However, it's more likely you'll want another logger to send log-events to
a persisted log, as well as recording them as breadcrumbs. (For example, you may try the
[`monolog/monolog`](https://packagist.org/packages/monolog/monolog) package, which allows you to log to
any combination of PSR-3 and monolog-handlers, filter the log-entries, and so on - or
[`mouf/utils.log.psr.multi-logger`](https://github.com/thecodingmachine/utils.log.psr.multi-logger),
if you prefer something simple.)

Note that log-entries will buffer *in the client* until you capture an exception - if your
application is a CLI script handling many requests, you will need to manually call `clearBreadcrumbs()`
on the client instance at the start/end of a request.

#### Configuration

The constructor accepts a second argument, `$root_path` - if specified, the project root-path will be
stripped from visible filenames in stack-traces.

You can configure a few different aspects of exception and request processing via public properties.

Use `$error_levels` to configure how PHP error-levels map to Sentry severity-levels. (The default
configuration matches that of the official 2.0 client.)

If your client base uses rare or exotic clients, you can add your own regular expression patterns to
to `$browser_patterns` and `$os_patterns` to enhance the browser classifications. The default
configuration will recognize most common browsers and versions, operating systems and most common bots.

Replace or add to `$user_ip_headers` to detect client IP addresses in unusual environments - the
built-in patterns support most ordinary cache/proxy-servers.

#### Customization

The client class is designed with project-specific extensions in mind - while it performs, essentially,
a single function, this is implemented as many `protected` methods designed for you to extend and
override with application-dependent details.

For example, maybe you want to include a database DSN that is required and defined for your project:

```php
class MyAppSentryClient extends SentryClient
{
    protected function createEvent(Throwable $exception, ?ServerRequestInterface $request = null): Event
    {
        $event = parent::createEvent($exception, $request);
        
        $event->tags['dsn'] = getenv('MY_APP_DSN');
        
        return $event;
    }
}
```

Using these simple extension points, you can customize how events get created and captured, how
exceptions and requests get processed, client IP detection and filtering, how PHP values are
formatted in stack-traces, and many other details.

Refer to the source-code for extension points - and note that we version this package semantically:
breaking changes to `protected` methods of the non-`final` client class *will* be correctly versioned
as major releases.

### Why?

*Openly opinionated fluff:*

Version 1.x of the official Sentry PHP client is dated, and not a good fit for a modern (PSR-7/15) application stack.

Version 2.0 (currently in development) is an architectural masterpiece - which is not what we need/want
from something that's going to essentially collect some data and perform a simple JSON POST request.

Specifically:

  * We don't need an error-handler - every modern application stack has one already.
  * We don't want a complex architecture and custom middleware - simple functions will do.
  * We don't want to record post-data - there are too many risks.
  * Fewer lines of code ~> fewer bugs (hopefully; you don't want the error-logger itself to break down.)
  * No dependencies: no conflicts, no fuss.

We want something simple, fast and transparent.

We also insist on code with good IDE support, which provides better insight for someone reading/modifying the
code, and reduces the potential for silent errors - the official client juggles `array` values, whereas our
model formally describes the JSON body-shape with plain PHP objects/classes that implement `JsonSerializable`.

Note that we only model the portion of the JSON body shape that makes sense in a PHP context - if you find
something missing or incorrect, a PR is of course more than welcome!
