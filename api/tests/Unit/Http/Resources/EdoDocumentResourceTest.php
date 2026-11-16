<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Enums\Edo\EdoDocumentStatus;
use App\Http\Resources\EdoDocumentResource;
use PHPUnit\Framework\TestCase;

final class EdoDocumentResourceTest extends TestCase
{
    public function test_every_status_has_expected_action_for_both_directions(): void
    {
        foreach (EdoDocumentStatus::cases() as $status) {
            foreach ([true, false] as $outgoing) {
                [$label, $owner] = EdoDocumentResource::expectedAction($status, $outgoing);
                $this->assertNotSame('', $label, $status->value);
                $this->assertContains($owner, ['me', 'counterparty', 'operator', null], $status->value);
            }
        }
    }

    public function test_sender_signs_draft_and_receiver_signs_sent_document(): void
    {
        $this->assertSame(['Подписать и отправить получателю', 'me'], EdoDocumentResource::expectedAction(EdoDocumentStatus::Draft, true));
        $this->assertSame(['Ожидается подпись получателя', 'counterparty'], EdoDocumentResource::expectedAction(EdoDocumentStatus::Sended, true));
        $this->assertSame(['Подписать (получатель)', 'me'], EdoDocumentResource::expectedAction(EdoDocumentStatus::Delivered, false));
        $this->assertSame(['Действий не требуется', null], EdoDocumentResource::expectedAction(EdoDocumentStatus::Signed, false));
    }
}
