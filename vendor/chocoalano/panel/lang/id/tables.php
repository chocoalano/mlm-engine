<?php

declare(strict_types=1);

/*
 * Elemen bawaan tabel — semua yang dikatakan tabel di luar kolom, filter,
 * atau action. Semuanya adalah nilai bawaan: tabel yang memanggil
 * `->emptyState()` atau `->searchPlaceholder()` akan menimpanya, dan berkas
 * ini hanyalah apa yang tertulis sebelum ada yang menimpanya.
 */

return [
    'reordered' => 'Urutan berhasil diperbarui.',

    'empty_state' => [
        'heading' => 'Data tidak ditemukan',
    ],

    'search' => [
        'placeholder' => 'Cari...',
    ],

    'filters' => [
        'trigger' => 'Filter',
        'apply' => 'Terapkan filter',
        'reset' => 'Bersihkan',
    ],

    'trashed_filter' => [
        'label' => 'Data terhapus',
        'without' => 'Disembunyikan',
        'with' => 'Disertakan',
        'only' => 'Hanya yang terhapus',
    ],
];
