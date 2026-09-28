<?php

declare(strict_types=1);

/*
 * Titik dalam siklus hidup sebuah data tempat integrasi dapat dijalankan.
 * Semuanya ditampilkan dalam sebuah select, jadi kata-katanya adalah yang
 * dipilih orang, bukan nilai mentah enum-nya.
 */

return [
    'page' => [
        'title' => 'Integrasi :resource',
        'heading' => 'Integrasi',
        'subheading' => 'Permintaan yang dikirim panel ini ketika :label ditulis.',
    ],

    'saved' => 'Integrasi berhasil disimpan.',
    'deleted' => 'Integrasi berhasil dihapus.',
    'secret_rotated' => 'Kunci penanda tangan berhasil diganti.',

    'trigger' => [
        'before_create' => 'Sebelum dibuat',
        'after_create' => 'Setelah dibuat',
        'before_update' => 'Sebelum diubah',
        'after_update' => 'Setelah diubah',
        'before_delete' => 'Sebelum dihapus',
        'after_delete' => 'Setelah dihapus',
    ],
];
