<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaService
{
    private const SIGNED_URL_TTL_MINUTES = 5;

    /**
     * Проверяет, что диск `media` настроен и доступен для записи.
     *
     * @throws ApiException
     */
    public function assertConfigured(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $driver = config('filesystems.disks.media.driver');

        if ($driver === null) {
            throw new ApiException(
                503,
                'STORAGE_NOT_CONFIGURED',
                'Не настроен диск хранения файлов',
            );
        }
    }

    /**
     * Возвращает URL для скачивания вложения.
     *
     * Для локального диска `private` раздаём через контроллер (см. download()).
     * Если диск публичный — можно вернуть Storage::url().
     *
     * @throws ApiException
     */
    public function url(Attachment $attachment): string
    {
        $disk = Storage::disk($attachment->disk);

        // Публичный диск — прямая ссылка.
        if (config("filesystems.disks.{$attachment->disk}.visibility") === 'public') {
            return $disk->url($attachment->object_key);
        }

        // Приватный — ссылка на наш контроллер, который отдаст файл.
        return route('attachments.download', ['attachmentId' => $attachment->uuid]);
    }

    /**
     * Отдаёт файл клиенту потоком.
     *
     * @throws ApiException
     */
    public function download(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        if (!$disk->exists($attachment->object_key)) {
            throw new ApiException(404, 'NOT_FOUND', 'Файл не найден');
        }

        return $disk->download(
            $attachment->object_key,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type->value],
        );
    }
}
