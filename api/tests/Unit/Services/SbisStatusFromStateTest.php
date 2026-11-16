<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Edo\EdoDocumentStatus;
use App\Services\Edo\Drivers\Sbis\SbisDocumentWorkflow;
use PHPUnit\Framework\TestCase;

final class SbisStatusFromStateTest extends TestCase
{
    public function test_maps_sbis_state_names(): void
    {
        $cases = [
            ['Отправлен', 'sender', EdoDocumentStatus::Sended],
            ['Доставлен', 'sender', EdoDocumentStatus::Delivered],
            ['Требует утверждения', 'receiver', EdoDocumentStatus::Received],
            ['Ожидает ответа', 'sender', EdoDocumentStatus::Delivered],
            ['Выполнение завершено успешно', 'sender', EdoDocumentStatus::Signed],
            ['Утвержден', 'receiver', EdoDocumentStatus::Signed],
            ['Отклонен', 'sender', EdoDocumentStatus::Rejected],
            ['Предложено аннулирование', 'receiver', EdoDocumentStatus::RequestedAvoidance],
            ['Аннулирован', 'sender', EdoDocumentStatus::AcceptedAvoidance],
            ['Ошибка доставки', 'sender', null],
            ['', 'sender', null],
        ];

        foreach ($cases as [$name, $side, $expected]) {
            $this->assertSame($expected, SbisDocumentWorkflow::statusFromState($name, $side), $name);
        }
    }
}
