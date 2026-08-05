<script setup>
/**
 * Tombol "Terapkan" pada filter bar.
 *
 * Pola pending vs applied: filter yang diubah user BELUM memicu fetch sampai
 * tombol ini ditekan. `dirty` menandai ada perubahan yang belum diterapkan.
 */
defineProps({
    loading: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

defineEmits(['click']);
</script>

<template>
    <button
        type="button"
        class="relative inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="disabled || loading"
        @click="$emit('click')"
    >
        <span
            v-if="dirty && !loading"
            class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-amber-400 ring-2 ring-white"
            aria-hidden="true"
        />

        <svg
            v-if="loading"
            class="h-4 w-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>

        <slot>Terapkan</slot>
    </button>
</template>
