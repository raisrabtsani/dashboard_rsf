import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk halaman landing (ringkasan lintas domain).
 *
 * DILARANG memanggil axios langsung di komponen. Lihat simpananApi.js.
 */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.dashboard.filter-options', filter);

export const fetchRingkasan = (filter) => ambil('api.dashboard.ringkasan', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.dashboard.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.dashboard.uker', { cabangId })).then((r) => r.data);
