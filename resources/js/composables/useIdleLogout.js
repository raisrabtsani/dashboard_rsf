import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, onUnmounted } from 'vue';

/**
 * Auto-logout saat sesi menganggur (idle).
 *
 * Melengkapi penegakan server: SESSION_LIFETIME=10 membuat sesi kedaluwarsa 10
 * menit setelah request terakhir (driver database). Composable ini menutup sisi
 * KLIEN — setelah `menit` tanpa aktivitas, user diarahkan logout, jadi layar tidak
 * ditinggalkan terbuka pasca sesi mati.
 *
 * "Aktivitas" = interaksi user (gerak/klik/ketik/scroll/sentuh) ATAU lalu-lintas
 * jaringan aplikasi (respons axios). Yang kedua disengaja: layar videotron yang
 * auto-refresh tiap 2 menit tetap dianggap aktif dan TIDAK ikut ter-logout,
 * sementara halaman diam tanpa polling apa pun tetap logout tepat waktu.
 *
 * @param {number} menit  ambang idle; <= 0 mematikan fitur.
 */
export function useIdleLogout(menit) {
    const batasMs = Number(menit) * 60_000;

    let timer = null;
    let interceptorId = null;
    let terakhirReset = 0;

    const keluar = () => {
        // Kirim sekali; router.post akan mengarahkan ke halaman login.
        router.post(route('logout'));
    };

    const reset = () => {
        // Throttle: reset paling sering tiap 1 detik supaya mousemove tidak
        // menjadwal ulang timer ribuan kali.
        const now = Date.now();
        if (now - terakhirReset < 1_000) return;
        terakhirReset = now;

        if (timer) clearTimeout(timer);
        timer = setTimeout(keluar, batasMs);
    };

    const EVENTS = ['pointerdown', 'keydown', 'mousemove', 'scroll', 'touchstart', 'wheel'];

    onMounted(() => {
        if (!(batasMs > 0) || typeof window === 'undefined') return;

        EVENTS.forEach((e) => window.addEventListener(e, reset, { passive: true }));

        // Aktivitas jaringan aplikasi juga menghitung sebagai "tidak idle".
        interceptorId = axios.interceptors.response.use(
            (r) => {
                reset();

                return r;
            },
            (e) => {
                reset();

                return Promise.reject(e);
            },
        );

        terakhirReset = Date.now();
        timer = setTimeout(keluar, batasMs);
    });

    onUnmounted(() => {
        if (timer) clearTimeout(timer);
        EVENTS.forEach((e) => window.removeEventListener(e, reset));
        if (interceptorId !== null) axios.interceptors.response.eject(interceptorId);
    });
}
