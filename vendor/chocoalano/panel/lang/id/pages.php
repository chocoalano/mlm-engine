<?php

declare(strict_types=1);

/*
 * Layar yang didaftarkan package ini ke setiap panel dengan sendirinya, dan
 * teks yang ditampilkan widget sebelum ada yang bisa ditampilkan.
 *
 * Halaman milik panel itu sendiri tidak ada di sini: judulnya berasal dari
 * aplikasi, dan di sanalah terjemahannya seharusnya berada.
 */

return [
    'navigation_group' => [
        'account' => 'Akun',
    ],

    'dashboard' => [
        'title' => 'Dasbor',
    ],

    'profile' => [
        'title' => 'Profil',
        'subheading' => 'Perbarui nama dan alamat surel Anda.',
    ],

    'security' => [
        'title' => 'Keamanan',
        'subheading' => 'Kata sandi, autentikasi dua faktor, dan passkey.',
    ],

    'appearance' => [
        'title' => 'Tampilan',
        'subheading' => 'Atur tampilan antarmuka pada perangkat ini.',
    ],

    'record_navigation' => [
        'view' => 'Lihat',
        'edit' => 'Ubah',
    ],

    'widgets' => [
        'table_empty' => 'Belum ada yang dapat ditampilkan.',
    ],
];
