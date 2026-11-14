<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A Mailchimp Marketing API key: a secret, a dash and the data centre of the account
 * ("…-us21"). The data centre becomes the host of every request, so only the shape Mailchimp
 * uses is accepted: anything else could send the key to another host.
 */
final readonly class MailchimpApiKey
{
    private const string SECRET_PATTERN = '/^[A-Za-z0-9]{16,64}$/';

    private const string SERVER_PATTERN = '/^[a-z]{2}[0-9]{1,3}$/';

    public function __construct(
        private string $secret,
        private string $server,
    ) {
        if (preg_match(self::SECRET_PATTERN, $secret) !== 1 || preg_match(self::SERVER_PATTERN, $server) !== 1) {
            // Never the value: exception messages end up in logs
            throw new InvalidArgumentException('Not a Mailchimp API key');
        }
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        $dash = strrpos($value, '-');

        if ($dash === false) {
            throw new InvalidArgumentException('Not a Mailchimp API key');
        }

        return new self(substr($value, 0, $dash), substr($value, $dash + 1));
    }

    public static function isWellFormed(string $value): bool
    {
        try {
            self::fromString($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function value(): string
    {
        return $this->secret.'-'.$this->server;
    }

    /** The data centre of the account, e.g. "us21" */
    public function server(): string
    {
        return $this->server;
    }

    public function baseUrl(): string
    {
        return "https://{$this->server}.api.mailchimp.com/3.0";
    }

    /**
     * dump(), print_r() and var_dump() show the server only.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['server' => $this->server, 'secret' => '********'];
    }
}
