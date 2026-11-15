<?php

declare(strict_types=1);

namespace Modules\Newsletter\Infrastructure\Registration;

use App\Application\Registration\Contracts\RegistrationFieldInterface;
use App\Domain\Registration\Enums\RegistrationFieldType;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;

/**
 * «Quiero recibir el boletín» on the registration form (core#699): optional and always unticked,
 * and offered only while the newsletter is available.
 */
final readonly class SubscribeOnRegistrationField implements RegistrationFieldInterface
{
    public const string KEY = 'newsletter.subscribe';

    public function __construct(
        private NewsletterAvailabilityInterface $availability,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) __('newsletter::messages.registration.label');
    }

    public function description(): string
    {
        return (string) __('newsletter::messages.registration.description');
    }

    public function type(): RegistrationFieldType
    {
        return RegistrationFieldType::Checkbox;
    }

    public function order(): int
    {
        return 100;
    }

    public function isAvailable(): bool
    {
        return $this->availability->isAvailable();
    }

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
