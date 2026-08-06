import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk halaman PH & Net DG.
 *
 * `mode` (ph|netdg) dikirim sebagai parameter biasa — dua "domain" berbagi
 * endpoint yang sama karena keduanya memakai sumbu waktu & scope yang identik.
 */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.recovery-ph.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.recovery-ph.snapshot', filter);

export const fetchChart = (filter) => ambil('api.recovery-ph.chart', filter);

export const fetchBranchPencapaian = (filter) => ambil('api.recovery-ph.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.recovery-ph.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.recovery-ph.uker', { cabangId })).then((r) => r.data);
