<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Unit\Domain;

use InvalidArgumentException;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailchimpApiKeyTest extends TestCase
{
    public function test_the_server_comes_from_the_end_of_the_key(): void
    {
        $key = MailchimpApiKey::fromString('  fakemailchimpkeyfakemailchimpkey-us21 ');

        $this->assertSame('us21', $key->server());
        $this->assertSame('fakemailchimpkeyfakemailchimpkey-us21', $key->value());
        $this->assertSame('https://us21.api.mailchimp.com/3.0', $key->baseUrl());
    }

    /**
     * The server ends up in the host name of every request: anything that is not a Mailchimp data
     * centre would send the key somewhere else.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'no server' => ['fakemailchimpkeyfakemailchimpkey'];
        yield 'another host' => ['fakemailchimpkeyfakemailchimpkey-evil.example.com'];
        yield 'path in the server' => ['fakemailchimpkeyfakemailchimpkey-us21/../x'];
        yield 'short secret' => ['abc-us21'];
        yield 'spaces inside' => ['fakemailchimpkey fakemailchimpkey-us21'];
    }

    #[DataProvider('malformedKeys')]
    public function test_a_malformed_key_is_refused(string $value): void
    {
        $this->assertFalse(MailchimpApiKey::isWellFormed($value));

        $this->expectException(InvalidArgumentException::class);
        MailchimpApiKey::fromString($value);
    }

    public function test_the_exception_never_carries_the_key(): void
    {
        try {
            MailchimpApiKey::fromString('secretsecretsecretsecret-evil.example.com');
            $this->fail('The key should have been refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('secretsecret', $e->getMessage());
        }
    }

    public function test_dumping_the_key_hides_the_secret(): void
    {
        $key = MailchimpApiKey::fromString('fakemailchimpkeyfakemailchimpkey-us21');

        $this->assertStringNotContainsString('fakemailchimpkey', print_r($key, true));
        $this->assertStringNotContainsString('fakemailchimpkey', var_export($key->__debugInfo(), true));
    }
}
