import { describe, expect, it } from 'vitest';
import { computed, defineComponent, h, provide, type Component, type VNode } from 'vue';
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

function inPlacement(placement: SiteBlockPlacement): Component {
    return defineComponent({
        setup() {
            provide(SITE_BLOCK_PLACEMENT, computed(() => placement));

            return (): VNode => h(SubscribeBlock, props);
        },
    });
}

describe('SubscribeBlock', () => {
    it('draws the form with the props the server resolved', () => {
        const wrapper = mount(SubscribeBlock, { props });

        expect(wrapper.text()).toContain('Novedades cada mes');
        expect(wrapper.find('form[action="/newsletter"]').exists()).toBe(true);
    });

    it('is dark in the footer', () => {
        const wrapper = mount(inPlacement('footer'));

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-neutral-900');
    });

    it('follows the page in a popup', () => {
        const wrapper = mount(inPlacement('popup'));

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-surface');
    });

    it('does not look at the markup around it', () => {
        const wrapper = mount(
            defineComponent({
                setup() {
                    provide(
                        SITE_BLOCK_PLACEMENT,
                        computed(() => 'popup' as const),
                    );

                    return (): VNode =>
                        h('footer', [h('div', { 'data-footer-column': '' }, [h(SubscribeBlock, props)])]);
                },
            }),
        );

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-surface');
    });
});
