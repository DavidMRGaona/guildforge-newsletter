<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Providers;

use App\Application\Impersonation\Services\ImpersonationContextInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderFactoryInterface;
use Modules\Newsletter\Application\Services\NewsletterProviderInterface;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;
use Modules\Newsletter\Domain\ValueObjects\MailchimpListId;
use Modules\Newsletter\Infrastructure\Providers\Mailchimp\MailchimpProvider;

final readonly class NewsletterProviderFactory implements NewsletterProviderFactoryInterface
{
    public function __construct(
        private ImpersonationContextInterface $impersonation,
    ) {}

    public function mailchimp(MailchimpApiKey $apiKey, ?string $listId): NewsletterProviderInterface
    {
        return new MailchimpProvider(
            $this->impersonation,
            $apiKey,
            MailchimpListId::isWellFormed($listId) ? $listId : null,
        );
    }
}
