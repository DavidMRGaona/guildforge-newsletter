<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Support;

use DateTimeImmutable;
use Modules\Newsletter\Application\DTOs\NewsletterSettings;
use Modules\Newsletter\Application\Services\NewsletterSettingsRepositoryInterface;
use Modules\Newsletter\Domain\Enums\ConnectionFailure;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;

/**
 * Saves newsletter settings the way the settings page does, so the module is available (or not).
 */
trait ConfiguresNewsletter
{
    protected const string LIST_ID = 'a1b2c3d4e5';

    protected function configureNewsletter(
        bool $lastCheckOk = true,
        ?string $consentText = 'Quiero recibir el boletín de la asociación.',
        ?string $privacyUrl = '/privacidad',
    ): void {
        $this->app->make(NewsletterSettingsRepositoryInterface::class)->save(new NewsletterSettings(
            apiKey: new MailchimpApiKey('fakemailchimpkeyfakemailchimpkey', 'us21'),
            listId: self::LIST_ID,
            listName: 'Socios',
            consentText: $consentText,
            privacyUrl: $privacyUrl,
            lastCheck: new ConnectionCheck(
                new DateTimeImmutable('2026-11-14 10:00:00'),
                $lastCheckOk ? null : ConnectionFailure::Unreachable,
            ),
        ));
    }

    protected function memberUrl(string $email): string
    {
        return 'https://us21.api.mailchimp.com/3.0/lists/'.self::LIST_ID.'/members/'.md5(mb_strtolower($email));
    }
}
