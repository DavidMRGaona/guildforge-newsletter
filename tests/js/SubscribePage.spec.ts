import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Subscribe from '../../resources/js/pages/Newsletter/Subscribe.vue';

describe('Newsletter/Subscribe', () => {
    it('shows a heading and the form', () => {
        const wrapper = mount(Subscribe, {
            props: {
                form: {
                    action: '/newsletter',
                    intro: null,
                    buttonLabel: null,
                    showName: true,
                    consentText: 'Quiero recibir el boletín.',
                    privacyUrl: '/privacidad',
                    email: 'ana@example.com',
                    name: null,
                },
            },
        });

        expect(wrapper.get('h1').text()).toBe('Boletín');
        expect((wrapper.get('input[type="email"]').element as HTMLInputElement).value).toBe(
            'ana@example.com'
        );
        expect(wrapper.get('button[type="submit"]').text()).toBe('Suscribirme');
    });
});
