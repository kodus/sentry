<?php

namespace Kodus\Sentry;

use JsonSerializable;

class Event implements JsonSerializable
{
    /**
     * @var string ISO 8601 date format (as required by Sentry)
     *
     * @see gmdate()
     */
    const DATE_FORMAT = "Y-m-d\TH:i:s";

    /**
     * @var string
     */
    public $event_id;

    /**
     * @var string
     *
     * @see EventLevel
     */
    public $level = EventLevel::ERROR;

    /**
     * @var string ISO 8601 timestamp
     */
    public $timestamp;

    /**
     * @var string
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
        $this->timestamp = gmdate(self::DATE_FORMAT, $timestamp);
        $this->message = $message;
    }

    public function addContext(Context $context)
    {
        $this->contexts[$context->getType()] = $context;
    }

    public function addTag(string $name, string $value)
    {
        $this->tags[$name] = $value;
    }

    /**
     * @internal
     */
    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this));
    }
}
