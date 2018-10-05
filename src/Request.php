<?php

namespace Kodus\Sentry;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/http/
 */
class Request implements JsonSerializable
{
    /**
     * @var string full URL of the request
     */
    public $url;

    /**
     * @var string HTTP method used
     */
    public $method;

    /**
     * @var string|null unparsed query string
     */
    public $query_string;

    /**
     * @var string|null cookie values (unparsed, as a string)
     */
    public $cookies;

    /**
     * @var string[] map where header-name => header-value
     */
    public $headers = [];

    /**
     * @var string|array|null Submitted data in whatever format makes most sense
     */
    public $data;

    /**
     * @var string[] map where key => ernvironment value
     */
    public $env = [];

    /**
     * @param string $url
     * @param string $method
     */
    public function __construct(string $url, string $method)
    {
        $this->url = $url;
        $this->method = $method;
    }

    public function jsonSerialize()
    {
        return get_object_vars($this);
    }
}
