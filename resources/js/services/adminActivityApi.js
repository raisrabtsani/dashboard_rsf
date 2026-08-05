import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk halaman Admin > Aktivitas Pengguna.
 */
export const fetchAktivitas = () =>
    axios.get(route('admin.activity.data')).then((r) => r.data);
