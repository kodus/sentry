#### Upgrading from `2.x` to `3.0`
The repository now requires PHP 8.4 or higher. Ensure your environment is updated accordingly.

The E_STRICT error level constant has been removed from Kodus\Sentry\Extensions due to its deprecation and removal in PHP 8.4. Replace any references to E_STRICT in your code, as it is no longer necessary. PHP 8.4 enforces stricter standards as part of E_ALL.

#### Upgrading from `1.x` to `2.0`

The constructor signature of `SentryClient` has changed.

If you had code like the following:

```php
$client = new SentryClient("https://0123456789abcdef0123456789abcdef@sentry.io/1234567");
```

You will need to upgrade to the new constructor signature as follows:

```php
$client = new SentryClient(
    new DirectEventCapture(
        new DSN("https://0123456789abcdef0123456789abcdef@sentry.io/1234567")
    )
);
```

The second constructor argument to the `SentryClient` constructor (`$extensions`) is
the same as before.

If you were using the public `SentryClient::$proxy` setting previously available, you
can now provide this as a second argument to the `DirectEventCapture` constructor.

Note the introduction of the `DSN` model, which may come in handy if you wish to build
a custom `EventCapture` implementation for some reason.
