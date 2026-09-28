<?php

declare(strict_types=1);

/*
 * Yang dikatakan panel ketika sebuah permintaan tidak dapat dilayani.
 *
 * Hanya penolakan yang benar-benar dapat dicapai pengguna yang ada di sini.
 * Pesan yang berarti "package ini salah pasang" — policy yang hilang, nama
 * kolom ganda, resource tanpa model — tetap berada di `PandaPanel\Exceptions`
 * dalam bahasa Inggris: pembacanya adalah developer yang sedang memegang
 * stack trace, dan menerjemahkannya hanya membuat masalah yang sama lebih
 * sulit dicari.
 */

return [
    'action_no_form' => 'Action ini tidak memiliki formulir.',
    'action_requires_form' => 'Aksi ini harus dijalankan melalui formulirnya.',
    'action_not_executable' => 'Action ini tidak dapat dijalankan.',
    'cell_not_editable' => 'Sel tersebut tidak dapat diubah.',
    'column_not_editable' => 'Kolom tersebut tidak dapat diubah.',
    'field_has_no_options' => 'Isian tersebut tidak memiliki pilihan.',
    'field_rejects_files' => 'Isian tersebut tidak menerima berkas.',
    'file_gone' => 'Berkas tersebut sudah tidak ada.',
    'file_not_stored' => 'Berkas tidak dapat disimpan.',
    'form_has_no_steps' => 'Formulir ini tidak memiliki tahapan.',
    'invalid_field' => 'Isian tidak valid.',
    'invalid_notification' => 'Notifikasi tidak valid.',
    'invalid_page' => 'Halaman tidak valid.',
    'invalid_parent_key' => 'Kunci induk tidak valid.',
    'invalid_record_key' => 'Kunci data tidak valid.',
    'invalid_record_keys' => 'Kunci data tidak valid.',
    'invalid_relation' => 'Relasi tidak valid.',
    'invalid_resource' => 'Resource tidak valid.',
    'invalid_scope' => 'Cakupan tidak valid.',
    'no_export_owner' => 'Pengguna tersebut tidak memiliki kunci untuk menyimpan hasil ekspor.',
    'no_file_uploaded' => 'Tidak ada berkas yang diunggah.',
    'no_import_owner' => 'Pengguna tersebut tidak memiliki kunci untuk menyimpan berkas impor.',
    'no_panel' => 'Tidak ada panel yang cocok untuk permintaan ini.',
    'no_such_tenant' => 'Tenant tersebut tidak ditemukan.',
    'not_notifiable' => 'Model pengguna tidak dapat menerima notifikasi.',
    'not_reorderable' => 'Urutan tabel ini tidak dapat diubah.',
    'record_already_related' => 'Data tersebut sudah ada dalam relasi ini.',
    'records_not_found' => 'Sebagian data tidak ditemukan.',
    'two_factor_page_missing' => 'Autentikasi dua faktor diwajibkan, tetapi halaman keamanan belum terdaftar.',
    'unknown_action' => 'Action tidak dikenali.',
    'unknown_bulk_action' => 'Action massal tidak dikenali.',
    'unknown_column' => 'Kolom tidak dikenali.',
    'unknown_export' => 'Ekspor tidak dikenali.',
    'unknown_field' => 'Isian tidak dikenali.',
    'unknown_locale' => 'Panel ini tidak tersedia dalam bahasa tersebut.',
    'unknown_import' => 'Impor tidak dikenali.',
    'unknown_relation' => 'Relasi tidak dikenali.',
    'unknown_relation_operation' => 'Operasi relasi tidak dikenali.',
    'unknown_resource' => 'Resource tidak dikenali.',
    'unknown_step' => 'Tahapan tidak dikenali.',
    'unsupported_trigger' => 'Pemicu tersebut bukan pemicu yang dijalankan resource ini.',

    /*
     * Kegagalan membaca berkas yang baru saja diunggah. Pesan ini sampai ke
     * pengguna sebagai isi notifikasi "Impor gagal", satu-satunya tempat
     * pesan ini akan dibaca — jadi isinya menyebut apa yang salah dengan
     * berkasnya, bukan apa yang melempar exception.
     */
    'spreadsheet' => [
        'unreadable' => 'Berkas tersebut bukan spreadsheet yang dapat dibaca.',
        'read_failed' => 'Spreadsheet tersebut tidak dapat dibaca.',
        'no_sheet' => 'Buku kerja tersebut tidak memiliki lembar yang dapat dibaca.',
        'too_large' => 'Spreadsheet tersebut terlalu besar untuk dibaca dengan aman.',
        'report_unwritable' => 'Laporan kegagalan tidak dapat ditulis.',
        'report_unreadable' => 'Laporan kegagalan tidak dapat dibaca kembali.',
        'export_temp_failed' => 'Berkas sementara untuk ekspor tidak dapat dibuat.',
        'export_unreadable' => 'Berkas ekspor tidak dapat dibaca kembali.',
    ],

    /*
     * Ditampilkan di samping isian URL pada layar integrasi, sehingga setiap
     * pesan menyebutkan apa yang perlu diubah, bukan sekadar bahwa sesuatu
     * ditolak.
     */
    'outbound_url' => [
        'not_a_url' => 'Itu bukan URL yang dapat dikirimi permintaan.',
        'unsupported_scheme' => 'Hanya http dan https yang didukung, bukan :scheme.',
    ],
];
