<?php

namespace Kodus\Sentry;

use Kodus\Sentry\Model\Event;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * This interface provides a minimum of composability for the client - you can
 * inject implementations of this interface to the {@see SentryClient} constructor,
 * and the client will apply these prior to capture of any {@see Event}.
 *
 * @see SentryClient::__construct()
 */
interface EventProcessor
{
    public function process(Event $event, Throwable $error, ?ServerRequestInterface $request): void;
}
