<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'media'),
    'disks' => [
        /**
         * Локальный диск для вложений заявок.
         *
         * Файлы складываются в storage/app/media/... и раздаются
         * через AttachmentController::download() с проверкой прав.
         */
        'media' => [
            'driver'     => 'local',
            'root'       => storage_path('app/media'),
            'url'        => env('APP_URL') . '/api/attachments',
            'visibility' => 'private',
            'throw'      => true,
        ],

        /**
         * Публичный диск — если понадобится отдавать файлы напрямую.
         * Требует `php artisan storage:link`.
         */
        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],

        /**
         * Стандартный local — для всяких временных файлов.
         */
        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
        ],
    ],

    /**
     * Символическая ссылка для `public` диска.
     * `php artisan storage:link` создаст её.
     */
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
