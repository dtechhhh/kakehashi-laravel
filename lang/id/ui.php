<?php

return [
    'app_name' => 'Kakehashi',
    'brand_subtitle' => 'Jembatan karier Indonesia–Jepang',
    'skip_link' => 'Lompat ke konten utama',

    'language' => [
        'id' => 'ID',
        'ja' => 'JA',
        'label' => 'Ganti bahasa',
    ],

    'nav' => [
        'label' => 'Navigasi utama',
        'home' => 'Beranda',
        'candidates' => 'Kandidat',
        'lookup' => 'Data Master',
        'requests' => 'Permintaan',
        'companies' => 'Perusahaan',
        'users' => 'Kelola Akun',
        'audit' => 'Audit',
    ],

    'common' => [
        'save' => 'Simpan',
        'cancel' => 'Batal',
        'back' => 'Kembali',
        'reload' => 'Muat ulang',
        'continue' => 'Lanjutkan',
        'confirm' => 'Konfirmasi',
        'close' => 'Tutup',
        'search' => 'Cari',
        'filter' => 'Filter',
        'actions' => 'Aksi',
        'view' => 'Lihat',
        'edit' => 'Ubah',
        'delete' => 'Hapus',
        'loading' => 'Memuat…',
        'logout' => 'Keluar',
        'login' => 'Masuk',
        'email' => 'Email',
        'password' => 'Kata sandi',
        'current_password' => 'Kata sandi saat ini',
        'new_password' => 'Kata sandi baru',
        'confirm_password' => 'Konfirmasi kata sandi baru',
    ],

    'auth' => [
        'login' => [
            'title' => 'Masuk',
            'subtitle' => 'Gunakan email dan kata sandi Anda untuk masuk.',
            'error_invalid' => 'Email atau kata sandi salah.',
            'error_inactive' => 'Akun dinonaktifkan. Hubungi Super Admin.',
            'error_generic' => 'Terjadi kesalahan. Silakan coba lagi.',
        ],
        'lockout' => [
            'title' => 'Terlalu banyak percobaan',
            'description' => 'Akun Anda dikunci sementara karena terlalu banyak percobaan masuk yang gagal.',
            'retry_in' => 'Coba lagi dalam :time',
            'back_to_login' => 'Kembali ke halaman masuk',
        ],
        'password_forced' => [
            'title' => 'Ubah kata sandi',
            'subtitle' => 'Anda harus mengganti kata sandi sebelum melanjutkan.',
            'policy' => 'Minimal 12 karakter dan mengandung 3 dari 4 kelas: huruf besar, huruf kecil, angka, simbol.',
            'success' => 'Kata sandi berhasil diubah.',
            'error_current' => 'Kata sandi saat ini tidak sesuai.',
            'error_policy' => 'Kata sandi baru tidak memenuhi kebijakan.',
        ],
        'enroll' => [
            'title' => 'Aktifkan verifikasi dua langkah',
            'subtitle' => 'Pindai kode QR dengan aplikasi autentikator, lalu masukkan kode 6 digit untuk konfirmasi.',
            'secret_label' => 'Atau masukkan kunci rahasia secara manual',
            'step_scan' => '1. Pindai kode QR di bawah ini',
            'step_confirm' => '2. Masukkan kode 6 digit dari aplikasi',
            'confirm_button' => 'Konfirmasi dan Aktifkan',
            'recovery_title' => 'Kode pemulihan',
            'recovery_description' => 'Simpan kode ini di tempat aman. Setiap kode hanya dapat dipakai satu kali.',
            'recovery_done' => 'Kode pemulihan sudah disimpan. Lanjutkan ke beranda.',
            'continue_home' => 'Lanjut ke Beranda',
            'already_enabled' => 'Verifikasi dua langkah sudah aktif.',
            'error_invalid_code' => 'Kode tidak valid. Coba lagi.',
            'error_generic' => 'Terjadi kesalahan saat mengaktifkan verifikasi dua langkah.',
        ],
        'challenge' => [
            'title' => 'Verifikasi dua langkah',
            'subtitle' => 'Masukkan kode 6 digit dari aplikasi autentikator Anda.',
            'code_label' => 'Kode 6 digit',
            'use_recovery' => 'Gunakan kode pemulihan',
            'use_code' => 'Gunakan kode dari aplikasi',
            'recovery_label' => 'Kode pemulihan',
            'error_invalid' => 'Kode tidak valid. Coba lagi.',
            'error_expired' => 'Sesi masuk kedaluwarsa. Silakan masuk kembali.',
        ],
    ],

    'state' => [
        'loading' => 'Memuat…',
        'empty' => [
            'title' => 'Belum ada data',
            'description' => 'Tidak ada data yang sesuai untuk ditampilkan.',
        ],
        'forbidden' => [
            'title' => 'Akses ditolak',
            'description' => 'Anda tidak memiliki izin untuk melihat halaman ini.',
        ],
        'not_found' => [
            'title' => 'Halaman tidak ditemukan',
            'description' => 'Halaman yang Anda cari tidak tersedia.',
        ],
        'session_expired' => [
            'title' => 'Sesi berakhir',
            'description' => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
        ],
        'conflict' => [
            'title' => 'Data telah diubah pihak lain',
            'description' => 'Data telah diubah oleh pihak lain. Muat ulang lalu coba lagi.',
        ],
    ],

    'date_time_format' => 'Y-m-d H:i',

    'home' => [
        'greeting' => 'Selamat datang, :name',
        'empty_title' => 'Beranda',
        'empty_description' => 'Pilih menu di atas untuk mulai bekerja.',
    ],

    'user_menu' => [
        'label' => 'Menu pengguna',
        'role' => 'Peran',
    ],

    'notifications' => [
        'title' => 'Notifikasi',
        'empty' => 'Tidak ada notifikasi.',
        'unread' => ':count notifikasi belum dibaca',
        'CANDIDATE_SUBMITTED' => 'Kandidat baru diajukan untuk ditinjau.',
        'CANDIDATE_REVISION_SUBMITTED' => 'Revisi kandidat diajukan untuk ditinjau.',
        'CANDIDATE_APPROVED' => 'Kandidat Anda disetujui.',
        'CANDIDATE_REJECTED' => 'Kandidat Anda ditolak. Lihat catatan penolakan.',
        'LOOKUP_REQUEST_SUBMITTED' => 'Permintaan data lookup baru diajukan.',
        'COMPANY_REQUESTED' => 'Permintaan data perusahaan baru diajukan.',
    ],
];
