`kodus/sentry`
==============

Lightweight [Sentry](https://sentry.io/welcome/) client with no dependencies.

[![PHP Version](https://img.shields.io/badge/php-7.1%2B-blue.svg)](https://packagist.org/packages/kodus/sentry)

### About

This package is an alternative to the [official PHP client](https://github.com/getsentry/sentry-php) for Sentry.

The client is (by and large) a single class backed by a bunch of simple model objects that match the shape
of the Sentry ingestion API end-point it gets posted to.

The API deviates slightly from the recommendation - mainly by providing (optional) separation of the creation
and capture of Sentry events, making it possible to create an `Event`, make changes/additions, and then
capture it. No framework or abstractions, just write simple code.

With most members declared as `protected`, you can further extend and class and override/enhance various
aspects of exception/error/request-processing with simple code that modifies the (fully type-hinted) model.

#### Features

Most of the useful features of the official client - plus some useful extras.

  * Detailed stack-traces with source-code context, paths/filenames, line-numbers.
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

TODO add examples

#### Why?

*Openly opinionated fluff:*

Version 1.x of the official Sentry PHP client is dated, and not a good fit for a modern (PSR-7) application stack.

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
