<?php

declare(strict_types=1);

/*
 * Setiap teks yang ditampilkan oleh action bawaan panel.
 *
 * Kunci dikelompokkan menurut action pemiliknya, dan urutannya mengikuti
 * urutan yang ditemui pengguna: tombol, lalu modal yang dibukanya, lalu
 * kalimat yang menegaskan apa yang terjadi. `relations` memuat varian untuk
 * relation manager, yang berbeda hanya di tempat yang memang harus berbeda —
 * menghapus record terkait tidak sama dengan melepas kaitannya, dan
 * konfirmasi yang salah menyebutkan lebih buruk daripada tidak ada.
 */

return [
    'create' => [
        'label' => ':label baru',
        'modal_heading' => ':label baru',
        'submit' => 'Buat',
        'success' => ':label berhasil dibuat.',
    ],

    'edit' => [
        'label' => 'Ubah',
    ],

    'view' => [
        'label' => 'Lihat',
    ],

    'delete' => [
        'label' => 'Hapus',
        'heading' => 'Hapus data ini?',
        'description' => 'Data akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.',
        'button' => 'Hapus',
        'success' => 'Data berhasil dihapus.',
    ],

    'delete_bulk' => [
        'label' => 'Hapus terpilih',
        'heading' => 'Hapus data yang dipilih?',
        'description' => 'Semua data yang dipilih akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.',
        'button' => 'Hapus',
        'success' => 'Data yang dipilih berhasil dihapus.',
        'denied' => 'Anda tidak berhak menghapus seluruh data yang dipilih.',
    ],

    'force_delete' => [
        'label' => 'Hapus permanen',
        'heading' => 'Hapus data ini secara permanen?',
        'description' => 'Tindakan ini tidak dapat dibatalkan dan data tidak dapat dipulihkan lagi.',
        'button' => 'Hapus permanen',
        'success' => 'Data berhasil dihapus permanen.',
    ],

    'force_delete_bulk' => [
        'label' => 'Hapus permanen terpilih',
        'heading' => 'Hapus data yang dipilih secara permanen?',
        'description' => 'Tindakan ini tidak dapat dibatalkan dan data tidak dapat dipulihkan lagi.',
        'button' => 'Hapus permanen',
        'success' => 'Data yang dipilih berhasil dihapus permanen.',
        'denied' => 'Anda tidak berhak menghapus permanen seluruh data yang dipilih.',
    ],

    'restore' => [
        'label' => 'Pulihkan',
        'success' => 'Data berhasil dipulihkan.',
    ],

    'restore_bulk' => [
        'label' => 'Pulihkan terpilih',
        'success' => 'Data yang dipilih berhasil dipulihkan.',
        'denied' => 'Anda tidak berhak memulihkan seluruh data yang dipilih.',
    ],

    'replicate' => [
        'label' => 'Duplikat',
        'heading' => 'Duplikat data ini?',
        'description' => 'Salinan akan dibuat. Anda dapat mengubahnya setelah itu.',
        'button' => 'Duplikat',
        'success' => 'Data berhasil diduplikat.',
    ],

    'export' => [
        'label' => 'Ekspor',
        'modal_heading' => 'Ekspor data',
        'submit' => 'Ekspor',
        'success' => 'Berkas ekspor Anda sudah siap.',
        'columns' => 'Kolom',
        'format' => 'Format',
        'download' => 'Unduh',
        'completed' => 'Ekspor :count data sudah siap.',
        'failed_title' => 'Ekspor gagal',
        'failed_body' => 'Berkas tidak dapat ditulis.',
    ],

    'import' => [
        'label' => 'Impor',
        'modal_heading' => 'Impor data',
        'submit' => 'Impor',
        'description' => 'Unggah berkas CSV atau Excel, lalu tentukan kolom mana untuk apa.',
        'file' => 'Berkas',
        'columns_section' => 'Kolom',
        'required' => 'Wajib diisi',
        'mapping_hint' => 'Kosongkan sebuah kolom untuk melewatinya. Kolom yang kosong ditebak dari judul kolom berkas.',
        'started' => 'Impor Anda telah dimulai. Anda akan diberi tahu setelah selesai.',
        'download_failed_rows' => 'Unduh baris yang gagal',
        'error_heading' => 'Kesalahan',
        'completed' => ':count baris berhasil diimpor.',
        'completed_with_failures' => ':count baris berhasil diimpor. :failed baris gagal — unduh laporannya untuk melihat sebabnya.',
        'failed_title' => 'Impor gagal',
        'failed_body' => 'Berkas tidak dapat dibaca.',
        'missing_columns' => 'Berkas ini tidak memiliki kolom untuk :missing, dan :verb wajib diisi. Judul kolom yang ada: :headings. Ubah nama kolom pada berkas, atau petakan secara manual sebelum mengimpor.',
        'missing_columns_verb' => '{1} kolom tersebut|[2,*] kolom-kolom tersebut',
        'no_headings' => '(tidak ada)',
    ],

    'relations' => [
        'create' => [
            'label' => ':title baru',
        ],

        'edit' => [
            'label' => 'Ubah',
        ],

        'associate' => [
            'label' => 'Kaitkan :title',
        ],

        'attach' => [
            'label' => 'Lampirkan :title',
        ],

        'delete' => [
            'label' => 'Hapus',
            'heading' => 'Hapus data ini?',
            'description' => 'Ini menghapus datanya sendiri, bukan hanya kaitannya dengan data ini.',
            'button' => 'Hapus',
            'success' => 'Data berhasil dihapus.',
        ],

        'detach' => [
            'label' => 'Lepaskan',
            'heading' => 'Lepaskan data ini?',
            'description' => 'Datanya sendiri tetap disimpan; hanya kaitannya yang dilepas.',
            'button' => 'Lepaskan',
            'success' => 'Data berhasil dilepaskan.',
        ],

        'detach_bulk' => [
            'label' => 'Lepaskan terpilih',
            'heading' => 'Lepaskan data yang dipilih?',
            'description' => 'Data-datanya sendiri tetap disimpan; hanya kaitannya yang dilepas.',
            'button' => 'Lepaskan',
            'success' => 'Data yang dipilih berhasil dilepaskan.',
            'denied' => 'Anda tidak berhak melepaskan seluruh data yang dipilih.',
        ],

        'dissociate' => [
            'label' => 'Putuskan kaitan',
            'heading' => 'Putuskan kaitan data ini?',
            'description' => 'Datanya tetap disimpan, tetapi tidak lagi menjadi milik data ini.',
            'button' => 'Putuskan kaitan',
            'success' => 'Kaitan data berhasil diputus.',
        ],
    ],

    'confirmation' => [
        'description' => 'Tindakan ini tidak dapat dibatalkan.',
    ],

    'bulk_denied' => 'Anda tidak berhak melakukan :action pada seluruh data yang dipilih.',
];
