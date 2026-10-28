<script setup>
import { computed } from 'vue';
import { splitRoute } from '../lib/format';

const props = defineProps({
    route: { type: String, required: true },
    link: { type: Boolean, default: true },
});

const parts = computed(() => splitRoute(props.route));
</script>

<template>
    <component
        :is="link ? 'router-link' : 'span'"
        :to="link ? { name: 'route', query: { route } } : undefined"
        class="inline-flex max-w-full min-w-0 items-baseline gap-1.5"
        :class="link ? 'hover:underline' : ''"
    >
        <span class="w-11 shrink-0 text-[10px] font-semibold tracking-wide text-muted">{{ parts[0] }}</span>
        <span class="mono truncate text-ink">{{ parts[1] }}</span>
    </component>
</template>
