<?php

declare(strict_types=1);

/*
 * Cara angka dan tanggal ditulis di sini.
 *
 * Isinya pemisah dan format, bukan kalimat, dan letaknya di `lang/` dengan
 * alasan yang sama seperti kalimat-kalimat itu: mana yang benar antara
 * `1,234.56` dan `1.234,56` adalah fakta tentang locale, dan panel yang
 * berganti bahasa tetapi mempertahankan pengelompokan angka Inggris akan
 * setengah diterjemahkan justru di tempat angka paling mudah salah dibaca
 * tanpa disadari.
 *
 * Bukan `Illuminate\Support\Number`, yang sebenarnya menangani ini dengan
 * benar lewat ICU — ia memanggil `ensureIntlExtensionIsInstalled()`, sementara
 * package ini hanya mensyaratkan `ext-json` dan `ext-zip`. Menjadikan
 * `ext-intl` syarat wajib sebuah panel admin adalah penghalang pemasangan
 * yang nyata di shared hosting.
 *
 * Format tanggal adalah format `date()`, dan semuanya nilai bawaan: kolom
 * atau entry yang memanggil `->format()` sudah menyatakan keinginannya dan
 * tidak pernah ditimpa dari sini.
 */

return [
    'decimal_separator' => ',',
    'thousands_separator' => '.',

    /** `DateColumn`, dan bagian tanggal dari apa pun yang menampilkannya. */
    'date' => 'j M Y',

    /** `DateTimeColumn` — sebuah sel tabel, jadi ringkas dan 24 jam. */
    'date_time' => 'j M Y H:i',

    /** `DateTimeEntry`, yang punya satu baris penuh untuk dirinya. */
    'date_time_verbose' => 'j M Y H:i',

    /** Chip filter, tempat sebuah tanggal hanya punya satu baris tombol. */
    'date_compact' => 'j M Y',
];
