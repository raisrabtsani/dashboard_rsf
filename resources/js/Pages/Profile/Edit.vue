<script setup>
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ status: { type: String } });
</script>

<template>
    <Head title="Ganti Password" />

    <div class="min-h-screen border-t-[3px] border-[#0756bd] bg-[#f4f8fd] px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto w-full max-w-[1180px]">
            <Link
                :href="route('dashboard')"
                class="mb-8 inline-flex items-center gap-2 text-sm font-semibold text-[#304663] transition hover:text-[#0756bd]"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5" />
                    <path d="m12 19-7-7 7-7" />
                </svg>
                Kembali ke Dashboard
            </Link>

            <div class="grid overflow-hidden rounded-[28px] bg-white shadow-[0_24px_70px_rgba(15,55,105,0.16)] ring-1 ring-slate-200/80 lg:grid-cols-[0.82fr_1.18fr]">
                <aside class="relative overflow-hidden bg-gradient-to-br from-[#0756bd] via-[#0d6ad6] to-[#2085ed] px-8 py-10 text-white sm:px-10 lg:min-h-[610px] lg:px-12 lg:py-12">
                    <div class="pointer-events-none absolute -right-16 -top-20 h-72 w-72 rounded-full bg-white/[0.055]" />
                    <div class="pointer-events-none absolute right-10 top-0 h-56 w-56 rounded-full border-[36px] border-white/[0.035]" />
                    <div class="pointer-events-none absolute -bottom-24 -left-16 h-64 w-64 rounded-full bg-white/[0.035]" />

                    <div class="relative flex h-full flex-col">
                        <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 shadow-sm ring-1 ring-white/25 backdrop-blur-sm">
                            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
                                <path d="m9 12 2 2 4-4" />
                            </svg>
                        </div>

                        <div class="mt-12">
                            <p class="text-xs font-extrabold uppercase tracking-[0.24em] text-cyan-100/90">Keamanan Akun</p>
                            <h1 class="mt-4 text-4xl font-extrabold tracking-tight sm:text-[42px]">Ganti Password</h1>
                            <p class="mt-4 max-w-sm text-[17px] leading-7 text-white/90">
                                Perbarui password secara berkala untuk menjaga keamanan akun Dashboard Keragaan.
                            </p>
                        </div>

                        <div class="my-9 h-px bg-white/20" />

                        <div class="space-y-5 text-sm font-medium text-white/95">
                            <div class="flex items-center gap-3">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-cyan-100/80">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2">
                                        <path d="m5 10 3 3 7-7" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                                Gunakan minimal 8 karakter
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-cyan-100/80">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2">
                                        <path d="m5 10 3 3 7-7" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                                Gabungkan huruf besar, huruf kecil, dan angka
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-cyan-100/80">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2">
                                        <path d="m5 10 3 3 7-7" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                                Hindari menggunakan nama atau email
                            </div>
                        </div>

                        <p class="mt-12 text-sm text-cyan-100/80 lg:mt-auto">Regional Strategy and Finance</p>
                    </div>
                </aside>

                <main class="px-6 py-9 sm:px-10 lg:px-14 lg:py-12">
                    <div class="mx-auto max-w-[610px]">
                        <div class="flex items-start gap-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-[#0756bd]">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="8" cy="15" r="3" />
                                    <path d="m10.5 12.5 6-6a3 3 0 1 1 4.2 4.2l-6 6" />
                                    <path d="m15 8 2 2" />
                                </svg>
                            </span>
                            <div>
                                <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">Buat password baru</h2>
                                <p class="mt-1 text-sm text-slate-500">Masukkan password lama, lalu tentukan password baru.</p>
                            </div>
                        </div>

                        <div
                            v-if="status === 'password-updated'"
                            class="mt-6 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m5 10 3 3 7-7" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Password berhasil diperbarui.
                        </div>

                        <UpdatePasswordForm />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>
