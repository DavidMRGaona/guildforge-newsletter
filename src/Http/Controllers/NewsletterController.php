<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Newsletter\Application\Services\SubscriptionRequesterInterface;
use Modules\Newsletter\Http\Requests\SubscribeRequest;
use Modules\Newsletter\Http\SubscribeFormProps;

/**
 * The /newsletter page and the form that every surface sends. Neither exists while the newsletter
 * is not available (EnsureNewsletterIsAvailable, on the routes). The answer is always «check your
 * email»: it never says whether the address was already on the list, and the provider is asked in
 * the background.
 */
final readonly class NewsletterController
{
    public function __construct(
        private SubscribeFormProps $form,
        private SeoMetadataFactoryInterface $seo,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Newsletter/Subscribe', [
            'form' => $this->form->for($request->user()),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('newsletter::messages.subscribe.page_title'),
                description: (string) __('newsletter::messages.subscribe.page_description'),
            ))->toArray(),
        ]);
    }

    public function store(SubscribeRequest $request, SubscriptionRequesterInterface $subscriptions): JsonResponse|RedirectResponse
    {
        if (! $request->isTrapped()) {
            $subscriptions->request($request->subscriberEmail(), $request->subscriberName());
        }

        $message = (string) __('newsletter::messages.subscribe.check_your_email');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return back()->with('success', $message);
    }
}
