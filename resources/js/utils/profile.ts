import { VERIFICATION_REQUIRED_ERROR } from '@/composables/useVerificationGate';
import { csrfHeaders, isCsrfFailure } from '@/utils/csrf';
import type { ProfileNewsletterResult, ProfileNewsletterStatus } from '../types/profile';

const STATUSES: readonly ProfileNewsletterStatus[] = [
    'subscribed',
    'pending',
    'unsubscribed',
    'blocked',
    'unavailable',
];

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function statusOf(body: unknown): ProfileNewsletterStatus | null {
    const status = isRecord(body) ? body.status : null;

    return STATUSES.find((known) => known === status) ?? null;
}

async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Asks (GET), joins (POST) or leaves (DELETE) with the account's own address, as JSON and outside
 * Inertia, so the profile stays as it is. Never throws: a network error is a failed result.
 */
export async function requestProfileNewsletter(
    endpoint: string,
    method: 'GET' | 'POST' | 'DELETE'
): Promise<ProfileNewsletterResult> {
    let response: Response;

    try {
        response = await fetch(endpoint, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(method === 'GET' ? {} : csrfHeaders()),
            },
        });
    } catch {
        return { kind: 'error', reason: 'failed' };
    }

    const body = await readJson(response);

    if (response.ok) {
        const status = statusOf(body);

        return status === null ? { kind: 'error', reason: 'failed' } : { kind: 'status', status };
    }

    if (response.status === 403 && isRecord(body) && body.error === VERIFICATION_REQUIRED_ERROR) {
        return { kind: 'error', reason: 'verify_email' };
    }

    if (response.status === 429) {
        return { kind: 'error', reason: 'too_many' };
    }

    if (isCsrfFailure(response)) {
        return { kind: 'error', reason: 'expired' };
    }

    return { kind: 'error', reason: 'failed' };
}
