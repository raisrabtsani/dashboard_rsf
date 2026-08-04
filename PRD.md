# Product Requirement Document (PRD)

# Dashboard Keragaan RSF

| Atribut     | Nilai                  |
| ----------- | ---------------------- |
| **Produk**  | Dashboard Keragaan RSF |
| **Penulis** | Nurfauzan Hanif        |

---

## Daftar Isi

1. [Ringkasan Eksekutif](#1-ringkasan-eksekutif)
2. [Latar Belakang & Rumusan Masalah](#2-latar-belakang--rumusan-masalah)
3. [Tujuan, Sasaran & Metrik Keberhasilan](#3-tujuan-sasaran--metrik-keberhasilan)
4. [Ruang Lingkup](#4-ruang-lingkup)
5. [Pengguna & Persona](#5-pengguna--persona)
6. [Asumsi, Ketergantungan & Batasan](#6-asumsi-ketergantungan--batasan)
7. [Arsitektur Sistem & Teknologi](#7-arsitektur-sistem--teknologi)
8. [Hierarki Organisasi & Dimensi Data](#8-hierarki-organisasi--dimensi-data)
9. [Model Data](#9-model-data)
10. [Kebutuhan Fungsional](#10-kebutuhan-fungsional)
11. [Aturan Bisnis & Formula](#11-aturan-bisnis--formula)
12. [Kebutuhan Non-Fungsional](#12-kebutuhan-non-fungsional)
13. [Desain UI/UX](#13-desain-uiux)
14. [Inventaris Route & Endpoint API](#14-inventaris-route--endpoint-api)
15. [Import Data & Idempotensi](#15-import-data--idempotensi)
16. [Pengujian & QA](#16-pengujian--qa)
17. [Deployment & Operasional](#17-deployment--operasional)
18. [Glosarium](#18-glosarium)

---

## 1. Ringkasan Eksekutif

**Dashboard Keragaan RSF** adalah aplikasi web internal BRI Regional Office (RO) Jakarta 2
untuk memantau **keragaan (performance) bisnis** di seluruh unit kerja dalam wilayah RSF
secara harian, per jam (khusus DPK), bulanan, dan tahunan.

- Menampilkan **8 domain dashboard**: Simpanan (DPK), DPK Hourly, Pinjaman (Kredit),
  Recovery EC, PH & Net DG, Laba, Merchant EDC, dan Merchant QRIS — plus **landing
  dashboard** ringkasan dan halaman **Present RSF** (slide presentasi tingkat Region).
- Membandingkan **aktual vs target RKA** dengan indikator pencapaian, gap, dan delta
  (D-1 / MTD / YTD / YoY) di setiap level organisasi (Region → Area → Cabang → Uker).
- Menegakkan **kontrol akses berjenjang** — user cabang hanya melihat cabangnya, user uker
  hanya ukernya — di sisi backend (bukan sekadar disembunyikan di UI).
- Menyediakan **area Admin** lengkap: upload data aktual & RKA per domain (CSV/Excel),
  manajemen user, dan monitoring aktivitas/adopsi pengguna.

Aplikasi berjalan sebagai monolit **Laravel 12 + Inertia 2 + Vue 3** tanpa REST API
terpisah, dan optimalkan untuk tiga kondisi tampilan: **desktop/laptop, videotron/TV ruang rapat, dan ponsel**.

---

## 2. Latar Belakang & Rumusan Masalah

### 2.1 Kondisi sebelum

1. Laporan keragaan (DPK, Kredit, Recovery, Laba, Merchant) diolah **manual di Excel**
   oleh staf RO setiap hari.
2. Tidak ada satu tempat untuk melihat **posisi terkini vs target RKA** lintas domain;
   harus menunggu rekap dikirim via grup chat/email.
3. Distribusi file Excel **tidak punya kontrol akses** — user cabang bisa melihat angka
   cabang lain; tidak ada jejak siapa membuka apa.
4. Presentasi (morning call) membutuhkan penyusunan slide manual berulang
   setiap hari dari data yang sama.

### 2.2 Rumusan masalah

> Bagaimana menyediakan satu platform pemantauan keragaan yang **cepat, akurat, terkontrol
> aksesnya, dan siap-presentasi**.

---

## 3. Tujuan, Sasaran & Metrik Keberhasilan

### 3.1 Tujuan

| ID  | Tujuan                                                                                    |
| --- | ----------------------------------------------------------------------------------------- |
| T1  | Satu platform keragaan harian untuk 8 domain bisnis + ringkasan lintas domain.            |
| T2  | Perbandingan aktual vs RKA otomatis (pencapaian %, gap, delta) di semua level organisasi. |
| T3  | Kontrol akses berjenjang yang ditegakkan server-side.                                     |
| T4  | Menghilangkan penyusunan slide morning call manual (halaman Present RSF).                 |
| T5  | Input data tetap sederhana: upload CSV/Excel, idempoten.                                  |
| T6  | Tampilan andal di desktop, videotron/TV, dan ponsel.                                      |

### 3.2 Bukan Tujuan

- **Bukan** sistem input transaksi / core banking.
- **Bukan** API publik — endpoint `api/*` hanya untuk konsumsi frontend internal (session auth).
- **Tidak** ada registrasi mandiri, reset password via email, atau verifikasi email
  (alur Breeze tersebut sengaja dihapus dan dikunci test).

### 3.3 Metrik Keberhasilan

| Metrik                                     | Target                                               | Instrumen                            |
| :----------------------------------------- | :--------------------------------------------------- | :----------------------------------- |
| **Adopsi: User aktif 30 hari**             | Naik berkelanjutan; unit "tidak aktif ≥7 hari" turun | Halaman Admin → Aktivitas Pengguna   |
| **Waktu tersedianya angka posisi harian**  | ≤ beberapa menit setelah file sumber diunggah        | Riwayat upload Admin                 |
| **Kesesuaian angka vs perhitungan bisnis** | 100%                                                 | Uji banding manual + test otomatis   |
| **Kebocoran data lintas scope**            | 0 kejadian                                           | Middleware `EnforceUserScope` + test |

---

## 4. Ruang Lingkup

### 4.1 Dalam Lingkup

| Modul                       | Deskripsi singkat                                                                                                              |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Landing Dashboard           | Ringkasan 4 domain inti + rasio %CASA & %LDR, filter Area/BO/Uker + tanggal.                                                   |
| Dashboard Simpanan (DPK)    | Saldo per produk (Tabungan/Giro/Deposito) & segmentasi, harian.                                                                |
| Dashboard DPK Hourly        | Posisi DPK per jam (only EOM), khusus RO/BO/admin; **auto-refresh 2 mnt** (layar monitoring).                                  |
| Dashboard Pinjaman (Kredit) | Baki debet per segmen & kualitas (Lancar/SML/NPL), tab Total/SML/NPL.                                                          |
| Dashboard Recovery EC       | Pencapaian recovery per segmen (Micro/SME/Consumer).                                                                           |
| Dashboard PH & Net DG       | Flow hapus buku bulanan + Net Downgrade (dihitung on-the-fly).                                                                 |
| Dashboard Laba              | Laba kumulatif YTD & MTD per segmen, bulanan.                                                                                  |
| Dashboard Merchant          | Halaman gabungan EDC + QRIS dengan toggle; KPI stok & flow.                                                                    |
| Present RSF                 | Slide presentasi pagi Region (RO/admin): Overview Region + per Area + detail.                                                  |
| Admin — Upload Aktual       | 10 domain upload (Simpanan, SimpananHourly, SimpananWholesale, Pinjaman, PinjamanCommercial, Recovery, PH, Laba, EDC, QRIS).   |
| Admin — Kelola RKA          | 8 domain RKA (semua minus PH & SimpananHourly).                                                                                |
| Admin — Manajemen User      | CRUD user (konsep **1 user = 1 kantor**), kunci/buka akun (`is_locked`), ganti password via form edit.                         |
| Admin — Kelola Area         | CRUD area + penetapan BO ke area (`cabang.area_id`); hapus area diblokir bila masih menaungi BO.                               |
| Admin — Aktivitas Pengguna  | Online sekarang + monitoring adopsi 30 hari, retensi 90 hari.                                                                  |
| Autentikasi                 | Login username + password, logout, ganti password.                                                                             |
| CLI Import                  | `import:{simpanan,pinjaman,recovery,laba,edc,qris,ph,simpanan-wholesale,pinjaman-commercial}`, `users:sync`, `activity:prune`. |

### 4.2 Di luar lingkup (Out of Scope)

- Integrasi langsung ke sistem sumber BRI (BRISTARS, dsb.) — input tetap file.
- Notifikasi (email/WA/push), ekspor PDF otomatis, penjadwalan laporan.
- Mobile app native — cukup responsive web.
- Multi-region: data & master saat ini spesifik wilayah RSF Jakarta 2.
- Laporan Performance KPI (lihat Non-Goals).

---

## 5. Pengguna & Persona

### 5.1 Persona

| Persona                | Tipe user         | Kebutuhan utama                                                                 |
| ---------------------- | ----------------- | ------------------------------------------------------------------------------- |
| **Admin RO**           | `admin`           | Upload data harian/bulanan, kelola RKA & user, pantau adopsi. Akses semua data. |
| **Pimpinan / staf RO** | `RO`              | Melihat seluruh wilayah, drill-down bebas.                                      |
| **Pimpinan Cabang**    | `BO`              | Memantau kinerja cabangnya + rincian per uker supervisi; akses DPK Hourly.      |
| **Kepala Unit/SBO/KK** | `SBO`/`UNIT`/`KK` | Memantau angka ukernya sendiri vs target.                                       |

### 5.2 Model akses (3 level)

Tiap user punya `role` (`admin`/`user`) dan `tipe` (`RO`/`BO`/`SBO`/`UNIT`/`KK`).
`User::getAccessLevelAttribute()` memetakan ke 3 level (dikirim ke frontend sebagai
props Inertia `access_level`):

| Level          | Tipe / role       | Lingkup data                    | Catatan                                               |
| -------------- | ----------------- | ------------------------------- | ----------------------------------------------------- |
| `LEVEL_ALL`    | `admin`, `RO`     | Semua data, semua filter        | Satu-satunya yang boleh akses Present RSF (+ admin).  |
| `LEVEL_CABANG` | `BO`              | Terkunci 1 cabang (`cabang_id`) | Boleh drill-down uker di cabangnya; akses DPK Hourly. |
| `LEVEL_UKER`   | `SBO`/`UNIT`/`KK` | Terkunci 1 uker (`uker_id`)     | Endpoint ranking antar entitas dibalas kosong.        |

Matriks akses halaman:

| Halaman                      | admin | RO  |     BO      |        SBO/UNIT/KK        |
| ---------------------------- | :---: | :-: | :---------: | :-----------------------: |
| Landing + 8 dashboard domain |  ✅   | ✅  | ✅ (scoped) |        ✅ (scoped)        |
| DPK Hourly                   |  ✅   | ✅  |     ✅      | ❌ (middleware `hourly`)  |
| Present RSF                  |  ✅   | ✅  |     ❌      | ❌ (middleware `present`) |
| Area Admin (`/admin/*`)      |  ✅   | ❌  |     ❌      |  ❌ (middleware `admin`)  |

---

## 6. Asumsi, Ketergantungan & Batasan

### 6.1 Asumsi

- File sumber data (CSV/Excel) tersedia harian/bulanan dengan struktur kolom yang stabil; ID cabang & uker bukan auto-increment.
- Admin RO disiplin mengupload data setiap hari kerja pagi.
- Semua user berada di jaringan/perangkat yang dapat mengakses server internal.

### 6.2 Ketergantungan

| Ketergantungan                   | Detail                                                         |
| -------------------------------- | -------------------------------------------------------------- |
| PHP ^8.2, Laravel ^12            | Framework backend.                                             |
| MySQL (produksi) / SQLite (test) | Query bulanan ditulis portable (tanpa `MONTH()` mentah).       |
| Node.js + Vite 7                 | Build frontend (Vue 3, Tailwind 3, Chart.js 4).                |
| PhpSpreadsheet ^5.7              | Parsing file Excel pada importer.                              |
| Cron server                      | `* * * * * php artisan schedule:run` (untuk `activity:prune`). |
| `SESSION_DRIVER=database`        | Prasyarat fitur "online sekarang" di Aktivitas Pengguna.       |

### 6.3 Batasan (Constraints)

- **Jangan mengedit migration lama in-place** — (Anggap aplikasi sudah live), perubahan skema
  selalu lewat migration baru.
- `UserSeeder` melakukan **truncate** tabel users — berbahaya jika dijalankan di produksi;
  gunakan `users:sync` untuk pemutakhiran tanpa truncate.
- Riwayat git mengandung tooling auto-commit — hindari rewrite history.

---

## 7. Arsitektur Sistem & Teknologi

### 7.1 Gambaran arsitektur

Aplikasi **monolitik Laravel 12 + Inertia 2 + Vue 3**. Tidak ada REST API terpisah:
halaman dirender via Inertia; data dinamis (filter, snapshot, chart, tabel) diambil
frontend lewat endpoint `api/*` (axios) yang mengembalikan JSON dan tetap berada dalam
session auth + middleware scoping yang sama.

```
Browser (Vue 3 + Inertia)
   │  page visit (Inertia)            │  data dinamis (axios → JSON)
   ▼                                  ▼
Route web.php ──► Middleware [auth, scope, (admin|present|hourly)]
   │
   ▼
Controller tipis (parse request) ──► <Domain>Service (query + kalkulasi)
   │                                        │
   ▼                                        ▼
Inertia::render(Pages/…)              MySQL (tabel aktual + rka_* + master)
```

**Pola per domain dashboard** (konsisten di 8 domain): 1 dashboard controller (tipis) →
1 service backend → 1 halaman Inertia → 1 service API frontend
(`resources/js/services/<domain>Api.js`) → endpoint `api/<domain>/*`.

| Domain            | Route prefix          | Controller                          | Service backend         | Halaman Vue                         |
| ----------------- | --------------------- | ----------------------------------- | ----------------------- | ----------------------------------- |
| Simpanan (DPK)    | `api/simpanan`        | `SimpananDashboardController`       | `SimpananService`       | `Pages/SimpananDashboard/Index.vue` |
| DPK Hourly        | `api/simpanan-hourly` | `SimpananHourlyDashboardController` | `SimpananHourlyService` | `Pages/SimpananHourly/Index.vue`    |
| Pinjaman (Kredit) | `api/pinjaman`        | `PinjamanDashboardController`       | `PinjamanService`       | `Pages/PinjamanDashboard/Index.vue` |
| Recovery EC       | `api/recovery`        | `RecoveryDashboardController`       | `RecoveryService`       | `Pages/RecoveryDashboard/Index.vue` |
| PH & Net DG       | `api/recovery-ph`     | `PhNetDgDashboardController`        | `PhNetDgService`        | `Pages/RecoveryPh/Index.vue`        |
| Laba              | `api/laba`            | `LabaDashboardController`           | `LabaService`           | `Pages/LabaDashboard/Index.vue`     |
| Merchant EDC      | `api/merchant/edc`    | `EdcController`                     | `EdcService`            | `Pages/Merchant/Index.vue` (toggle) |
| Merchant QRIS     | `api/merchant/qris`   | `QrisController`                    | `QrisService`           | `Pages/Merchant/Index.vue` (toggle) |

Halaman lintas domain: **Landing** (`DashboardController` → `DashboardService`), **Merchant gabungan** (`MerchantController`), **Present RSF**
(`PresentController` → `PresentService`, di `app/Services/Present/`).
Trait bersama controller upload/RKA Admin: `App\Http\Controllers\Concerns\HandlesUploadConflict`
(respons 409 "periode/tanggal sudah ada" yang seragam).

### 7.2 Teknologi & versi

| Lapisan     | Teknologi                                                                                                    |
| ----------- | ------------------------------------------------------------------------------------------------------------ |
| Backend     | PHP ^8.2, Laravel ^12, Inertia-Laravel ^2, Sanctum ^4, Ziggy ^2, PhpSpreadsheet ^5.7                         |
| Frontend    | Vue ^3.4, @inertiajs/vue3 ^2, Tailwind CSS ^3.2 (+@tailwindcss/forms), Vite ^7, axios ^1.11                  |
| Chart       | Chart.js ^4.5 + vue-chartjs ^5.3 + chartjs-plugin-datalabels ^2.2                                            |
| Format kode | Laravel Pint + `pint.json` (house style terkunci); konvensi komponen/composable Vue (frontend)               |
| Test        | PHPUnit ^11.5 (`php artisan test`, ~97 test), feature di SQLite + unit test `tests/Unit/` (Satuan, Bulan)    |
| CI/CD       | GitHub Actions `.github/workflows/ci.yml`: `pint --test` + `php artisan test` + `npm run build` tiap push/PR |
| Dev tooling | Breeze ^2.3 (sisa scaffolding), Pail, Sail, Collision, security-checker                                      |

### 7.3 Middleware terdaftar ([bootstrap/app.php](../bootstrap/app.php))

| Alias     | Kelas               | Fungsi                                                                                                     |
| --------- | ------------------- | ---------------------------------------------------------------------------------------------------------- |
| `admin`   | `AdminMiddleware`   | Gerbang area Admin (role admin).                                                                           |
| `scope`   | `EnforceUserScope`  | Menulis ulang `area_id`/`cabang_id`/`uker_id` di Request sesuai level user — satu titik penegakan scoping. |
| `present` | `EnsureRoOrAdmin`   | Gerbang halaman Present RSF.                                                                               |
| `hourly`  | `EnsureRoBoOrAdmin` | Gerbang DPK Hourly.                                                                                        |
| (web)     | `TrackActivity`     | Update `users.last_seen_at` (throttle cache 60 dtk) + catat `page_visits`.                                 |

---

## 8. Hierarki Organisasi & Dimensi Data

```
Region ──► Cabang ──► Uker            (master: region, cabang, uker)
              ▲
            Area                       (areas; cabang.area_id)
```

- **Region** = wilayah RSF (Jakarta 2). **Cabang** = Branch Office (BO). **Uker** = Unit
  Kerja (tipe BO/SBO/UNIT/KK) di bawah cabang.
- Sebuah cabang menempel ke **Region** (`region_id`) sekaligus ke **Area** (`area_id`).
  **Filter dashboard memakai dimensi Area → Cabang → Uker** (bukan Region).
- Area yang ada: **Area 1, Area 2, Area 3** (dipakai juga sebagai slide "Overview per Area"
  di Present RSF).
- ID `cabang` & `uker` **manual** dari sumber data BRI.
- **Entitas khusus `855`** = rollup Region Office RO Jakarta 2. Sediakan baris
  master `855` (tipe REGION) di tabel `cabang` **dan** `uker` (migration
  `2026_06_19_..._add_region_office_855_to_master`) agar import Pinjaman segmen **Medium**
  (dikelola level Region, `cabang_id = 855`) lolos validasi FK. `855` **disembunyikan**
  dari dropdown BO & sebagian besar query tampilan. Nilai `855` **terpusat** di
  `App\Models\Region::OFFICE_ID` (sumber tunggal); const lokal tetap
  dengan nama semantiknya tapi merujuk konstanta itu. Perlakuan per domain:
  - `RecoveryService::EXCLUDED_ID` / `PhNetDgService::EXCLUDED_ID` — dikecualikan (di query recovery/PH).
  - `SimpananService::EXCLUDED_REGION_ID` / `SimpananHourlyService` — dikecualikan.
  - Importer Laba `REGION_ID` — justru **diikutkan**.
  - **Net DG** — **diikutkan** dalam kalkulasi total (porsi Medium), tetapi disembunyikan
    dari dropdown & baris tabel cabang (jumlah baris tabel ≠ kartu Total sebesar porsi
    Medium — disengaja).

---

## 9. Model Data

### 9.1 Tabel transaksi / aktual

Semua nilai disimpan dalam **rupiah penuh**; konversi tampilan ke **juta** lewat sumber
tunggal `App\Support\Satuan::toJuta()` (÷ 1.000.000).

| Tabel                 | Granularitas baris                                     | Kolom nilai  | Catatan                                                          |
| --------------------- | ------------------------------------------------------ | ------------ | ---------------------------------------------------------------- |
| `simpanan`            | uker × produk × segmentasi × tanggal                   | `saldo`      | Produk: Tabungan/Giro/Deposito; CASA = Tabungan + Giro.          |
| `pinjaman`            | uker × segmen × segmentasi × kualitas × tanggal        | `baki_debet` | Kualitas: Lancar/SML/NPL; OS = jumlah ketiganya.                 |
| `recovery`            | uker × segmen × tanggal (unik `recovery_unique`)       | `actual`     | Per-uker (drill-down jalan); segmen kanonik Micro/SME/Consumer.  |
| `laba`                | uker × segmen × tahun × bulan (unik `laba_unique`)     | `laba`       | Nilai **kumulatif YTD**; MTD = bulan N − (N−1).                  |
| `ph`                  | uker × segmen × periode akhir bulan (unik `ph_unique`) | `saldo`      | **Flow bulanan** hapus buku; segmen Micro/Small/Consumer.        |
| `edc` / `qris`        | uker × KPI × tanggal                                   | `actual`     | Merchant; satuan mengikuti KPI; nama KPI dinormalisasi importer. |
| `simpanan_wholesale`  | uker × produk × tanggal                                | `saldo`      | Segmentasi Wholesale; **khusus Present RSF**.                    |
| `pinjaman_commercial` | uker × segmentasi × kualitas × tanggal                 | `baki_debet` | Segmen Commercial; **khusus Present RSF** (cermin `pinjaman`).   |
| `simpanan_hourly`     | uker × produk × segmentasi × tanggal × jam             | `saldo`      | DPK Hourly (only EOM); baseline delta pakai `simpanan` harian.   |

### 9.2 Tabel target (RKA)

Per domain, kolom `target` + `tahun`/`bulan`: `rka_simpanan`, `rka_pinjaman`,
`rka_recovery`, `rka_laba`, `rka_edc`, `rka_qris`, `rka_simpanan_wholesale`,
`rka_pinjaman_commercial`. **PH & Net DG tidak punya RKA.**

### 9.3 Tabel master & pendukung

| Tabel                      | Fungsi                                                                                                               |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `region`, `cabang`, `uker` | Master organisasi (ID manual BRI; cabang punya `region_id` + `area_id`).                                             |
| `areas`                    | Master Area (Area 1/Area 2/Area 3).                                                                                  |
| `users`                    | Akun: `username` (login), `role`, `tipe`, `cabang_id`, `uker_id`, `last_seen_at`; `email` nullable & tak dipakai UI. |
| `page_visits`              | Log navigasi halaman (user × route_name × waktu); retensi 90 hari.                                                   |
| `sessions`                 | Session database — sumber daftar "online sekarang".                                                                  |
| `cache`, `jobs`            | Bawaan Laravel.                                                                                                      |

### 9.4 Model Eloquent

`User`, `Region`, `Cabang`, `Area`, `Uker`, `Simpanan`, `SimpananHourly`,
`SimpananWholesale`, `Pinjaman`, `PinjamanCommercial`, `Recovery`, `Ph`, `Laba`, `Edc`,
`Qris`, `PageVisit` + model `Rka*` per domain (8 buah).

---

## 10. Kebutuhan Fungsional

Notasi prioritas: **[M]** Must-have (wajib ada), **[S]** Should, **[C]** Could.

### F1 — Autentikasi & Akun

| ID   | Kebutuhan                                                                                                                                                                                                              |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F1.1 | [M] Login memakai **username** (bukan email) + password. Route `/` redirect ke login.                                                                                                                                  |
| F1.2 | [M] Logout dan **ganti password** (halaman Profil hanya berisi form Update Password).                                                                                                                                  |
| F1.3 | [M] Alur Breeze lain **dihapus dan dikunci test**: registrasi publik, reset password via email, verifikasi email, confirm password, update profil/hapus akun sendiri (`Auth/DisabledAuthFeaturesTest`, `ProfileTest`). |
| F1.4 | [M] Sesi idle timeout **10 menit** (`SESSION_LIFETIME`) → auto-logout.                                                                                                                                                 |
| F1.5 | [C] Paksa ganti password saat login pertama (belum ada — lihat Risiko: password default seragam).                                                                                                                      |

### F2 — Otorisasi & Scoping Data

| ID   | Kebutuhan                                                                                                                                                                                                                           |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F2.1 | [M] Middleware `EnforceUserScope` (alias `scope`, terpasang di semua route dashboard) **menulis ulang** `area_id`/`cabang_id`/`uker_id` pada Request sebelum controller/service membacanya — aman walau user mengubah query string. |
| F2.2 | [M] Endpoint "ranking antar entitas" (daftar dipelihara di `EnforceUserScope::BRANCH_ROUTES`) dibalas **kosong** untuk user level uker.                                                                                             |
| F2.3 | [M] Frontend menyembunyikan filter yang tak relevan lewat composable `useScope()` ([utils/scope.js](../resources/js/utils/scope.js)) — kosmetik/UX; penegakan tetap backend.                                                        |
| F2.4 | [M] Endpoint dashboard baru **wajib** membaca filter dari Request agar otomatis ikut terkunci scope.                                                                                                                                |
| F2.5 | [M] Gerbang halaman khusus: `present` (RO/admin), `hourly` (RO/BO/admin), `admin` (admin).                                                                                                                                          |

### F3 — Landing Dashboard (`/dashboard`)

| ID   | Kebutuhan                                                                                                                                       |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| F3.1 | [M] Kartu ringkasan **4 domain inti** (Simpanan, Pinjaman, Recovery, Laba) + rasio **%CASA** dan **%LDR**.                                      |
| F3.2 | [M] Filter Area/Cabang/Uker (cascading di client) + tanggal, satu tombol **Terapkan**.                                                          |
| F3.3 | [M] Kartu **Laba** memakai periode bulanan **terakhir yang tersedia ≤ posisi** (fallback mundur otomatis bila laba bulan berjalan belum rilis). |
| F3.4 | [M] Satuan tampilan via `Satuan::toJuta()` — jangan pakai heuristik "auto-detect satuan".                                                       |

### F4 — Dashboard Simpanan / DPK (`/dashboard/simpanan`)

| ID   | Kebutuhan                                                                                                                                                                           |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F4.1 | [M] Snapshot kartu per produk (Tabungan/Giro/Deposito, Total, CASA) dengan delta D-1/MTD/YTD/YoY + pencapaian vs RKA.                                                               |
| F4.2 | [M] Line chart tren saldo harian (per bulan, palet warna `Bulan::WARNA` / `chartColors.js`).                                                                                        |
| F4.3 | [M] Tabel "Kinerja Cabang" dengan sort kolom + **drill-down BO**: pilih BO → rincian per uker supervisi (kirim `cabang_id` ke endpoint branch; service grouping per-uker otomatis). |
| F4.4 | [M] Filter Area → Cabang → Uker + tanggal, pola pending vs applied + `ApplyButton`.                                                                                                 |

### F5 — Dashboard DPK Hourly (`/dashboard/simpanan-hourly`)

| ID   | Kebutuhan                                                                                                                                                                                                                                                                                                                                                                                     |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F5.1 | [M] Menampilkan posisi DPK **per jam** pada tanggal-tanggal EOM ("only EOM"); jam dipilih saat upload.                                                                                                                                                                                                                                                                                        |
| F5.2 | [M] Baseline delta memakai data `simpanan` **harian** (bukan antar-jam).                                                                                                                                                                                                                                                                                                                      |
| F5.3 | [M] Akses terbatas RO/BO/admin (middleware `hourly`), termasuk endpoint `api/simpanan-hourly/*`.                                                                                                                                                                                                                                                                                              |
| F5.4 | [M] **Auto-refresh 2 menit** (layar monitoring EOM): snapshot/chart/tabel diambil ulang otomatis tiap 2 mnt tanpa klik Terapkan — "senyap" (tanpa overlay loading agar tak kedip di videotron), memakai filter yang **sudah** diterapkan (perubahan filter yang belum di-Terapkan diabaikan). Indikator jam "diperbarui HH:MM:SS" di subtitle tabel; interval dibersihkan saat `onUnmounted`. |

### F6 — Dashboard Pinjaman / Kredit (`/dashboard/pinjaman`)

| ID   | Kebutuhan                                                                                                                                                                                                                                             |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F6.1 | [M] Tab **Total / SML / NPL**; OS = Lancar + SML + NPL.                                                                                                                                                                                               |
| F6.2 | [M] Snapshot + chart + chart per segmen + tabel Rincian Per Produk + Kinerja Cabang (drill-down BO).                                                                                                                                                  |
| F6.3 | [M] Khusus tab **SML/NPL**: kolom YOY diganti **"Date to Date"** = MoM (tanggal posisi vs tanggal sama bulan lalu; `PinjamanService::getMomDate()` pakai `subMonthNoOverflow` + fallback tanggal terakhir tersedia). Tab Total tetap D-1/MTD/YTD/YOY. |
| F6.4 | [M] Segmen **Medium** (kelolaan Region, `cabang_id=855`) ikut dalam data; disembunyikan dari dropdown BO.                                                                                                                                             |

### F7 — Dashboard Recovery EC (`/dashboard/recovery`)

| ID   | Kebutuhan                                                                                                                                                                                               |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F7.1 | [M] Snapshot pencapaian recovery vs RKA per segmen tampil **Micro / SME / Consumer**.                                                                                                                   |
| F7.2 | [M] Normalisasi taksonomi antar-tahun **saat baca** (`RecoveryService::SEGMEN_RAW`): 2025 "SME" vs 2026 "Small+Medium" disatukan jadi segmen kanonik **SME** — YoY apple-to-apple; data mentah DB utuh. |
| F7.3 | [M] Drill-down per-uker berfungsi (tabel recovery menyimpan `uker_id`).                                                                                                                                 |

### F8 — Dashboard PH & Net DG (`/dashboard/recovery-ph`, submenu "Recovery EC & PH")

| ID   | Kebutuhan                                                                                                                                                                                                                     |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| F8.1 | [M] Toggle \*\*PH                                                                                                                                                                                                             | NET DG** ala Merchant, section kartu+chart per scope: **Total, Mikro, SME, Consumer\*\*. |
| F8.2 | [M] **PH** = flow bulanan dari tabel `ph`; importer SUM per kombinasi; baris tanpa `id_uker` valid di-fallback ke level BO (`uker_id = cabang_id`).                                                                           |
| F8.3 | [M] **Net DG tidak punya tabel** — dihitung on-the-fly (rumus lihat §11 / BR-7), terkunci di test.                                                                                                                            |
| F8.4 | [M] Chart combo 2 tahun (bar delta bulanan + line akumulasi), dipotong s/d bulan posisi filter; Mentari `#71C5E8` = tahun lalu, Nusantara `#0857C3` = tahun berjalan; label garis akum Jan disembunyikan (tumpang tindih).    |
| F8.5 | [M] Bulan tanpa data direpresentasikan `null` (beda makna dari 0).                                                                                                                                                            |
| F8.6 | [M] Query bulanan **portable MySQL/SQLite** (group by tanggal; bulan diturunkan di PHP).                                                                                                                                      |
| F8.7 | [M] Rollup `855` **diikutkan** dalam kalkulasi (taksonomi SME = Small+Medium mengikuti file bisnis "DG 26.xlsx"; wajib diverifikasi angka-per-angka vs file bisnis), tetapi disembunyikan dari dropdown & baris tabel cabang. |

### F9 — Dashboard Laba (`/dashboard/laba`)

| ID   | Kebutuhan                                                                                       |
| ---- | ----------------------------------------------------------------------------------------------- |
| F9.1 | [M] Data laba bulanan **kumulatif YTD**; MTD dihitung = bulan N − bulan (N−1).                  |
| F9.2 | [M] Snapshot + chart kumulatif + **chart MTD** terpisah + tabel kinerja cabang (drill-down BO). |
| F9.3 | [M] Filter tahun-bulan (bukan tanggal harian).                                                  |

### F10 — Dashboard Merchant EDC & QRIS (`/dashboard/merchant`)

| ID    | Kebutuhan                                                                                                                                                                                                      |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- | ------------------------------------------------- |
| F10.1 | [M] Satu halaman gabungan dengan \*\*toggle EDC                                                                                                                                                                | QRIS\*\*; alias route lama `/dashboard/merchant/edc | qris` tetap hidup (redirect ke halaman gabungan). |
| F10.2 | [M] KPI **EDC**: TID, MID, EDC Produktif, EDC SV Rp.0 (inverse: makin kecil makin baik) = stok; Sales Volume (rupiah) & Jumlah Transaksi = flow (akumulasi YTD, data marginal).                                |
| F10.3 | [M] KPI **QRIS**: User QRIS, QRIS Produktif (stok); Sales Volume (rupiah) & Jumlah Transaksi (flow).                                                                                                           |
| F10.4 | [M] Semantik nilai: stok = SUM(actual) pada tanggal; flow = SUM(actual) 1 Jan s/d tanggal. Delta = nilai(posisi) − nilai(refDate); flow menampilkan D-1/MTD/YOY (tanpa YTD), stok menampilkan D-1/MTD/YTD/YOY. |
| F10.5 | [M] Pencapaian/Gap hanya untuk KPI ber-target (EDC: TID, EDC Produktif, Sales Volume; QRIS: User QRIS, QRIS Produktif, Sales Volume).                                                                          |

### F11 — Present RSF (`/dashboard/present`, RO/admin)

| ID    | Kebutuhan                                                                                                                                                                                |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F11.1 | [M] Slide presentasi pagi tingkat Region: **Overview Region** → **Overview per Area** (Area 1/Area 2/Area 3; kartu sama tanpa %CASA/%LDR) → slide **detail DPK / Pinjaman / SML / NPL**. |
| F11.2 | [M] Memakai data domain inti + tabel khusus `simpanan_wholesale` & `pinjaman_commercial` (upload terpisah di Admin) sehingga cakupan segmen lengkap (termasuk Wholesale & Commercial).   |
| F11.3 | [M] Tabel detail memakai komponen `PresentDetailTable`; kartu memuat MtD/YtD + Gap RKA, dioptimalkan agar tidak wrap/tabrakan di HP (riwayat commit Juli 2026).                          |
| F11.4 | [—] Section "Performance KPI" **tidak dibuat di aplikasi** — laporan KPI diolah manual di Excel.                                                                                         |

### F12 — Admin: Upload Data Aktual

| ID    | Kebutuhan                                                                                                                                                                                                                                                                                         |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F12.1 | [M] Halaman upload per domain (`Admin/Upload/<Domain>.vue`): Simpanan, SimpananHourly, SimpananWholesale, Pinjaman, PinjamanCommercial, Recovery, Ph, Laba, Edc, Qris.                                                                                                                            |
| F12.2 | [M] Tiap domain: upload file, **riwayat upload**, **download ulang** per periode, **hapus** per tanggal/periode, **hapus per bulan** (`bulk-month`) atau per tahun (RKA/PH/Laba).                                                                                                                 |
| F12.3 | [M] Parsing di **service import** khusus per domain (tidak ada parsing inline di controller) — lihat §15.                                                                                                                                                                                         |
| F12.4 | [M] Proteksi duplikat: umumnya blokir per **tanggal** yang sudah ada; **Pinjaman blokir per (tanggal, segmen)** (`detectDateSegmenPairsFromCsv()` + `$skipDateSegmen`) agar segmen baru (mis. Medium) tetap bisa masuk; **PH melewati periode yang sudah ada** (skip, bukan blokir seluruh file). |
| F12.5 | [M] Upsert idempoten via unique index (`recovery_unique`, `laba_unique`, `ph_unique`).                                                                                                                                                                                                            |

### F13 — Admin: Kelola RKA (Target)

| ID    | Kebutuhan                                                                                                                                                                |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| F13.1 | [M] Halaman RKA per domain (`Admin/Rka/<Domain>.vue`): Simpanan, SimpananWholesale, Pinjaman, PinjamanCommercial, Recovery, Laba, Edc, Qris (minus PH & SimpananHourly). |
| F13.2 | [M] Upload target tahunan/bulanan, lihat data, edit baris (Simpanan/Pinjaman), hapus per baris & per tahun, summary (Simpanan/Pinjaman).                                 |

### F14 — Admin: Manajemen User (`/admin/users`)

| ID    | Kebutuhan                                                                                                                                                                                                                                                                                                       |
| ----- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F14.1 | [M] CRUD user (konsep **1 user = 1 kantor** — menambah user = mendaftarkan kantornya lewat `office_id`; form cascade Area→BO→Uker). Statistik + dropdown uker per cabang.                                                                                                                                       |
| F14.2 | [M] Kolom "Terakhir Aktif" dari `users.last_seen_at` (helper `timeAgo`).                                                                                                                                                                                                                                        |
| F14.3 | [M] **Kunci/buka akun** (`users.is_locked`, tombol gembok; user terkunci ditolak login walau kredensial benar; admin tak bisa mengunci diri sendiri). **Ganti password** lewat form Edit user (kosongkan = tak diubah). Tidak menyediakan route/icon `reset-password` terpisah maupun fitur export/bulk-delete. |
| F14.4 | [M] Seeding awal: `UserSeeder` dari `database/seeders/data/User.csv` (truncate + bulk insert; hash dihitung sekali per password unik; username numerik diberi prefix `PKU` + 4 digit). Pemutakhiran tanpa truncate: command `users:sync`.                                                                       |

### F15 — Admin: Aktivitas Pengguna (`/admin/activity`)

| ID    | Kebutuhan                                                                                                                                                |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F15.1 | [M] Daftar **online sekarang** (aktivitas ≤ 5 menit, dari tabel `sessions`; butuh `SESSION_DRIVER=database`), polling 45 detik.                          |
| F15.2 | [M] Monitoring adopsi: agregasi 30 hari dari `page_visits`; definisi "tidak aktif" ≥ 7 hari (konstanta di `ActivityService`).                            |
| F15.3 | [M] `page_visits` hanya mencatat **navigasi halaman** (GET bernama, bukan `api.*`, non-AJAX atau ber-header `X-Inertia`) — polling axios tidak tercatat. |
| F15.4 | [M] Retensi 90 hari via `activity:prune`, dijadwalkan harian (`routes/console.php`; produksi butuh cron).                                                |

### F16 — CLI & Operasional

| ID    | Kebutuhan                                                                                                                                                                                                                                    |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F16.1 | [M] Command import per domain: `import:{simpanan, pinjaman, recovery, laba, edc, qris, ph, simpanan-wholesale, pinjaman-commercial}` (`app/Console/Commands/`). Catatan: `import:ph` **menimpa** periode (beda dari upload admin yang skip). |
| F16.2 | [M] `users:sync` — upsert user dari CSV/Excel tanpa truncate (password lama tidak diubah).                                                                                                                                                   |
| F16.3 | [M] `activity:prune` — hapus `page_visits` > 90 hari.                                                                                                                                                                                        |

---

## 11. Aturan Bisnis & Formula

| ID    | Aturan / Formula                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BR-1  | **Satuan**: semua nilai domain inti disimpan **rupiah penuh**; tampilan dikonversi ke **juta** via `App\Support\Satuan::toJuta()` (÷ 1.000.000), lalu diformat Jt/M/T oleh `formatAngka.js`. Konversi dalam SQL (`DB::raw('.../1000000')`) diperbolehkan di query.                                                                                                                                                                          |
| BR-2  | **CASA** = Tabungan + Giro; **%CASA** = CASA / Total DPK.                                                                                                                                                                                                                                                                                                                                                                                   |
| BR-3  | **%LDR** = Total Pinjaman (OS) / Total DPK.                                                                                                                                                                                                                                                                                                                                                                                                 |
| BR-4  | **OS Pinjaman** = Lancar + SML + NPL.                                                                                                                                                                                                                                                                                                                                                                                                       |
| BR-5  | **Delta**: `D-1` (label UI; **data key tetap `dtd`** — jangan diganti), `MTD` (vs akhir bulan lalu), `YTD` (vs akhir Des), `YoY` (vs tanggal sama tahun lalu). Khusus Pinjaman tab SML/NPL: kolom YoY → **"Date to Date"** = MoM (tanggal posisi vs tanggal sama bulan lalu, `subMonthNoOverflow` + fallback).                                                                                                                              |
| BR-6  | **Pencapaian** = actual / target RKA; **Gap** = actual − target. Pewarnaan `pctCls` (normal: besar=bagus) vs `pctClsInverse` (SML/NPL/EDC SV Rp.0: kecil=bagus).                                                                                                                                                                                                                                                                            |
| BR-7  | **Net DG** (tanpa tabel, on-the-fly dari posisi SML/NPL akhir bulan `pinjaman` + `ph`): `NetDG NPL(N) = NPL(N) − NPL(N−1) + PH(N)`; `NetDG SML(N) = SML(N) − SML(N−1) + NetDG NPL(N)`. Yang tampil = **NetDG SML**, bentuk teleskopik `Δ(SML+NPL) + PH`; akumulasi YTD = `(SML+NPL)(N) − (SML+NPL)(Des LY) + akum PH`. Konsekuensi: deret tahun X butuh posisi pinjaman **Des X−1** (deret 2025 kosong sampai posisi 31 Des 2024 diunggah). |
| BR-8  | **Laba**: nilai tersimpan kumulatif YTD → **MTD = laba(N) − laba(N−1)**. Landing memakai bulan terakhir tersedia ≤ posisi.                                                                                                                                                                                                                                                                                                                  |
| BR-9  | **Taksonomi segmen Recovery**: kanonik **Micro / SME / Consumer**; SME = Small + Medium + SME (normalisasi saat baca, lintas tahun 2025/2026).                                                                                                                                                                                                                                                                                              |
| BR-10 | **Taksonomi segmen Net DG**: **Micro / SME (Small+Medium) / Consumer** — mengikuti perhitungan bisnis "DG 26.xlsx"; rollup 855 (Medium) ikut dihitung di total.                                                                                                                                                                                                                                                                             |
| BR-11 | **PH** adalah flow bulanan (bukan posisi); periode = akhir bulan; PH & Net DG **tidak punya RKA**.                                                                                                                                                                                                                                                                                                                                          |
| BR-12 | **Merchant**: KPI stok = posisi pada tanggal; KPI flow = akumulasi YTD dari data marginal; flow tanpa delta YTD (identik nilai kartu).                                                                                                                                                                                                                                                                                                      |
| BR-13 | **Recovery import** meng-**SUM** `actual` per (cabang, uker, segmen, tanggal) sebelum upsert — file sumber bisa berisi banyak baris per kombinasi (per debitur/akun). Bukan last-wins/MAX.                                                                                                                                                                                                                                                  |
| BR-14 | **Bulan tanpa data = `null`**, bukan 0 (mempengaruhi chart & delta).                                                                                                                                                                                                                                                                                                                                                                        |
| BR-15 | **Entitas 855**: disembunyikan dari dropdown/tabel tampilan; dikecualikan di Simpanan & Recovery; diikutkan di Laba & Net DG (lihat §8).                                                                                                                                                                                                                                                                                                    |

---

## 12. Kebutuhan Non-Fungsional

### NFR-1 Keamanan

| ID      | Kebutuhan                                                                                                                                                            |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| NFR-1.1 | Semua route dashboard & API di belakang `auth` + `scope`; area admin di belakang `admin`.                                                                            |
| NFR-1.2 | Scoping data ditegakkan **server-side** (`EnforceUserScope` menulis ulang parameter Request).                                                                        |
| NFR-1.3 | Sesi idle 10 menit auto-logout; session tersimpan di database.                                                                                                       |
| NFR-1.4 | **Watermark nama user** (tipis, diagonal, berulang, `pointer-events:none`, `aria-hidden`) di semua halaman via `DashboardLayout` — jejak anti-bocor pada screenshot. |
| NFR-1.5 | Password disimpan hash bcrypt. ⚠️ Risiko diketahui: password default seeding seragam (`RSF12345`) dan ikut ter-commit di CSV — mitigasi diusulkan di §19.            |
| NFR-1.6 | Dependensi dipindai `enlightn/security-checker` (dev).                                                                                                               |

### NFR-2 Kinerja

| ID      | Kebutuhan                                                                                                          |
| ------- | ------------------------------------------------------------------------------------------------------------------ |
| NFR-2.1 | Halaman memuat data awal via props Inertia (mis. `initialSnapshot`), lalu interaksi via axios — tidak full reload. |
| NFR-2.2 | Filter options di-cache (mis. `getCachedFilterOptions()` di Merchant).                                             |
| NFR-2.3 | Seeder user menghitung hash **sekali per password unik** (bulk insert cepat).                                      |
| NFR-2.4 | `TrackActivity` di-throttle cache 60 detik agar tidak membebani tiap request.                                      |
| NFR-2.5 | Polling ringan: Aktivitas Pengguna 45 detik; polling axios tidak tercatat ke `page_visits`.                        |

### NFR-3 Kompatibilitas & Responsivitas

| ID      | Kebutuhan                                                                                                                                                                         |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| NFR-3.1 | Teks kartu wajib aman di **3 kondisi**: videotron, desktop sidebar-terbuka, dan HP — pola container-query `fs-*` (unit `cqw`); dilarang truncate/min-width paksa.                 |
| NFR-3.2 | Viewport `maximum-scale=1` (cegah zoom-out tak sengaja di mobile).                                                                                                                |
| NFR-3.3 | **TV select fallback**: dropdown custom (`utils/tvSelect.js`) untuk Android TV/WebView yang native `<select>`-nya tidak bisa dibuka.                                              |
| NFR-3.4 | Tooltip line chart = **external HTML tooltip** dirender dalam container chart (`pointer-events:none`) agar tidak terpotong di layar sempit; entri bulan tanpa data disembunyikan. |
| NFR-3.5 | Query bulanan portable MySQL (produksi) & SQLite (test).                                                                                                                          |

### NFR-4 Aksesibilitas & UX

| ID      | Kebutuhan                                                                                                   |
| ------- | ----------------------------------------------------------------------------------------------------------- |
| NFR-4.1 | CSS menghormati `prefers-reduced-motion`.                                                                   |
| NFR-4.2 | Watermark `aria-hidden` (tidak mengganggu screen reader).                                                   |
| NFR-4.3 | Loading state konsisten (`LoadingOverlay`, `spinner-bri`), angka animasi `CountUp`, notifikasi `ToastHost`. |

### NFR-5 Maintainability

| ID      | Kebutuhan                                                                                                                                           |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| NFR-5.1 | Backend diformat **Laravel Pint** (`pint.json`, house style terkunci) sebelum commit; pola controller tipis → service. CI menegakkan `pint --test`. |
| NFR-5.2 | Konstanta bulan terpusat `app/Support/Bulan.php` (mirror frontend `chartColors.js`); `*Constants` hanya delegasi.                                   |
| NFR-5.3 | Helper frontend bersama di `resources/js/utils/` — dilarang duplikasi (lihat §13.3).                                                                |
| NFR-5.4 | Test otomatis (feature) tiap domain sebagai jaring pengaman; `php artisan test` sebelum & sesudah perubahan backend.                                |
| NFR-5.5 | Judul tab: brand dari `APP_NAME` env (`"Dashboard Keragaan RSF"`); tiap halaman set `<Head title>` sendiri.                                         |

---

## 13. Desain UI/UX

### 13.1 Brand & tema

- **Palet BRI** (di `tailwind.config.js`): `bri-nusantara` `#0857C3` (primary),
  `bri-cakrawala`, `bri-mentari` `#71C5E8`, `bri-black` `#3C3C3C` (teks), skala
  `bri-50..950`, background `bg-bri-hero`, shadow `shadow-bri*`, animasi (`fade-in`,
  `pop-in`, `drop-in`, dll).
- **Kebijakan warna ketat**: pakai palet BRI; **dipertahankan** warna semantik
  emerald/rose/amber untuk delta/badge dan palet `BULAN` untuk line chart; abu-abu diganti
  tint `bri-black`.
- Font resmi **Inter**.
- Class shortcut `.sel`, `.lbl` + komponen CSS `.btn-bri*`, `.card-bri*`, `.badge-*`,
  `.table-bri`, `.spinner-bri` di `resources/css/app.css`.

### 13.2 Pola interaksi baku

- **Layout tunggal** `Layouts/DashboardLayout.vue` untuk semua halaman (termasuk Admin),
  dengan watermark user.
- **Pola filter**: filter bar Area→Cabang→Uker (+ tanggal / tahun-bulan), state
  **pending vs applied**, data dimuat saat klik **Terapkan** (`ApplyButton`).
- **Drill-down BO** di tabel "Kinerja Cabang" (Simpanan, Pinjaman, Recovery, Laba).
- **Sort tabel** via composable `useTableSort` + komponen `SortArrow`.
- Chart line memakai `buildLineChart` + `lineChartOptions`; chart combo PH/Net DG dengan
  konvensi warna 2 tahun (Mentari = lalu, Nusantara = berjalan).
- Label delta di UI: **"D-1"** (bukan DTD); key data tetap `dtd`.

### 13.3 Komponen & helper reusable (wajib dipakai ulang, dilarang bikin varian)

| Jenis    | Daftar                                                                                                                                                                                                                                                                                    |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Komponen | `KpiCard`, `LoadingOverlay`, `ApplyButton`, `SortArrow`, `CountUp`, `ToastHost`, `PresentDetailTable`, `TextInput`, `InputLabel`, `InputError`, `PrimaryButton`                                                                                                                           |
| Utils JS | `formatAngka.js` (`formatAngka`/`formatDelta`/`formatPct`), `tanggal.js` (`fmtTglPendek`/`fmtTglPanjang`/`timeAgo`), `pencapaian.js` (`pctCls`/`pctClsInverse`), `chartColors.js` (`BULAN`), `chartLine.js`, `chartOptions.js`, `useTableSort.js`, `scope.js` (`useScope`), `tvSelect.js` |

---

## 14. Inventaris Route & Endpoint API

### 14.1 Halaman (Inertia)

| Route                                            | Middleware tambahan | Halaman                      |
| ------------------------------------------------ | ------------------- | ---------------------------- |
| `/` → redirect login                             | —                   | —                            |
| `/dashboard`                                     | —                   | Landing                      |
| `/dashboard/simpanan`                            | —                   | SimpananDashboard/Index      |
| `/dashboard/simpanan-hourly`                     | `hourly`            | SimpananHourly/Index         |
| `/dashboard/pinjaman`                            | —                   | PinjamanDashboard/Index      |
| `/dashboard/recovery`                            | —                   | RecoveryDashboard/Index      |
| `/dashboard/recovery-ph`                         | —                   | RecoveryPh/Index             |
| `/dashboard/laba`                                | —                   | LabaDashboard/Index          |
| `/dashboard/merchant` (+alias `/edc`,`/qris`)    | —                   | Merchant/Index (toggle)      |
| `/dashboard/present`                             | `present`           | Present                      |
| `/profile`                                       | —                   | Profil (form ganti password) |
| `/admin` + sub-halaman upload/RKA/users/activity | `admin`             | Admin/\*                     |

Semua halaman non-admin di atas dalam grup `['auth', 'scope']`; admin dalam `['auth', 'admin']`.

### 14.2 Endpoint data per domain (pola umum)

Pola endpoint tiap domain (variasi kecil per domain, nama route `api.<domain>.*`):

| Endpoint                             | Fungsi                                                                                                |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| `GET /filter-options`                | Opsi filter (area/cabang/uker/tanggal tersedia).                                                      |
| `GET /snapshot`                      | Kartu KPI + delta + pencapaian (nama method controller seragam `getSnapshot`).                        |
| `GET /chart`                         | Data line/combo chart.                                                                                |
| `GET /branch` / `/branch-pencapaian` | Tabel ranking kinerja cabang (kosong utk level uker).                                                 |
| `GET /cabang/{areaId}`               | Cascading: cabang per area (di Laba param bernama `cabangId` tapi berisi `area_id` — catatan desain). |
| `GET /uker/{cabangId}`               | Cascading: uker per cabang.                                                                           |
| Khusus Pinjaman                      | `GET /chart-segmen`, `GET /produk`.                                                                   |
| Khusus Laba                          | `GET /chart-mtd`.                                                                                     |

### 14.3 Route Admin (ringkas)

| Grup                      | Operasi                                                                                                                                                |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Upload aktual (10 domain) | `GET /upload`, `POST /upload`, `GET /history`, `GET /download/{periode}`, `DELETE /{periode}`, `DELETE /bulk-month` (atau `year/{tahun}` utk PH/Laba). |
| RKA (8 domain)            | `GET /`, `GET /data`, `POST /upload`, `PUT /{id}` (Simpanan/Pinjaman), `DELETE /{id}`, `DELETE /year/{tahun}`, `GET /summary` (Simpanan/Pinjaman).     |
| Users                     | index/data/stats/uker-cascading/store/show/update/destroy/bulk-destroy/toggle-lock (kunci/buka akun; ganti password lewat form edit).                  |
| Activity                  | `GET /`, `GET /data`.                                                                                                                                  |

(Inventaris lengkap: [routes/web.php](../routes/web.php) & [routes/auth.php](../routes/auth.php).)

---

## 15. Import Data & Idempotensi

### 15.1 Service import per domain (tidak ada parsing inline di controller)

| Domain              | Aktual                                                        | RKA                                     |
| ------------------- | ------------------------------------------------------------- | --------------------------------------- |
| Simpanan            | `SimpananCsvImportService`                                    | `RkaSimpananCsvImportService`           |
| Simpanan Hourly     | `SimpananHourlyCsvImportService`                              | — (tidak ada RKA)                       |
| Simpanan Wholesale  | `SimpananWholesaleCsvImportService`                           | `RkaSimpananWholesaleCsvImportService`  |
| Pinjaman            | `PinjamanCsvImportService`                                    | `RkaPinjamanCsvImportService`           |
| Pinjaman Commercial | `PinjamanCommercialCsvImportService`                          | `RkaPinjamanCommercialCsvImportService` |
| Recovery            | `RecoveryCsvImportService` (`importActual()` + `importRka()`) | idem                                    |
| PH                  | `PhCsvImportService`                                          | — (tidak ada RKA)                       |
| Laba                | `LabaCsvImportService`                                        | `RkaLabaCsvImportService`               |
| EDC                 | `EdcCsvImportService`                                         | `RkaEdcCsvImportService`                |
| QRIS                | `QrisCsvImportService`                                        | `RkaQrisCsvImportService`               |

### 15.2 Aturan idempotensi & proteksi duplikat

| Domain   | Perilaku                                                                                                                                                                         |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Umum     | Upload diblokir bila **tanggal** sudah ada (hapus dulu bila mau ganti).                                                                                                          |
| Pinjaman | Blokir per **(tanggal, segmen)** — file berisi segmen baru (Medium) tetap masuk; segmen lama di-skip.                                                                            |
| Recovery | **SUM** per (cabang, uker, segmen, tanggal) sebelum upsert; unique `recovery_unique`.                                                                                            |
| Laba     | Upsert per (uker, segmen, tahun, bulan); unique `laba_unique`.                                                                                                                   |
| PH       | Upload admin **skip** periode yang sudah ada (bukan blokir seluruh file); CLI `import:ph` **menimpa**; unique `ph_unique`; fallback `uker_id = cabang_id` bila `id_uker` kosong. |
| EDC/QRIS | Nama KPI dinormalisasi importer ke bentuk kanonik (mis. `Sales_Volume_Marginal`).                                                                                                |

---

## 16. Pengujian & QA

| Jenis                 | Cakupan                                                                                                                                                                                        |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Characterization test | 4 domain dashboard + landing + **Present RSF** (`tests/Feature/*DashboardTest.php`, `DashboardTest.php`, `PresentRsfTest.php`) — jaring pengaman perilaku tiap domain.                         |
| Unit test             | `tests/Unit/` — helper murni `SatuanTest`, `BulanTest` (~97 test total).                                                                                                                       |
| CI                    | GitHub Actions menjalankan `pint --test` + `php artisan test` + `npm run build` tiap push/PR.                                                                                                  |
| Importer test         | `RecoveryImportTest`, `RkaImportTest` (termasuk kunci perilaku SUM Recovery).                                                                                                                  |
| Auth negatif          | `Auth/DisabledAuthFeaturesTest`, `ProfileTest` — memastikan alur Breeze yang dihapus tetap mati.                                                                                               |
| Aktivitas             | `ActivityTest`.                                                                                                                                                                                |
| Rumus bisnis          | Rumus Net DG terkunci di test (verifikasi vs Excel bisnis).                                                                                                                                    |
| Kebijakan run         | `php artisan test` **sebelum & sesudah** setiap perubahan backend; test jalan di SQLite (query wajib portable).                                                                                |
| Verifikasi visual     | Render + screenshot (server preview :8001, login user uji, lebar ≥1280) — **hanya** untuk halaman baru / rombak besar; klaim "selesai" untuk perubahan UI tidak boleh tanpa verifikasi render. |

---

## 17. Deployment & Operasional

| Aspek             | Ketentuan                                                                                                                             |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Setup             | `composer run setup` (install → `.env` → key → migrate → npm install → build). Dev: `composer run dev` (serve + queue + pail + vite). |
| Environment wajib | `APP_NAME="Dashboard Keragaan RSF"`, `SESSION_DRIVER=database`, `SESSION_LIFETIME=10`.                                                |
| Cron              | `* * * * * php artisan schedule:run` (menjalankan `activity:prune` harian).                                                           |
| Kebijakan migrasi | Dilarang edit migration lama in-place — selalu migration baru (begitu aplikasi dipakai).                                              |
| Kebijakan seeding | `UserSeeder` = **truncate** (hanya inisialisasi); produksi pakai `users:sync`.                                                        |
| Format kode       | `./vendor/bin/pint` sebelum commit.                                                                                                   |
| Git               | Commit rapi per fitur; jalankan test + `pint` sebelum commit.                                                                         |

---

## 18. Glosarium

| Istilah                         | Arti                                                                                 |
| ------------------------------- | ------------------------------------------------------------------------------------ |
| **RSF**                         | Wilayah kerja BRI Regional Office Jakarta 2 yang dicakup aplikasi ini.               |
| **Keragaan**                    | Kinerja/performance bisnis.                                                          |
| **DPK**                         | Dana Pihak Ketiga (simpanan: Tabungan, Giro, Deposito).                              |
| **CASA**                        | Current Account Saving Account = Giro + Tabungan.                                    |
| **LDR**                         | Loan to Deposit Ratio = Pinjaman / DPK.                                              |
| **OS**                          | Outstanding (baki debet total pinjaman).                                             |
| **SML**                         | Special Mention Loan (kolektibilitas dalam perhatian khusus).                        |
| **NPL**                         | Non-Performing Loan (kredit bermasalah).                                             |
| **PH**                          | Pinjaman Hapus buku (write-off), flow bulanan.                                       |
| **Net DG**                      | Net Downgrade — perpindahan bersih ke kualitas lebih buruk, memperhitungkan PH.      |
| **Recovery EC**                 | Penagihan/pengembalian atas kredit ekstra-comptable (yang sudah dihapusbukukan).     |
| **RKA**                         | Rencana Kerja & Anggaran (target).                                                   |
| **RO / BO / SBO / UNIT / KK**   | Regional Office / Branch Office / Sub-BO / BRI Unit / Kantor Kas.                    |
| **Uker**                        | Unit kerja (BO/SBO/UNIT/KK).                                                         |
| **EOM**                         | End of Month (akhir bulan).                                                          |
| **D-1 / MTD / YTD / YoY / MoM** | Delta vs kemarin / awal bulan / awal tahun / tahun lalu / bulan lalu (tanggal sama). |
| **TID / MID**                   | Terminal ID / Merchant ID (EDC).                                                     |
| **EDC SV Rp.0**                 | EDC dengan sales volume nol (KPI inverse — makin sedikit makin baik).                |
| **Morning call**                | Rapat pagi harian Region — konsumen utama halaman Present RSF.                       |

---

_PRD ini adalah **spesifikasi untuk membangun Dashboard Keragaan RSF dari nol**. Semua kebutuhan, aturan bisnis, dan formula di atas adalah target yang harus dibangun dan dikunci dengan test otomatis. Rekomendasi urutan: bangun satu domain end-to-end lebih dulu (Simpanan/DPK), lalu replikasi polanya ke domain lain._
