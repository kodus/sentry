<?php

namespace Kodus\Sentry\Model;

/**
 * @see https://docs.sentry.io/clientdev/interfaces/user/
 */
class UserInfo
{
    /**
     * @var string|null the application-specific unique ID of the User
     */
    public $id;

    /**
     * @var string|null the User's application-specific logical username (or display-name, etc.)
     */
    public $username;

    /**
     * @var string|null the User's e-mail address
     */
    public $email;

    /**
     * @var string|null the User's client IP address (dotted notation)
     */
    public $ip_address;
}
