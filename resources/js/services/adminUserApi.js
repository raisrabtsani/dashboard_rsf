import axios from 'axios';

/** Satu-satunya tempat axios untuk manajemen user. */

export const fetchUsers = (filter = {}) =>
    axios
        .get(route('admin.users.data'), {
            params: Object.fromEntries(
                Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
            ),
        })
        .then((r) => r.data);

export const fetchUkerPerCabang = (cabangId) =>
    axios.get(route('admin.users.uker', { cabangId })).then((r) => r.data);

export const simpanUser = (payload) =>
    axios.post(route('admin.users.store'), payload).then((r) => r.data);

export const perbaruiUser = (id, payload) =>
    axios.put(route('admin.users.update', { user: id }), payload).then((r) => r.data);

export const hapusUser = (id) =>
    axios.delete(route('admin.users.destroy', { user: id })).then((r) => r.data);

export const toggleKunci = (id) =>
    axios.patch(route('admin.users.toggle-lock', { user: id })).then((r) => r.data);
