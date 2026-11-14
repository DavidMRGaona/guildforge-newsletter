<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain\ValueObjects;

/**
 * How Mailchimp names a member: the MD5 of the lowercase address. It is also the only trace of an
 * address the module ever writes to a log.
 */
final readonly class SubscriberHash
{
    private function __construct(
        private string $value,
    ) {}

    public static function fromEmail(string $email): self
    {
        return new self(md5(mb_strtolower(trim($email))));
    }

    public function value(): string
    {
        return $this->value;
    }
}
