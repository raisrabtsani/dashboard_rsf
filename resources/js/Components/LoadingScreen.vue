<script setup>
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
                <div class="loader-card w-[380px] max-w-[92vw] overflow-hidden rounded-[2rem] px-8 py-8 text-center shadow-2xl ring-1 ring-slate-200">
                    <div class="loader-icon-wrap mx-auto">
                        <span class="loader-glow" />
                        <span class="loader-orbit loader-orbit-a" />
                        <span class="loader-orbit loader-orbit-b" />
                        <div class="loader-icon">
                            <div class="loader-bars">
                                <span class="loader-bar loader-bar-1" />
                                <span class="loader-bar loader-bar-2" />
                                <span class="loader-bar loader-bar-3" />
                                <span class="loader-bar loader-bar-4" />
                            </div>
                        </div>
                    </div>

                    <p class="mt-5 text-xs font-bold uppercase tracking-[0.28em] text-brand-600">Dashboard Keragaan</p>
                    <p class="mt-2 text-[2.05rem] font-extrabold leading-tight text-slate-900">Memuat dashboard</p>
                    <p class="mt-3 text-[15px] leading-8 text-slate-500">Data cukup besar. Mohon tunggu proses masih berjalan.</p>

                    <div class="mt-8 h-4 w-full overflow-hidden rounded-full bg-slate-100 p-1">
                        <div class="loader-progress-fill h-full rounded-full" />
                    </div>

                    <div class="mt-3 flex items-center justify-between text-sm font-bold">
                        <span class="text-slate-500">Mohon tunggu</span>
                        <span class="text-slate-400">100%</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.loader-card {
    background:
        radial-gradient(circle at left bottom, rgba(103, 217, 255, 0.16), transparent 30%),
        radial-gradient(circle at right top, rgba(59, 130, 246, 0.12), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

.loader-icon-wrap {
    position: relative;
    display: flex;
    height: 118px;
    width: 118px;
    align-items: center;
    justify-content: center;
}

.loader-glow {
    position: absolute;
    inset: 12px;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0.08) 50%, transparent 75%);
    animation: glowPulse 1.8s ease-in-out infinite;
}

.loader-orbit {
    position: absolute;
    inset: 0;
    border-radius: 9999px;
}

.loader-orbit-a {
    border: 3px solid transparent;
    border-top-color: rgba(59, 130, 246, 0.9);
    border-right-color: rgba(59, 130, 246, 0.55);
    animation: spinOrbit 2.4s linear infinite;
}

.loader-orbit-b {
    inset: 8px;
    border: 3px solid transparent;
    border-bottom-color: rgba(56, 189, 248, 0.85);
    border-left-color: rgba(56, 189, 248, 0.45);
    animation: spinOrbitReverse 1.7s linear infinite;
}

.loader-icon {
    position: relative;
    z-index: 1;
    display: flex;
    height: 86px;
    width: 86px;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    background: linear-gradient(145deg, #0a66dc 0%, #0a56c7 55%, #0648a8 100%);
    box-shadow:
        0 18px 34px rgba(8, 87, 195, 0.34),
        inset 0 1px 0 rgba(255, 255, 255, 0.18);
    animation: floatIcon 2.1s ease-in-out infinite;
}

.loader-icon::after {
    content: '';
    position: absolute;
    inset: 6px;
    border-radius: 9999px;
    border: 2px solid rgba(255, 255, 255, 0.18);
}

.loader-bars {
    display: flex;
    height: 34px;
    align-items: flex-end;
    gap: 6px;
}

.loader-bar {
    display: block;
    width: 6px;
    border-radius: 9999px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(222, 238, 255, 0.88));
    box-shadow: 0 0 12px rgba(255, 255, 255, 0.2);
    transform-origin: bottom center;
    animation: barPulse 1s ease-in-out infinite;
}

.loader-bar-1 {
    height: 20px;
    animation-delay: 0s;
}

.loader-bar-2 {
    height: 29px;
    animation-delay: 0.14s;
}

.loader-bar-3 {
    height: 24px;
    animation-delay: 0.28s;
}

.loader-bar-4 {
    height: 33px;
    animation-delay: 0.42s;
}

.loader-progress-fill {
    width: 100%;
    background: linear-gradient(90deg, #2a6ff6 0%, #2d90ff 52%, #1dc6ef 100%);
    box-shadow: 0 6px 16px rgba(42, 111, 246, 0.28);
    transform-origin: left center;
    animation: progressGlow 1.6s ease-in-out infinite;
}

@keyframes spinOrbit {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

@keyframes spinOrbitReverse {
    from {
        transform: rotate(360deg);
    }
    to {
        transform: rotate(0deg);
    }
}

@keyframes floatIcon {
    0%,
    100% {
        transform: translateY(0px) scale(1);
    }
    50% {
        transform: translateY(-4px) scale(1.02);
    }
}

@keyframes glowPulse {
    0%,
    100% {
        transform: scale(0.94);
        opacity: 0.55;
    }
    50% {
        transform: scale(1.06);
        opacity: 0.95;
    }
}

@keyframes barPulse {
    0%,
    100% {
        transform: scaleY(0.72);
        opacity: 0.7;
    }
    50% {
        transform: scaleY(1.08);
        opacity: 1;
    }
}

@keyframes progressGlow {
    0%,
    100% {
        opacity: 0.88;
        transform: scaleX(0.96);
    }
    50% {
        opacity: 1;
        transform: scaleX(1);
    }
}
</style>
