<?php

namespace Tests\Mocks;

class MockRequest
{
    /**
     * @var string
     */
    public $body;

    /**
     * @var string[]
     */
    public $headers;

    /**
     * @param string   $body
     * @param string[] $headers
     */
    public function __construct(string $body, array $headers)
    {
        $this->body = $body;
        $this->headers = $headers;
    }
}
