import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import ProfileNewsletterSection from '../../resources/js/components/profile/ProfileNewsletterSection.vue';
import type { ProfileNewsletter } from '../../resources/js/types/profile';

const ENDPOINT = '/perfil/boletin';

function answer(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

async function mountSection(
    profileNewsletter: ProfileNewsletter | null = {
        endpoint: ENDPOINT,
        verified: true,
        verifyUrl: '/verificar-email',
    }
): Promise<VueWrapper> {
    const wrapper = mount(ProfileNewsletterSection, {
        props: { profileNewsletter, profileNewsletterVisible: true },
        attachTo: document.body,
    });
    await flushPromises();

    return wrapper;
}

describe('ProfileNewsletterSection', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        document.cookie = 'XSRF-TOKEN=token-123';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('asks for the status when it is shown, without sending anything else', async () => {
        fetchMock.mockResolvedValue(answer(200, { status: 'unsubscribed' }));

        await mountSection();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(ENDPOINT);
        expect(init.method).toBe('GET');
    });

    it('draws nothing and asks nothing without the page prop', async () => {
        const wrapper = await mountSection(null);

        expect(wrapper.find('[data-testid="profile-newsletter"]').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('offers to unsubscribe when subscribed', async () => {
        fetchMock.mockResolvedValue(answer(200, { status: 'subscribed' }));

        const wrapper = await mountSection();

        expect(wrapper.text()).toContain('Estás suscrito al boletín');
        expect(wrapper.get('button').text()).toBe('Darme de baja');
    });

    it('says a confirmation was sent when pending, with no button', async () => {
        fetchMock.mockResolvedValue(answer(200, { status: 'pending' }));

        const wrapper = await mountSection();

        expect(wrapper.text()).toContain('Te hemos enviado un correo para confirmar');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('offers to subscribe when not on the list', async () => {
        fetchMock.mockResolvedValue(answer(200, { status: 'unsubscribed' }));

        const wrapper = await mountSection();

        expect(wrapper.get('button').text()).toBe('Suscribirme');
    });

    it('explains a bounced address and offers no button', async () => {
        fetchMock.mockResolvedValue(answer(200, { status: 'blocked' }));

        const wrapper = await mountSection();

        expect(wrapper.text()).toContain('no puede recibir el boletín');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('subscribes with a POST carrying the CSRF token and shows the confirmation', async () => {
        fetchMock
            .mockResolvedValueOnce(answer(200, { status: 'unsubscribed' }))
            .mockResolvedValueOnce(answer(200, { status: 'pending' }));

        const wrapper = await mountSection();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        const [, init] = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(init.method).toBe('POST');
        expect(JSON.stringify(init.headers)).toContain('token-123');
        expect(wrapper.text()).toContain('Te hemos enviado un correo para confirmar');
    });

    it('unsubscribes with a DELETE and shows the result', async () => {
        fetchMock
            .mockResolvedValueOnce(answer(200, { status: 'subscribed' }))
            .mockResolvedValueOnce(answer(200, { status: 'unsubscribed' }));

        const wrapper = await mountSection();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect((fetchMock.mock.calls[1] as [string, RequestInit])[1].method).toBe('DELETE');
        expect(wrapper.text()).toContain('No estás suscrito');
    });

    it('lets the person try again when the status could not be read', async () => {
        fetchMock
            .mockResolvedValueOnce(answer(200, { status: 'unavailable' }))
            .mockResolvedValueOnce(answer(200, { status: 'subscribed' }));

        const wrapper = await mountSection();
        expect(wrapper.text()).toContain('No hemos podido consultar');

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Estás suscrito');
    });

    it('shows the verification message to someone who must verify their email', async () => {
        fetchMock.mockResolvedValue(answer(403, { error: 'email_verification_required' }));

        const wrapper = await mountSection();

        expect(wrapper.get('[role="alert"]').text()).toContain('Verifica el correo');
    });

    it('shows the limit message when too many attempts were made', async () => {
        fetchMock
            .mockResolvedValueOnce(answer(200, { status: 'subscribed' }))
            .mockResolvedValueOnce(answer(429, { message: 'x' }));

        const wrapper = await mountSection();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('demasiados intentos');
        expect(wrapper.text()).toContain('Estás suscrito');
    });

    it('never lets a network error escape', async () => {
        fetchMock.mockRejectedValue(new TypeError('offline'));

        const wrapper = await mountSection();

        expect(wrapper.get('[role="alert"]').text()).toContain('No se ha podido completar');
    });

    it('shows only the verification message, with a link, and asks nothing when the email is not verified', async () => {
        const wrapper = await mountSection({
            endpoint: ENDPOINT,
            verified: false,
            verifyUrl: '/verificar-email',
        });

        expect(wrapper.get('[data-testid="newsletter-verify"]').text()).toContain(
            'verifica primero'
        );
        expect(wrapper.get('a').attributes('href')).toBe('/verificar-email');
        expect(wrapper.find('button').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('announces the status in a polite live region', async () => {
        fetchMock.mockResolvedValue(answer(200, { status: 'subscribed' }));

        const wrapper = await mountSection();
        const line = wrapper.get('[data-testid="newsletter-status"]');

        expect(line.attributes('role')).toBe('status');
        expect(line.attributes('aria-live')).toBe('polite');
    });

    it('moves the focus to the status when the pressed button goes away', async () => {
        fetchMock
            .mockResolvedValueOnce(answer(200, { status: 'subscribed' }))
            .mockResolvedValueOnce(answer(200, { status: 'unsubscribed' }));

        const wrapper = await mountSection();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(
            wrapper.get('[data-testid="newsletter-status"]').element
        );
    });
});
