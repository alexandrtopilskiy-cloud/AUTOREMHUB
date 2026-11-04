<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\RepairRequestResource;
use App\Services\WorkChangeService;
use App\Support\ApiInput;
use Illuminate\Http\Request;

/**
 * Изменение работ и запчастей на стадии «В работе»: отправка на согласование,
 * решения УК и Заказчика, отмена изменений СТО.
 */
final class WorkChangeController
{
    public function __construct(private readonly WorkChangeService $service) {}

    public function submit(Request $request, string $id): RepairRequestResource
    {
        ApiInput::only($request, ['revision', 'removedNotes']);
        $notes = $request->json('removedNotes', []);

        if (!is_array($notes) || ($notes !== [] && array_is_list($notes))) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'removedNotes должен быть объектом {id: примечание}');
        }

        return new RepairRequestResource(
            $this->service->submit($request->user(), $id, ApiInput::revision($request), $notes),
        );
    }

    public function decide(Request $request, string $id): RepairRequestResource
    {
        ApiInput::only($request, ['revision', 'decision', 'comment']);
        $decision = $request->json('decision');

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'decision: approved или rejected');
        }

        $comment = $request->json('comment', '');
        if (!is_string($comment) || mb_strlen($comment) > 2000) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Комментарий — строка до 2000 символов');
        }

        return new RepairRequestResource(
            $this->service->decide($request->user(), $id, ApiInput::revision($request), $decision === 'approved', $comment),
        );
    }

    public function discard(Request $request, string $id): RepairRequestResource
    {
        ApiInput::only($request, ['revision']);

        return new RepairRequestResource(
            $this->service->discard($request->user(), $id, ApiInput::revision($request)),
        );
    }
}
