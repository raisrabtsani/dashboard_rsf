<script setup>
import InputError from '@/Components/InputError.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);
const lihatCurrent = ref(false);
const lihatPassword = ref(false);
const lihatKonfirmasi = ref(false);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <form class="mt-9" @submit.prevent="updatePassword">
        <div class="space-y-6">
            <div>
                <label for="current_password" class="mb-2 block text-sm font-bold text-slate-800">Password saat ini</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="14" height="11" x="5" y="10" rx="2" />
                            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                        </svg>
                    </span>
                    <input
                        id="current_password"
                        ref="currentPasswordInput"
                        v-model="form.current_password"
                        :type="lihatCurrent ? 'text' : 'password'"
                        autocomplete="current-password"
                        placeholder="Masukkan password saat ini"
                        class="h-13 w-full rounded-xl border border-slate-300 bg-white py-3 pl-12 pr-12 text-sm font-medium text-slate-800 shadow-sm placeholder:text-slate-400 focus:border-[#2a7be4] focus:ring-[#2a7be4]"
                    />
                    <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" @click="lihatCurrent = !lihatCurrent">
                        <svg v-if="!lihatCurrent" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
                            <circle cx="12" cy="12" r="2.5" />
                        </svg>
                        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m3 3 18 18" />
                            <path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6.5 0 10 6 10 6a17 17 0 0 1-2.2 3" />
                            <path d="M6.6 6.6C3.6 8.5 2 12 2 12s3.5 6 10 6a9.8 9.8 0 0 0 4-.8" />
                        </svg>
                    </button>
                </div>
                <InputError :message="form.errors.current_password" class="mt-2" />
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-bold text-slate-800">Password baru</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="8" cy="15" r="3" />
                            <path d="m10.5 12.5 6-6a3 3 0 1 1 4.2 4.2l-6 6" />
                        </svg>
                    </span>
                    <input
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        :type="lihatPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        placeholder="Masukkan password baru"
                        class="h-13 w-full rounded-xl border border-slate-300 bg-white py-3 pl-12 pr-12 text-sm font-medium text-slate-800 shadow-sm placeholder:text-slate-400 focus:border-[#2a7be4] focus:ring-[#2a7be4]"
                    />
                    <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" @click="lihatPassword = !lihatPassword">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
                            <circle cx="12" cy="12" r="2.5" />
                        </svg>
                    </button>
                </div>
                <InputError :message="form.errors.password" class="mt-2" />
            </div>

            <div>
                <label for="password_confirmation" class="mb-2 block text-sm font-bold text-slate-800">Konfirmasi password baru</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="8" cy="15" r="3" />
                            <path d="m10.5 12.5 6-6a3 3 0 1 1 4.2 4.2l-6 6" />
                        </svg>
                    </span>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="lihatKonfirmasi ? 'text' : 'password'"
                        autocomplete="new-password"
                        placeholder="Ulangi password baru"
                        class="h-13 w-full rounded-xl border border-slate-300 bg-white py-3 pl-12 pr-12 text-sm font-medium text-slate-800 shadow-sm placeholder:text-slate-400 focus:border-[#2a7be4] focus:ring-[#2a7be4]"
                    />
                    <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" @click="lihatKonfirmasi = !lihatKonfirmasi">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
                            <circle cx="12" cy="12" r="2.5" />
                        </svg>
                    </button>
                </div>
                <InputError :message="form.errors.password_confirmation" class="mt-2" />
            </div>
        </div>

        <div class="mt-7 border-t border-slate-200 pt-6">
            <div class="flex flex-wrap justify-end gap-3">
                <Link
                    :href="route('dashboard')"
                    class="inline-flex h-11 min-w-[86px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    Batal
                </Link>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#0756bd] px-5 text-sm font-bold text-white shadow-[0_8px_18px_rgba(7,86,189,0.24)] transition hover:bg-[#064ba7] disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                        <path d="M17 21v-8H7v8" />
                        <path d="M7 3v5h8" />
                    </svg>
                    {{ form.processing ? 'Menyimpan...' : 'Simpan Password' }}
                </button>
            </div>
        </div>
    </form>
</template>
