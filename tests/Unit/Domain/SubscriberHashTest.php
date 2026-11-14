<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Unit\Domain;

use Modules\Newsletter\Domain\ValueObjects\SubscriberHash;
use PHPUnit\Framework\TestCase;

final class SubscriberHashTest extends TestCase
{
    public function test_it_is_the_md5_of_the_lowercase_address(): void
    {
        $this->assertSame(md5('ana@example.com'), SubscriberHash::fromEmail(' Ana@Example.COM ')->value());
    }

    public function test_the_same_address_always_gives_the_same_hash(): void
    {
        $this->assertSame(
            SubscriberHash::fromEmail('ANA@example.com')->value(),
            SubscriberHash::fromEmail('ana@EXAMPLE.com')->value(),
        );
    }
}
