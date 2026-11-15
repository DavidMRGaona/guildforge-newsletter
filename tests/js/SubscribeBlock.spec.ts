import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, type Component, type VNode } from 'vue';
import { mount } from '@vue/test-utils';
import SubscribeBlock from '../../resources/js/components/SubscribeBlock.vue';
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

function inside(markup: 'footer-column' | 'popup-with-footer'): Component {
    return defineComponent({
        setup() {
            return (): VNode =>
                markup === 'footer-column'
                    ? h('footer', [
                          h('div', { 'data-footer-column': '' }, [h(SubscribeBlock, props)]),
                      ])
                    : // A popup whose own content has a <footer>: not the site footer
                      h('div', { role: 'dialog' }, [h('footer', [h(SubscribeBlock, props)])]);
        },
    });
}

describe('SubscribeBlock', () => {
    it('draws the form with the props the server resolved', () => {
        const wrapper = mount(SubscribeBlock, { props });

        expect(wrapper.text()).toContain('Novedades cada mes');
        expect(wrapper.find('form[action="/newsletter"]').exists()).toBe(true);
    });

    it('is dark in a column of the site footer', async () => {
        const wrapper = mount(inside('footer-column'), { attachTo: document.body });
        // The tone is read once the block is in the page
        await nextTick();

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-neutral-900');
    });

    it('follows the page anywhere else, even inside some other <footer>', async () => {
        const wrapper = mount(inside('popup-with-footer'), { attachTo: document.body });
        await nextTick();

        expect(wrapper.get('input[type="email"]').classes()).toContain('bg-surface');
    });
});
