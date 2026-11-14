<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { SubscribeFormProps } from '../types/subscribe';
import SubscribeForm from './SubscribeForm.vue';

/**
 * Site block `newsletter.subscribe` (core#696), in the footer or inside a popup. The footer is dark in
 * both colour schemes and a popup follows the page, so the form takes its tone from where it is drawn.
 */
defineProps<SubscribeFormProps>();

const root = ref<HTMLElement | null>(null);
const tone = ref<'default' | 'dark'>('default');

onMounted(() => {
    // Before the first paint, so the form never flashes in the wrong colours
    tone.value = root.value?.closest('footer') ? 'dark' : 'default';
});
</script>

<template>
    <div ref="root" data-newsletter-subscribe>
        <SubscribeForm v-bind="$props" :tone="tone" />
    </div>
</template>
