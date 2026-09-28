<?php

declare(strict_types=1);

/*
 * Pesan validasi yang berasal dari isian milik panel sendiri, bukan yang
 * sudah disediakan Laravel. Sejauh ini hanya builder yang memerlukannya: tipe
 * blok yang tidak ada dalam daftar isian ditolak di sisi server, dan pesannya
 * harus muncul di tempat isian itu digambar.
 */

return [
    'builder' => [
        'unknown_block' => 'Blok ini bukan blok yang disediakan isian ini.',
    ],
];
