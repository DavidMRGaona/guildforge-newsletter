import { describe, expect, it } from 'vitest';
import { computed, defineComponent, h, provide, type PropType, type VNode } from 'vue';
import { mount } from '@vue/test-utils';
import SubscribeBlock from '../../resources/js/components/SubscribeBlock.vue';
import { SITE_BLOCK_PLACEMENT } from '@/composables/useSiteBlockPlacement';
import type { SiteBlockPlacement } from '@/types/siteBlocks';
import type { SubscribeFormProps } from '../../resources/js/types/subscribe';

const props: SubscribeFormProps = {
    action: '/newsletter',
    intro: 'Novedades cada mes',
    buttonLabel: null,
    showName: true,
    consentText: 'Quiero recibir el boletín.',
    privacyUrl: '/privacidad',
    email: null,
    name: null,
};

// One component gives the block its placement, optionally inside the markup the footer used to be told by
const Harness = defineComponent({
    props: {
        placement: { type: String as PropType<SiteBlockPlacement>, required: true },
        wrapped: { type: Boolean, default: false },
    },
    setup(harnessProps) {
        provide(
            SITE_BLOCK_PLACEMENT,
            computed(() => harnessProps.placement)
        );

        return (): VNode => {
            const block = h(SubscribeBlock, props);

            return harnessProps.wrapped
                ? h('footer', [h('div', { 'data-footer-column': '' }, [block])])
                : block;
        };
    },
});

describe('SubscribeBlock', () => {
    it('draws the form with the props the server resolved', () => {
        const wrapper = mount(SubscribeBlock, { props });

        expect(wrapper.text()).toContain('Novedades cada mes');
        expect(wrapper.find('form[action="/newsletter"]').exists()).toBe(true);
    });

    it('is dark in the footer', () => {
        const wrapper = mount(Harness, { props: { placement: 'footer' } });

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-neutral-900');
    });

    it('follows the page in a popup', () => {
        const wrapper = mount(Harness, { props: { placement: 'popup' } });

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-surface');
    });

    it('follows the page on the link page, with the whole form', () => {
        const wrapper = mount(Harness, { props: { placement: 'link_page' } });
        const input = wrapper.get('input[type="email"]');
        const label = wrapper.get(`label[for="${input.attributes('id')}"]`);

        expect(input.classes()).toContain('bg-surface');
        expect(label.classes()).not.toContain('sr-only');
    });

    it('does not look at the markup around it', () => {
        const wrapper = mount(Harness, {
            props: { placement: 'popup', wrapped: true },
        });

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-surface');
    });

    describe('compact in the footer (newsletter#17)', () => {
        it('puts the email label out of sight and uses a placeholder', () => {
            const wrapper = mount(Harness, { props: { placement: 'footer' } });
            const input = wrapper.get('input[type="email"]');
            const label = wrapper.get(`label[for="${input.attributes('id')}"]`);

            expect(label.classes()).toContain('sr-only');
            expect(label.text()).toContain('Correo electrónico');
            expect(input.attributes('placeholder')).toBe('Correo electrónico');
        });

        it('keeps the usual label and no placeholder in a popup', () => {
            const wrapper = mount(Harness, { props: { placement: 'popup' } });
            const input = wrapper.get('input[type="email"]');
            const label = wrapper.get(`label[for="${input.attributes('id')}"]`);

            expect(label.classes()).not.toContain('sr-only');
            expect(input.attributes('placeholder')).toBeUndefined();
        });

        it('draws a dark checkbox with small text and the policy link inside the same sentence', () => {
            const wrapper = mount(Harness, { props: { placement: 'footer' } });
            const checkbox = wrapper.get('input[type="checkbox"]');
            const text = wrapper.get('[data-consent-text]');

            expect(checkbox.classes()).toEqual(
                expect.arrayContaining(['border-neutral-500', 'bg-neutral-900'])
            );
            expect(text.classes()).toContain('text-xs');
            expect(text.get('a').attributes('href')).toBe('/privacidad');
            expect(text.text()).toContain('Quiero recibir el boletín.');
        });

        it('keeps the policy link on its own line in a popup', () => {
            const wrapper = mount(Harness, { props: { placement: 'popup' } });

            expect(wrapper.find('[data-consent-text]').exists()).toBe(false);
            expect(wrapper.get('a[href="/privacidad"]').element.parentElement?.className).toContain(
                'pl-6'
            );
            expect(wrapper.get('input[type="checkbox"]').classes()).not.toContain('bg-neutral-900');
        });

        it('lays the email and the button out in a row from sm', () => {
            const wrapper = mount(Harness, { props: { placement: 'footer' } });

            expect(wrapper.get('form').classes().join(' ')).toContain('sm:grid-cols-');
            expect(wrapper.get('button[type="submit"]').element.parentElement?.className).toContain(
                'sm:col-start-2'
            );
        });

        it('uses the small button in the footer and the usual one elsewhere', () => {
            const footer = mount(Harness, { props: { placement: 'footer' } });
            const popup = mount(Harness, { props: { placement: 'popup' } });

            expect(footer.get('button[type="submit"]').classes()).toContain('text-sm');
            expect(footer.get('button[type="submit"]').classes()).not.toContain('text-base');
            expect(popup.get('button[type="submit"]').classes()).toContain('text-base');
        });
    });
});
