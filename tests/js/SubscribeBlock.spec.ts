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
    privacyUrl: null,
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

    it('does not look at the markup around it', () => {
        const wrapper = mount(Harness, {
            props: { placement: 'popup', wrapped: true },
        });

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-surface');
    });
});
