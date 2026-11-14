<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Newsletter\Http\SubscribeFormProps;

/**
 * The subscription form. `website` is a trap field people never see: whoever fills it in is
 * answered as everybody else and nothing is sent.
 */
final class SubscribeRequest extends FormRequest
{
    public const string TRAP_FIELD = 'website';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:'.SubscribeFormProps::NAME_MAX_LENGTH],
            'consent' => ['accepted'],
            self::TRAP_FIELD => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent.accepted' => (string) __('newsletter::messages.subscribe.errors.consent'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => (string) __('newsletter::messages.subscribe.fields.email'),
            'name' => (string) __('newsletter::messages.subscribe.fields.name'),
        ];
    }

    public function isTrapped(): bool
    {
        return trim((string) $this->input(self::TRAP_FIELD, '')) !== '';
    }

    public function subscriberEmail(): string
    {
        return trim((string) $this->validated('email'));
    }

    public function subscriberName(): ?string
    {
        $name = trim((string) $this->validated('name', ''));

        return $name === '' ? null : $name;
    }
}
