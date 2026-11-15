import { VERIFICATION_REQUIRED_ERROR } from '@/composables/useVerificationGate';
import { csrfHeaders, isCsrfFailure } from '@/utils/csrf';
import type { SubscribeField, SubscribeResult } from '../types/subscribe';

export interface SubscribePayload {
    email: string;
    name: string;
    consent: boolean;
    /** The trap field: people never see it, so it stays empty */
    website: string;
}

const FIELDS: readonly SubscribeField[] = ['email', 'name', 'consent'];

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function messageOf(body: unknown): string | null {
    return isRecord(body) && typeof body.message === 'string' ? body.message : null;
}

function errorsOf(body: unknown): Partial<Record<SubscribeField, string>> {
    const errors: Partial<Record<SubscribeField, string>> = {};
    const sent = isRecord(body) && isRecord(body.errors) ? body.errors : {};

    for (const field of FIELDS) {
        const value = sent[field];
        const first = Array.isArray(value) ? value[0] : value;

        if (typeof first === 'string') {
            errors[field] = first;
        }
    }

    return errors;
}

async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Sends the form as JSON, outside Inertia, so the page (and a popup holding the form) stays as it is.
 * Never throws: a network error is a failed result.
 */
export async function submitSubscription(
    action: string,
    payload: SubscribePayload
): Promise<SubscribeResult> {
    let response: Response;

    try {
        response = await fetch(action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            body: JSON.stringify(payload),
        });
    } catch {
        return { kind: 'error', reason: 'failed', message: null };
    }

    const body = await readJson(response);

    if (response.ok) {
        return { kind: 'success', message: messageOf(body) };
    }

    if (response.status === 422) {
        const errors = errorsOf(body);

        if (Object.keys(errors).length > 0) {
            return { kind: 'invalid', errors };
        }
    }

    if (response.status === 403 && isRecord(body) && body.error === VERIFICATION_REQUIRED_ERROR) {
        return { kind: 'error', reason: 'verify_email', message: null };
    }

    if (response.status === 429) {
        return { kind: 'error', reason: 'too_many', message: messageOf(body) };
    }

    if (isCsrfFailure(response)) {
        return { kind: 'error', reason: 'expired', message: null };
    }

    return { kind: 'error', reason: 'failed', message: null };
}
