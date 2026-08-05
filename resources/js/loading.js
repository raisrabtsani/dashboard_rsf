import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * State loading global untuk LoadingScreen.
 *
 * Layar loading besar (gambar "Memuat dashboard") hanya muncul untuk navigasi
 * yang benar-benar lambat — ditunda 250 ms supaya perpindahan cepat tidak
 * berkedip. Progress bar tipis bawaan Inertia tetap jalan untuk yang cepat.
 */
export const loading = reactive({ aktif: false });

let timer = null;

router.on('start', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        loading.aktif = true;
    }, 250);
});

const selesai = () => {
    clearTimeout(timer);
    loading.aktif = false;
};

router.on('finish', selesai);
router.on('error', selesai);
