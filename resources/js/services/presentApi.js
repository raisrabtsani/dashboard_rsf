import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk halaman PRESENT (rapat pagi Region).
 */
const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchOverview = (filter) => ambil('api.present.overview', filter);

export const fetchArea = (filter) => ambil('api.present.area', filter);

export const fetchDetail = (filter) => ambil('api.present.detail', filter);
