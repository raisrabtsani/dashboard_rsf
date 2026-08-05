import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Font identitas: Inter (dimuat di app.blade.php).
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            /*
             * IDENTITAS WARNA <BANK/REGION KAMI>.
             *
             * `brand` = biru korporat (anchor 600 = #0857C3, biru "Nusantara" yang
             * sudah dipakai di chartColors.js). SATU sumber warna merek — untuk
             * mengganti identitas, ubah skala ini saja, jangan sebar hex di komponen.
             *
             * Makna naik/turun/warning DIPERTAHANKAN lewat alias semantik yang
             * menunjuk emerald/rose/amber — sama dengan ambang di utils/pencapaian.js:
             *   positif (hijau) = naik/tercapai · negatif (merah) = turun/di bawah
             *   waspada (kuning) = mendekati/peringatan.
             */
            colors: {
                brand: {
                    50: '#f0f6ff',
                    100: '#dfeaff',
                    200: '#c6d9ff',
                    300: '#a1beff',
                    400: '#6f97ff',
                    500: '#4472f5',
                    600: '#0857c3',
                    700: '#0b4aa3',
                    800: '#103f85',
                    900: '#12386b',
                    950: '#0c2444',
                },
                positif: colors.emerald,
                negatif: colors.rose,
                waspada: colors.amber,
            },

            screens: {
                // Videotron / TV besar — dipakai untuk melebarkan layout ringkasan.
                tv: '1920px',
            },
        },
    },

    plugins: [forms],
};
