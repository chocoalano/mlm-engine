<?php

declare(strict_types=1);

/*
 * Setiap teks yang ditampilkan komponen Vue yang di-publish.
 *
 * Terpisah dari kelompok lain karena perjalanannya berbeda: berkas inilah
 * yang diserialkan ke `usePage().props.translations` oleh `SharePanelData`,
 * lalu dibaca komponen lewat `useTranslator()`. Sisa `lang/` dibaca di PHP
 * dan tidak pernah meninggalkan server.
 *
 * Kunci dikelompokkan menurut tempatnya dibaca, bukan menurut isinya, supaya
 * teks satu komponen berkumpul dan satu kelompok dapat ditelusuri sambil
 * melihat layar yang digambarnya.
 *
 * Hanya elemen bawaan yang ada di sini. Setiap label yang berasal dari schema
 * — judul kolom, label isian, tombol action — diselesaikan di server dan
 * sampai ke sini dalam keadaan sudah diterjemahkan, karena di sanalah schema
 * itu berada. Lihat `PandaPanel\Support\Label`.
 */

return [

    /*
     * Primitif shadcn. Sebagian besar label untuk pembaca layar: pengguna
     * awas melihat tanda ✕, selebihnya mendengar apa yang tertulis di sini.
     */
    'ui' => [
        'copy_command' => 'Salin perintah :name',
        'close' => 'Tutup',
        'more' => 'Lainnya',
        'loading' => 'Memuat',
        'sidebar' => 'Bilah sisi',
        'sidebar_description' => 'Menampilkan bilah sisi untuk perangkat seluler.',
        'toggle_sidebar' => 'Buka atau tutup bilah sisi',
        'breadcrumb' => 'remah roti',
        'appearance' => 'Tampilan',
        'appearance_light' => 'Terang',
        'appearance_dark' => 'Gelap',
        'appearance_system' => 'Sistem',
        'show_password' => 'Tampilkan kata sandi',
        'hide_password' => 'Sembunyikan kata sandi',
        'open' => 'Buka',
    ],

    /*
     * Kerangka panel: header, bilah sisi, pencarian, notifikasi, pengalih.
     */
    'shell' => [
        'current_section' => 'Bagian saat ini',
        'panel_navigation' => 'Navigasi panel',
        'record_navigation' => 'Navigasi data',
        'switch_panel' => 'Ganti panel',
        'switch_panel_description' => 'Panel yang dapat Anda akses. Anda sedang berada di :panel.',
        'none' => 'tidak ada',
        'switch_tenant' => 'Ganti tenant',
        'switch_language' => 'Bahasa',
        'select' => 'Pilih',
        'light_mode' => 'Beralih ke mode terang',
        'dark_mode' => 'Beralih ke mode gelap',
        'unsaved_changes' => 'Ada perubahan yang belum disimpan. Tinggalkan halaman ini dan buang perubahannya?',

        'search' => 'Cari',
        'search_description' => 'Cari di seluruh resource panel ini.',
        'search_placeholder' => 'Cari...',
        'search_too_short' => 'Ketik minimal dua karakter.',
        'search_empty' => 'Tidak ada yang ditemukan.',
        'search_label' => 'Cari di panel ini',
        'search_results' => 'Hasil pencarian',
        'search_searching' => 'Mencari…',
        'search_result_count' => ':count hasil',
        'search_failed' => 'Pencarian tidak dapat dijalankan.',
        'search_stale' => 'Menampilkan hasil sebelumnya — pembaruan gagal.',
        'retry' => 'Coba lagi',

        'notifications' => 'Notifikasi',
        'notification_center' => 'Pusat notifikasi',
        'unread_count' => ':count belum dibaca',
        'mark_all_read' => 'Tandai semua terbaca',
        'clear_read' => 'Bersihkan yang terbaca',
        'mark_as_read' => 'Tandai terbaca',
        'read' => 'Terbaca',
        'notifications_failed' => 'Notifikasi tidak dapat dimuat.',
        'notifications_empty' => 'Belum ada apa pun di sini.',
    ],

    /*
     * Dasbor sebuah panel sebelum ada apa pun yang terdaftar di dalamnya.
     */
    'dashboard' => [
        'this_panel' => 'Panel ini',
        'ready' => ':panel sudah siap. Dasbornya masih kosong.',
        'empty' => 'Belum ada apa pun yang terdaftar untuk ditampilkan di sini. Jalankan salah satu perintah berikut dan hasilnya akan muncul di layar ini saat Anda memuatnya lagi.',
        'add_widget' => 'Tambah widget',
        'add_widget_description' => 'Sebuah angka, grafik, atau tabel ringkas. Widget adalah bahan pembentuk dasbor.',
        'add_resource' => 'Tambah resource',
        'add_resource_description' => 'Sebuah model dengan tabel, formulir, dan empat halamannya. Ia masuk ke bilah sisi dengan sendirinya.',
        'already_here' => 'Yang sudah ada:',
    ],

    /*
     * Tabel: toolbar, kepala tabel, baris, penomoran halaman, dua renderer.
     */
    'tables' => [
        'move_column' => 'Pindahkan :column',
        'sort_by' => 'Urutkan menurut :column',
        'reorder_row' => 'Ubah urutan :record, posisi :position dari :total',
        'reorder_announcement' => ':record dipindahkan ke posisi :position dari :total',
        'move_up' => 'Naikkan',
        'move_down' => 'Turunkan',
        'select_row' => 'Pilih :record',
        'per_page' => ':count / halaman',
        'page_of' => 'Halaman :page dari :pages',
        'remove_filter' => 'Hapus :filter',
        'filter_from' => ':filter dari',
        'filter_to' => ':filter sampai',
        'rule_value' => 'Nilai untuk :rule',
        'remove_rule' => 'Hapus aturan :number',
        'rule' => 'aturan',
        'select_all_rows' => 'Pilih semua baris di halaman ini',
        'row_actions' => 'Action baris',
        'actions' => 'Action',
        'reorder' => 'Ubah urutan',
        'sort' => 'Urutkan',
        'filter' => 'Filter',
        'filters_not_applied' => 'Belum diterapkan.',
        'clear' => 'Bersihkan',
        'apply' => 'Terapkan',
        'column' => 'Kolom',
        'condition' => 'Kondisi',
        'add_condition' => 'Tambah kondisi',
        'max_conditions' => 'Maksimal :count kondisi.',
        'no_queryable_columns' => 'Tidak ada kolom yang tersedia untuk difilter.',
        'from' => 'Dari',
        'to' => 'Sampai',
        'layout' => 'Tata letak',
        'layout_table' => 'Tampilan tabel',
        'layout_cards' => 'Tampilan kartu',
        'search_table' => 'Cari di tabel ini',
        'search_column' => 'Cari :column',
        'no_results' => 'Tidak ada hasil',
        'range' => ':from–:to dari :total',
        'sorted_by' => 'Diurutkan berdasarkan :column',
        'all' => 'Semua',
        'rows_per_page' => 'Baris per halaman',
        'previous_page' => 'Halaman sebelumnya',
        'next_page' => 'Halaman berikutnya',
        'previous' => 'Sebelumnya',
        'next' => 'Berikutnya',
    ],

    /*
     * Elemen bawaan formulir, dan isian yang menggambar kontrolnya sendiri.
     */
    'forms' => [
        'create_record' => 'Buat :resource',
        'confirm_field' => 'Konfirmasi :field',
        'search_field_placeholder' => 'Cari :field…',
        'remove_file' => 'Hapus :file',
        'move_item_up' => 'Naikkan :item',
        'move_item_down' => 'Turunkan :item',
        'remove_item' => 'Hapus :item',
        'save' => 'Simpan',
        'cancel' => 'Batal',
        'back' => 'Kembali',
        'next' => 'Lanjut',
        'loading' => 'Memuat',
        'load_failed' => 'Formulir ini tidak dapat dimuat.',
        'error_summary' => 'Formulir ini memiliki :count kesalahan. Perbaiki lalu coba lagi.',
        'tab_has_errors' => 'Tab ini memiliki kesalahan',
        'no_renderer' => 'Isian ini tidak memiliki renderer.',
        'create_another' => 'Buat & buat lagi',

        'select_placeholder' => 'Pilih...',
        'select_empty' => 'Tidak ada pilihan.',
        'select_more' => '+:count lainnya',
        'search_field' => 'Cari :field',
        'select_no_matches' => 'Tidak ada yang cocok dengan pencarian itu.',
        'select_failed' => 'Daftar tidak dapat dimuat.',
        'select_stale' => 'Menampilkan hasil sebelumnya — pembaruan gagal.',
        'retry' => 'Coba lagi',
        'checkbox_select_all' => 'Pilih semua',
        'checkbox_deselect_all' => 'Batalkan semua pilihan',

        'add_row' => 'Tambah baris',
        'remove' => 'Hapus',
        'no_entries' => 'Belum ada isian.',

        'no_blocks' => 'Belum ada blok.',
        'block_unavailable' => 'Tipe blok ini sudah tidak tersedia.',
        'move_block_up' => 'Naikkan blok',
        'move_block_down' => 'Turunkan blok',
        'remove_block' => 'Hapus blok',
        'collapse_section' => 'Tutup bagian',
        'expand_section' => 'Buka bagian',

        'pick_a_date' => 'Pilih tanggal',
        'color_for_field' => 'Warna :field',
        'clear_date' => 'Hapus tanggal',
        'pick_a_time' => 'Pilih waktu',
        'clear_time' => 'Hapus waktu',
        'date' => 'Tanggal',
        'time' => 'Waktu',
        'date_and_time' => 'Tanggal dan waktu',
        'hour' => 'Jam',
        'minute' => 'Menit',
        'second' => 'Detik',
        'uploads_unavailable' => 'Formulir ini tidak dapat menyimpan berkas.',
        'link' => 'Tautan',
        'link_url' => 'URL tautan',
        'plain_text' => 'Teks biasa',
        'write' => 'Tulis',
        'preview' => 'Pratinjau',
        'editor_link' => 'Tautan',
        'editor_bullet_list' => '• Daftar',
        'editor_ordered_list' => '1. Daftar',
    ],

    /*
     * Action, dan dialog yang dibukanya.
     */
    'actions' => [
        'cancel' => 'Batal',
        'row_actions' => 'Action baris',
        'copy' => 'Salin',
    ],

    /*
     * Widget: elemen bawaan di sekeliling apa pun yang digambarnya.
     */
    'widgets' => [
        'chart_summary' => ':type, :series seri pada :categories periode',
        'chart_line' => 'Diagram garis',
        'chart_bar' => 'Diagram batang',
        'chart_area' => 'Diagram area',
        'category' => 'Periode :number',
        'category_empty' => ':category — tidak ada data',
        'chart_data' => 'Data diagram',
        'show_data' => 'Lihat data',
        'hide_data' => 'Sembunyikan data',
        'period' => 'Periode',
        'trend_positive' => 'peningkatan',
        'trend_negative' => 'penurunan',
        'filters' => 'Filter',
        'unavailable' => 'Widget ini tidak tersedia.',
        'empty' => 'Belum ada yang dapat ditampilkan.',
        'no_data' => 'Tidak ada data untuk periode ini.',
        'increased' => 'Naik',
        'decreased' => 'Turun',
        'unchanged' => 'Tetap',
        'search' => 'Cari',
        'search_table' => 'Cari di tabel ini',
        'previous' => 'Sebelumnya',
        'next' => 'Berikutnya',
    ],

    /*
     * Layar integrasi: permintaan keluar yang dikirim sebuah resource.
     */
    'integrations' => [
        'new_request' => 'Permintaan baru',
        'request_name' => 'Nama permintaan',
        'trigger' => 'Pemicu',
        'active' => 'Aktif',
        'off' => 'Nonaktif',
        'method' => 'Metode',
        'url' => 'URL',
        'url_placeholder' => 'https://api.example.com/hooks/record',
        'save' => 'Simpan',
        'send' => 'Kirim',
        'delete' => 'Hapus',
        'send_failed' => 'Permintaan tidak dapat dikirim.',

        'no_hosts' => 'Belum ada tujuan yang diizinkan. Tambahkan host ke',
        'no_hosts_after' => '; sampai saat itu setiap URL di sini akan ditolak ketika disimpan.',
        'empty' => 'Belum ada permintaan',
        'empty_description' => 'Permintaan di sini dikirim ketika sebuah data ditulis.',

        'params' => 'Parameter',
        'headers' => 'Header',
        'body' => 'Isi',
        'signing' => 'Penandatanganan',
        'history' => 'Riwayat',
        'value' => 'Nilai',
        'bodies' => 'Isi',
        'header' => 'Header',
        'parameter' => 'Parameter',
        'reveal' => 'Tampilkan',
        'hide' => 'Sembunyikan',
        'failed' => 'gagal',
        'just_now' => 'baru saja',
        'signature_hmac' => ', berupa HMAC-SHA256 atas',
        'template_hint' => 'digantikan dari payload. Ini bukan Blade — hanya jalur, tanpa ekspresi.',

        'signature_intro' => 'Setiap permintaan membawa',
        'signature_middle' => 'menggunakan kunci di bawah ini, dan',
        'signature_after' => 'yang tetap sama di seluruh percobaan ulang satu pengiriman, sehingga penerima dapat menyaring duplikat.',
        'signing_secret' => 'Kunci penanda tangan',
        'rotate' => 'Ganti',
        'rotate_warning' => 'Penggantian berlaku pada pengiriman berikutnya. Perbarui sistem penerima terlebih dahulu.',
        'history_empty' => 'Belum ada yang dikirim',
        'history_empty_description' => 'Percobaan pengiriman muncul di sini setelah permintaan ini dijalankan.',
    ],

    /*
     * Layar yang dilayani Fortify: masuk, dan cara kembali masuk.
     */
    'auth' => [
        'sign_in' => 'Masuk',
        'log_in' => 'Masuk',
        'log_out' => 'Keluar',
        'sign_up' => 'Daftar',
        'name' => 'Nama',
        'email' => 'Alamat surel',
        'email_placeholder' => 'surel@contoh.com',
        'password' => 'Kata sandi',
        'confirm_password' => 'Konfirmasi kata sandi',
        'new_password' => 'Kata sandi baru',
        'remember_me' => 'Ingat saya',
        'continue' => 'Lanjut',
        'sign_in_code' => 'Kode masuk',
        'register' => 'Daftar',
        'verify_email_title' => 'Verifikasi surel',
        'login_description' => 'Masukkan data Anda untuk melanjutkan ke :brand.',
        'register_description' => 'Daftar untuk melanjutkan ke :brand.',
        'email_code_description' => 'Kami telah mengirimkan kode enam digit ke :email.',

        'forgot_password' => 'Lupa kata sandi',
        'forgot_password_link' => 'Lupa kata sandi Anda?',
        'forgot_password_description' => 'Masukkan alamat surel Anda dan kami akan mengirimkan tautan pengaturan ulang.',
        'email_reset_link' => 'Kirim tautan pengaturan ulang',
        'back_to_login' => 'Kembali ke halaman masuk',
        'reset_password' => 'Atur ulang kata sandi',

        'create_account' => 'Buat akun',
        'create_an_account' => 'Buat sebuah akun',
        'have_account' => 'Sudah punya akun?',
        'no_account' => 'Belum punya akun?',

        'verify_email' => 'Verifikasi surel Anda',
        'verify_email_description' => 'Kami telah mengirimkan tautan. Buka tautan itu untuk menyelesaikan proses masuk.',
        'verify_email_sent' => 'Tautan baru telah dikirim ke alamat Anda.',
        'resend_link' => 'Kirim ulang tautan',

        'check_email' => 'Periksa surel Anda',
        'code' => 'Kode',
        'send_another_code' => 'Kirim kode lagi',
        'wait_before_retry' => 'Tunggu :seconds detik sebelum meminta lagi',

        'passkey_sign_in' => 'Masuk dengan passkey',
        'passkey_authenticating' => 'Mengautentikasi...',
        'or_continue_with_email' => 'Atau lanjutkan dengan surel',
    ],

    /*
     * Halaman akun yang didaftarkan setiap panel.
     */
    'settings' => [
        'save' => 'Simpan',
        'name' => 'Nama',
        'full_name' => 'Nama lengkap',
        'email' => 'Alamat surel',
        'email_unverified' => 'Alamat surel Anda belum diverifikasi.',
        'email_resend' => 'Klik di sini untuk mengirim ulang surel verifikasi.',
        'email_resent' => 'Tautan verifikasi baru telah dikirim ke alamat surel Anda.',

        'current_password' => 'Kata sandi saat ini',
        'new_password' => 'Kata sandi baru',
        'confirm_password' => 'Konfirmasi kata sandi',

        'passkeys' => 'Passkey',
        'passkeys_description' => 'Kelola passkey Anda untuk masuk tanpa kata sandi',
        'passkeys_empty' => 'Belum ada passkey',
        'passkeys_empty_description' => 'Tambahkan passkey untuk masuk tanpa kata sandi',

        'two_factor' => 'Autentikasi dua faktor',
        'two_factor_description' => 'Kelola pengaturan autentikasi dua faktor Anda',
        'two_factor_disabled_description' => 'Setelah autentikasi dua faktor diaktifkan, Anda akan diminta memasukkan pin saat masuk. Pin itu diambil dari aplikasi pendukung TOTP di ponsel Anda.',
        'two_factor_enabled_description' => 'Anda akan diminta memasukkan pin acak yang aman saat masuk, yang dapat Anda ambil dari aplikasi pendukung TOTP di ponsel Anda.',
        'two_factor_enable' => 'Aktifkan 2FA',
        'two_factor_disable' => 'Nonaktifkan 2FA',
        'two_factor_continue_setup' => 'Lanjutkan penyiapan',

        'email_codes' => 'Kode lewat surel',
        'email_codes_on' => 'Kode sekali pakai dikirim ke alamat surel Anda setiap kali Anda masuk pada sesi baru.',
        'email_codes_off' => 'Kirim kode sekali pakai ke alamat surel Anda saat masuk.',
        'turn_on' => 'Aktifkan',
        'turn_off' => 'Nonaktifkan',

        'delete_account' => 'Hapus akun',
        'delete_account_description' => 'Hapus akun Anda beserta seluruh datanya',
        'delete_account_warning_heading' => 'Peringatan',
        'delete_account_warning' => 'Harap berhati-hati, tindakan ini tidak dapat dibatalkan.',
        'delete_account_confirm' => 'Apakah Anda yakin ingin menghapus akun Anda?',
        'delete_account_explanation' => 'Setelah akun Anda dihapus, seluruh data dan sumber dayanya juga akan dihapus permanen. Masukkan kata sandi Anda untuk memastikan bahwa Anda memang ingin menghapus akun ini secara permanen.',
        'password' => 'Kata sandi',
        'cancel' => 'Batal',
    ],
];
