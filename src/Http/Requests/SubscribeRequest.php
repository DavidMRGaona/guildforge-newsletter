<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Newsletter\Http\SubscribeFormProps;

/**
 * The subscription form. `website` is a trap field people never see: whoever sends anything in it
 * (text, a list…) is answered as everybody else, nothing is validated and nothing is sent.
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

    /**
     * A trapped request is not validated: the controller answers it like any other.
     */
    public function validateResolved(): void
    {
        if ($this->isTrapped()) {
            return;
        }

        parent::validateResolved();
    }

    public function isTrapped(): bool
    {
        return $this->filled(self::TRAP_FIELD);
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
