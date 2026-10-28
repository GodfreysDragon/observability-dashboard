<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    frames: { type: Array, required: true },
});

const showVendor = ref(false);

/** Vendor frames collapse into runs so the application frames stand out. */
const visible = computed(() => {
    if (showVendor.value) return props.frames.map((f) => ({ ...f, collapsed: 0 }));
    const out = [];
    for (const frame of props.frames) {
        const last = out[out.length - 1];
        if (!frame.app && last && !last.app) last.collapsed++;
        else out.push({ ...frame, collapsed: frame.app ? 0 : 1 });
    }
    return out;
});
</script>

<template>
    <div>
        <ol class="divide-y divide-line rounded-lg border border-line">
            <li v-for="(f, i) in visible" :key="i" class="px-3 py-1.5" :class="f.app ? 'bg-surface' : 'bg-surface-2'">
                <template v-if="f.app || showVendor">
                    <div class="mono break-all" :class="f.app ? 'font-medium text-ink' : 'text-muted'">{{ f.file }}<span class="text-muted">:{{ f.line }}</span></div>
                    <div class="mono break-all text-muted">{{ f.call }}</div>
                </template>
                <div v-else class="text-xs text-muted">{{ f.collapsed }} vendor frame{{ f.collapsed === 1 ? '' : 's' }}</div>
            </li>
        </ol>
        <button type="button" class="link mt-2 text-xs" @click="showVendor = !showVendor">{{ showVendor ? 'Hide' : 'Show' }} vendor frames</button>
    </div>
</template>
