<?php

declare(strict_types=1);

namespace Modules\Newsletter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Newsletter\Application\Services\NewsletterAvailabilityInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The newsletter routes do not exist while the newsletter is not available: every request gets
 * the same 404, before the limit, the verification or the validation look at it.
 */
final readonly class EnsureNewsletterIsAvailable
{
    public function __construct(
        private NewsletterAvailabilityInterface $availability,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->availability->isAvailable(), 404);

        return $next($request);
    }
}
