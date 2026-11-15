<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Newsletter\Application\Services\ProfileNewsletterServiceInterface;
use Modules\Newsletter\Domain\Enums\ProfileNewsletterState;

/**
 * The «Boletín» section of the profile: where the account's address stands and joining or leaving
 * with it. Always the signed-in account's own email, never one from the request. The routes do not
 * exist while the newsletter is not available (EnsureNewsletterIsAvailable).
 */
final readonly class ProfileNewsletterController
{
    public function __construct(
        private ProfileNewsletterServiceInterface $newsletter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->account($request);

        return $this->answer($this->newsletter->state((string) $user->getKey(), $user->email));
    }

    public function subscribe(Request $request): JsonResponse
    {
        $user = $this->account($request);

        return $this->answer($this->newsletter->subscribe((string) $user->getKey(), $user->email, $user->name));
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $this->account($request);

        return $this->answer($this->newsletter->unsubscribe((string) $user->getKey(), $user->email));
    }

    private function account(Request $request): UserModel
    {
        $user = $request->user();

        abort_unless($user instanceof UserModel, 401);

        // Whatever the site's verification setting says: anyone can register with an address that is
        // not theirs, and must not read or change that address's subscription
        if (! $user->hasVerifiedEmail()) {
            throw new HttpResponseException(response()->json([
                'message' => (string) __('newsletter::messages.profile.errors.verify_email'),
                'error' => 'email_verification_required',
            ], 403));
        }

        return $user;
    }

    private function answer(ProfileNewsletterState $state): JsonResponse
    {
        // Never cached by the browser or a proxy: it is somebody's own subscription
        return response()->json(['status' => $state->value])->header('Cache-Control', 'no-store, private');
    }
}
