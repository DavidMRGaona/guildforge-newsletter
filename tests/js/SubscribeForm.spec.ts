import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import SubscribeForm from '../../resources/js/components/SubscribeForm.vue';
import type { SubscribeFormProps } from '../../resources/js/types/subscribe';
import { signIn } from './support/page';

const CHECK = 'Revisa tu correo: te hemos enviado un enlace para confirmar la suscripción.';

function props(changes: Partial<SubscribeFormProps> = {}): SubscribeFormProps {
    return {
        action: '/newsletter',
        intro: null,
        buttonLabel: null,
        showName: true,
        consentText: 'Quiero recibir el boletín de la asociación.',
        privacyUrl: '/privacidad',
        email: null,
        name: null,
        ...changes,
    };
}

function answer(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function mountForm(changes: Partial<SubscribeFormProps> = {}): VueWrapper {
    return mount(SubscribeForm, { props: props(changes), attachTo: document.body });
}

async function fillAndSend(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('input[type="email"]').setValue('ana@example.com');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

describe('SubscribeForm', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        document.cookie = 'XSRF-TOKEN=token-123';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('names every field with its label', () => {
        const wrapper = mountForm();

        for (const selector of [
            'input[type="email"]',
            'input[name="name"]',
            'input[type="checkbox"]',
        ]) {
            const input = wrapper.get(selector);
            const label = wrapper.get(`label[for="${input.attributes('id')}"]`);

            expect(label.text()).not.toBe('');
        }

        expect(
            wrapper
                .get(`label[for="${wrapper.get('input[type="checkbox"]').attributes('id')}"]`)
                .text()
        ).toContain('Quiero recibir el boletín de la asociación.');
    });

    it('fills in the address and the name of whoever is signed in', () => {
        const wrapper = mountForm({ email: 'ana@example.com', name: 'Ana' });

        expect((wrapper.get('input[type="email"]').element as HTMLInputElement).value).toBe(
            'ana@example.com'
        );
        expect((wrapper.get('input[name="name"]').element as HTMLInputElement).value).toBe('Ana');
    });

    it('leaves the name out when the block does not ask for it', () => {
        const wrapper = mountForm({ showName: false });

        expect(wrapper.find('input[name="name"]').exists()).toBe(false);
    });

    it('shows the intro and the button text chosen for the block', () => {
        const wrapper = mountForm({ intro: 'Novedades cada mes', buttonLabel: 'Apuntarme' });

        expect(wrapper.text()).toContain('Novedades cada mes');
        expect(wrapper.get('button[type="submit"]').text()).toBe('Apuntarme');
    });

    it('links the privacy policy, in a new tab only when it is another site', () => {
        expect(mountForm().get('a[href="/privacidad"]').attributes('target')).toBeUndefined();

        const external = mountForm({ privacyUrl: 'https://example.com/privacy' }).get(
            'a[href="https://example.com/privacy"]'
        );
        expect(external.attributes('target')).toBe('_blank');
        expect(external.attributes('rel')).toContain('noopener');
    });

    it('keeps the trap field out of sight, of the tab order and of screen readers', () => {
        const trap = mountForm().get('input[name="website"]');

        expect(trap.attributes('tabindex')).toBe('-1');
        expect(trap.element.closest('[aria-hidden="true"]')).not.toBeNull();
    });

    it('sends the form as JSON with the CSRF token and announces the answer', async () => {
        fetchMock.mockResolvedValue(answer(200, { message: CHECK }));
        const wrapper = mountForm();
        await wrapper.get('input[name="name"]').setValue('  Ana  ');

        await fillAndSend(wrapper);

        expect(fetchMock).toHaveBeenCalledOnce();
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/newsletter');
        expect(init.method).toBe('POST');
        expect(init.headers).toMatchObject({
            Accept: 'application/json',
            'X-XSRF-TOKEN': 'token-123',
        });
        expect(JSON.parse(String(init.body))).toEqual({
            email: 'ana@example.com',
            name: 'Ana',
            consent: true,
            website: '',
        });

        const done = wrapper.get('[data-subscribe-done]');
        expect(done.attributes('role')).toBe('status');
        expect(done.text()).toBe(CHECK);
        expect(document.activeElement).toBe(done.element);
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('puts each error under its field, names the field by it and focuses the first one', async () => {
        fetchMock.mockResolvedValue(
            answer(422, {
                message: 'Datos no válidos',
                errors: {
                    email: ['El correo electrónico no es válido.'],
                    consent: ['Marca la casilla para aceptar recibir el boletín.'],
                },
            })
        );
        const wrapper = mountForm();

        await fillAndSend(wrapper);

        const email = wrapper.get('input[type="email"]');
        expect(email.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${email.attributes('aria-describedby')}`).text()).toBe(
            'El correo electrónico no es válido.'
        );
        const consent = wrapper.get('input[type="checkbox"]');
        expect(wrapper.get(`#${consent.attributes('aria-describedby')}`).text()).toBe(
            'Marca la casilla para aceptar recibir el boletín.'
        );
        expect(document.activeElement).toBe(email.element);
        expect(wrapper.get('input[name="name"]').attributes('aria-describedby')).toBeUndefined();
    });

    it('says so when there were too many attempts', async () => {
        fetchMock.mockResolvedValue(answer(429, { message: 'Has hecho demasiados intentos.' }));
        const wrapper = mountForm();

        await fillAndSend(wrapper);

        expect(wrapper.get('[role="alert"]').text()).toBe('Has hecho demasiados intentos.');
    });

    it('asks to reload a page whose session expired', async () => {
        fetchMock.mockResolvedValue(answer(419, { message: 'CSRF token mismatch.' }));
        const wrapper = mountForm();

        await fillAndSend(wrapper);

        expect(wrapper.get('[role="alert"]').text()).toContain('Recárgala');
    });

    it('never breaks when the network fails', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
        const wrapper = mountForm();

        await fillAndSend(wrapper);

        expect(wrapper.get('[role="alert"]').text()).toContain('No se ha podido enviar');
        expect(wrapper.find('form').exists()).toBe(true);
    });

    it('points to the verification notice when the server asks to verify the email first', async () => {
        fetchMock.mockResolvedValue(answer(403, { error: 'email_verification_required' }));
        const wrapper = mountForm();

        await fillAndSend(wrapper);

        const button = wrapper.get('button[type="submit"]');
        expect(button.attributes('disabled')).toBeDefined();
        expect(wrapper.get(`#${button.attributes('aria-describedby')}`).text()).not.toBe('');
    });

    it('does not send anything while the account must verify its email', async () => {
        signIn({ mustVerifyEmail: true });
        const wrapper = mountForm();

        await fillAndSend(wrapper);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('takes the colours of the dark footer when asked', () => {
        const wrapper = mount(SubscribeForm, { props: { ...props(), tone: 'dark' } });

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-neutral-900');
    });
});
