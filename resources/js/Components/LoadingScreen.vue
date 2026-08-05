<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { loading } from '@/loading';
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="loading.aktif"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-100/80 backdrop-blur-sm"
                role="status"
                aria-live="polite"
            >
                <div class="w-80 max-w-[90vw] rounded-3xl bg-white p-8 text-center shadow-2xl ring-1 ring-slate-200">
                    <div class="relative mx-auto flex h-20 w-20 items-center justify-center">
                        <span class="absolute inset-0 animate-ping rounded-2xl bg-brand-500/20" />
                        <span class="absolute inset-2 rounded-2xl ring-2 ring-brand-200" />
                        <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-600 to-brand-500 text-white shadow-lg shadow-brand-600/30">
                            <ApplicationLogo class="h-9 w-9" />
                        </span>
                    </div>

                    <p class="mt-5 text-xs font-bold uppercase tracking-widest text-brand-600">Dashboard Keragaan</p>
                    <p class="mt-1 text-lg font-bold text-slate-900">Memuat dashboard</p>
                    <p class="mt-1 text-sm text-slate-500">Data cukup besar. Mohon tunggu, proses masih berjalan.</p>

                    <div class="mt-5 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                        <div class="loading-bar h-full rounded-full bg-gradient-to-r from-brand-600 to-brand-400" />
                    </div>
                    <p class="mt-2 text-right text-[11px] font-medium text-slate-400">Mohon tunggu…</p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.loading-bar {
    width: 40%;
    animation: geser 1.1s ease-in-out infinite;
}

@keyframes geser {
    0% {
        margin-left: -40%;
    }
    100% {
        margin-left: 100%;
    }
}
</style>
