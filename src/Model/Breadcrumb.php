<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/breadcrumbs/
 */
class Breadcrumb implements JsonSerializable
{
    /**
     * @var int UNIX timestamp
     */
    public $timestamp;

    /**
     * @var string Severity level
     *
     * @see Level
     */
    public $level;

    /**
     * @var string
     */
    public $message;

    /**
     * @var array
     */
    private $data;

    public function __construct(int $timestamp, string $level, string $message, array $data)
    {
        $this->timestamp = $timestamp;
        $this->level = $level;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * @internal
     */
    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this));
    }
}
