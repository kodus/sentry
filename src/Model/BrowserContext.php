<?php

namespace Kodus\Sentry\Model;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/contexts/
 */
class BrowserContext implements Context
{
    /**
     * @var string|null Display name of the browser application.
     */
    public $name;

    /**
     * @var string|null Version string of the browser.
     */
    public $version;

    /**
     * @param null|string $name
     * @param null|string $version
     */
    public function __construct(?string $name, ?string $version)
    {
        $this->name = $name;
        $this->version = $version;
    }

    public function getType(): string
    {
        return "browser";
    }

    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this));
    }
}
