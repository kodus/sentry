<?php

namespace Kodus\Sentry;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/attributes/
 */
class Event implements JsonSerializable
{
    /**
     * @var string ISO 8601 date format (as required by Sentry)
     *
     * @see gmdate()
     */
    const DATE_FORMAT = "Y-m-d\TH:i:s";

    /**
     * @var string auto-generated UUID v4 (without dashes, as required by Sentry)
     */
    public $event_id;

    /**
     * @var string severity level of this Event
     *
     * @see EventLevel
     */
    public $level = EventLevel::ERROR;

    /**
     * @var int timestamp
     */
    public $timestamp;

    /**
     * @var string human-readable message
     */
    public $message;

    /**
     * @var string platform name
     */
    public $platform = "php";

    /**
     * @var string[] map where tag name => value
     */
    public $tags = [];

    /**
     * @var ExceptionList|null
     */
    public $exception;

    /**
     * @var Request|null
     */
    public $request;

    /**
     * @var Context[] map where Context Type => Context
     */
    protected $contexts = [];

    public function __construct(string $event_id, int $timestamp, string $message)
    {
        $this->event_id = $event_id;
        $this->timestamp = $timestamp;
        $this->message = $message;
    }

    /**
     * Add/replace a given {@see Context} instance.
     *
     * @param Context $context
     */
    public function addContext(Context $context)
    {
        $this->contexts[$context->getType()] = $context;
    }

    /**
     * Add/replace a given "tag" name/value pair
     *
     * @param string $name
     * @param string $value
     */
    public function addTag(string $name, string $value)
    {
        $this->tags[$name] = $value;
    }

    /**
     * @internal
     */
    public function jsonSerialize()
    {
        $data = array_filter(get_object_vars($this));

        $data["timestamp"] = gmdate(self::DATE_FORMAT, $this->timestamp);

        return $data;
    }
}
