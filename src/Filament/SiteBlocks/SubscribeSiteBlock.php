<?php

declare(strict_types=1);

namespace Modules\Newsletter\Filament\SiteBlocks;

use App\Application\SiteBlocks\Contracts\SiteBlockInterface;
use App\Domain\SiteBlocks\SiteBlockPlacement;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Modules\Newsletter\Http\SubscribeFormProps;

/**
 * The subscription form as a block of the footer, of a popup (core#696) or of the link page
 * (core#755). Only drawn while the newsletter is available; the consent text and the privacy link
 * come from the module settings.
 */
final readonly class SubscribeSiteBlock implements SiteBlockInterface
{
    public const string KEY = 'newsletter.subscribe';

    public function __construct(
        private NewsletterAvailabilityInterface $availability,
        private SubscribeFormProps $form,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) __('newsletter::messages.block.label');
    }

    public function placements(): array
    {
        return [SiteBlockPlacement::Footer, SiteBlockPlacement::Popup, SiteBlockPlacement::LinkPage];
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('intro')
                ->label(__('newsletter::messages.block.fields.intro'))
                ->helperText(__('newsletter::messages.block.helpers.intro'))
                ->maxLength(160),
            TextInput::make('button_label')
                ->label(__('newsletter::messages.block.fields.button_label'))
                ->placeholder(__('newsletter::messages.block.button_default'))
                ->maxLength(40),
            Toggle::make('show_name')
                ->label(__('newsletter::messages.block.fields.show_name'))
                ->helperText(__('newsletter::messages.block.helpers.show_name'))
                ->default(true),
        ];
    }

    public function component(): string
    {
        return 'components/SubscribeBlock.vue';
    }

    public function isAvailable(): bool
    {
        return $this->availability->isAvailable();
    }

    public function props(array $settings, ?Authenticatable $user): array
    {
        return $this->form->for(
            $user,
            intro: is_string($settings['intro'] ?? null) ? $settings['intro'] : null,
            buttonLabel: is_string($settings['button_label'] ?? null) ? $settings['button_label'] : null,
            // A block saved before the toggle existed shows the name field
            showName: ($settings['show_name'] ?? true) !== false,
        );
    }
}
