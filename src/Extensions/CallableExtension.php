<?php

namespace Kodus\Sentry\Extensions;

use Throwable;
use Kodus\Sentry\Model\Event;
use Kodus\Sentry\SentryClientExtension;
use Psr\Http\Message\ServerRequestInterface;

class CallableExtension implements SentryClientExtension
{
	/**
    * @var Closure(Event, ServerRequestInterface|null):void
    */
	private $callable;

	/**
    * @param Closure(Event, ServerRequestInterface|null):void $callable
    */
	public function __construct(callable $callable) {
		$this->callable = $callable;
	}

	public function apply(Event $event, Throwable $exception, ?ServerRequestInterface $request): void {
		$callable = $this->callable;
		$callable($event, $request);
	}
}
