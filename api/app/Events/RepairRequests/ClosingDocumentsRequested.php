<?php

declare(strict_types=1);

namespace App\Events\RepairRequests;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Нужно сформировать закрывающие документы ЗР (ЗН, Счёт, УПД, отчёт агента)
 * и отправить ЗН, Счёт и УПД в ЭДО. Триггер — переход ЗР на этап
 * «Выдан. Подготовка документов» или кнопка «Сформировать документы» в ЛК УК.
 */
final class ClosingDocumentsRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $requestId,
        public readonly ?int $userId = null,
    ) {}
}
