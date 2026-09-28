<?php

declare(strict_types=1);

/*
 * Yang dikatakan panel ketika terjadi sesuatu yang tidak disaksikan langsung
 * oleh pengguna: permintaan yang gagal, atau surel yang dikirim untuk
 * menyelesaikan proses masuk.
 *
 * `http` dikunci berdasarkan status karena itulah yang diterima frontend.
 * Status yang tidak ada di sini tetap ditangani oleh Inertia sendiri,
 * sehingga menambahkan kunci di sini adalah cara membuat panel mulai
 * berbicara tentang status yang sebelumnya didiamkan.
 */

return [
    'http' => [
        403 => [
            'title' => 'Tidak diizinkan',
            'body' => 'Anda tidak memiliki izin untuk melakukan itu.',
        ],
        404 => [
            'title' => 'Tidak ditemukan',
            'body' => 'Data tersebut sudah tidak ada.',
        ],
        419 => [
            'title' => 'Sesi berakhir',
            'body' => 'Muat ulang halaman lalu coba lagi.',
        ],
        429 => [
            'title' => 'Terlalu banyak permintaan',
            'body' => 'Tunggu sebentar lalu coba lagi.',
        ],
        500 => [
            'title' => 'Terjadi kesalahan',
            'body' => 'Permintaan tidak dapat diselesaikan.',
        ],
        503 => [
            'title' => 'Sedang tidak tersedia',
            'body' => 'Aplikasi sedang dalam pemeliharaan.',
        ],
    ],

    'two_factor_code' => [
        'subject' => 'Kode masuk Anda',
        'intro' => 'Gunakan kode ini untuk menyelesaikan proses masuk:',
        'expiry' => 'Kode berlaku sepuluh menit dan hanya dapat dipakai sekali.',
        'warning' => 'Jika Anda tidak sedang mencoba masuk, segera ganti kata sandi Anda — ada orang lain yang mengetahuinya.',
    ],

    'two_factor' => [
        'throttled' => 'Terlalu banyak permintaan kode. Coba lagi nanti.',
        'sent' => 'Kode baru sedang dikirim.',
        'invalid' => 'Kode tersebut salah atau sudah kedaluwarsa.',
        'enabled' => 'Kode lewat surel diaktifkan.',
        'disabled' => 'Kode lewat surel dinonaktifkan.',
    ],

    'two_factor_required' => 'Siapkan autentikasi dua faktor untuk melanjutkan.',
];
