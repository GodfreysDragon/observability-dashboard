<script setup>
import { ref } from 'vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    /** Hide the chart/table switch when the card has no table twin. */
    tableToggle: { type: Boolean, default: true },
});

const showTable = ref(false);
</script>

<template>
    <section class="card min-w-0 p-4 sm:p-5">
        <header class="mb-4 flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-ink">{{ title }}</h2>
                <p v-if="subtitle || $slots.subtitle" class="mt-0.5 text-xs text-muted">
                    <slot name="subtitle">{{ subtitle }}</slot>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <slot name="actions" />
                <div v-if="tableToggle" class="flex rounded-lg border border-line p-0.5 text-xs" role="group" aria-label="View as">
                    <button
                        type="button"
                        class="rounded-md px-2 py-0.5"
                        :class="!showTable ? 'bg-surface-2 font-medium text-ink' : 'text-muted hover:text-ink'"
                        :aria-pressed="!showTable"
                        @click="showTable = false"
                    >
                        Chart
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-2 py-0.5"
                        :class="showTable ? 'bg-surface-2 font-medium text-ink' : 'text-muted hover:text-ink'"
                        :aria-pressed="showTable"
                        @click="showTable = true"
                    >
                        Table
                    </button>
                </div>
            </div>
        </header>
        <div class="transition-opacity" :class="loading ? 'opacity-50' : ''" :aria-busy="loading">
            <div v-if="showTable && $slots.table" class="max-h-96 overflow-auto">
                <slot name="table" />
            </div>
            <slot v-else />
        </div>
    </section>
</template>
