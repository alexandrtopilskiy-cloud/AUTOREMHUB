<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CatalogKind;
use App\Enums\PartMovementKind;
use App\Enums\RequestPartStatus;
use App\Enums\UserRole;
use App\Http\Requests\MovePartRequest;
use App\Http\Requests\RemovePartRequest;
use App\Http\Requests\StorePartRequest;
use App\Http\Requests\UpdatePartStatusRequest;
use App\Models\Attachment;
use App\Models\CatalogPart;
use App\Models\PartMovement;
use App\Models\RepairRequest;
use App\Models\RequestPart;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CatalogAuditService;
use App\Services\RepairRequestService;
use App\Support\ApiInput;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Illuminate\Http\JsonResponse;

final class PartController
{
    public function __construct(
        private readonly RepairRequestService $service,
        private readonly ApiPresenter $apiPresenter,
        private readonly CatalogAuditService $catalogAudit,
    ) {
    }

    public function store(StorePartRequest $request, string $id): JsonResponse
    {
        return $this->save($request, $id, null);
    }

    public function update(StorePartRequest $request, string $id, string $partId): JsonResponse
    {
        return $this->save($request, $id, $partId);
    }

    public function remove(RemovePartRequest $request, string $id, string $partId): JsonResponse
    {
        $repairRequest = $this->service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'part_removed',
            function (RepairRequest $repairRequest) use ($request, $partId): array {
                $this->service->flag($repairRequest, $request->user(), 'canEditParts');

                $part = $repairRequest->parts()->where('uuid', $partId)->firstOrFail();

                $hasMovements = PartMovement::query()
                    ->where('request_part_id', $part->id)
                    ->exists();

                if (!$part->status->isEditable() || $hasMovements) {
                    Input::fail('Поставленную позицию удалять нельзя: оформите возврат');
                }

                $name = $part->name;
                $part->delete();

                $repairRequest->estimate_version++;
                RequestPart::query()
                    ->where('repair_request_id', $repairRequest->id)
                    ->update(['approved_version' => null]);

                return ['comment' => 'Удалена несогласованная позиция: ' . $name];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($repairRequest, $request->user()),
        ]);
    }

    public function status(UpdatePartStatusRequest $request, string $id, string $partId): JsonResponse
    {
        $comment = trim((string) $request->validated('comment'));

        if ($comment === '') {
            Input::fail('Укажите комментарий', 'comment');
        }

        $status = RequestPartStatus::from((string) $request->validated('status'));

        $repairRequest = $this->service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'part_status',
            function (RepairRequest $repairRequest) use ($request, $status, $comment, $partId): array {
                $this->service->flag($repairRequest, $request->user(), 'canManageParts');

                $part = $repairRequest->parts()->where('uuid', $partId)->firstOrFail();

                if ($part->approved_version !== $repairRequest->estimate_version) {
                    Input::fail('Запчасть ещё не согласована');
                }

                $expected = $status === RequestPartStatus::Ordered
                    ? RequestPartStatus::Approved
                    : RequestPartStatus::Ordered;

                if ($part->status !== $expected) {
                    Input::fail('Недопустимый переход состояния запчасти');
                }

                $part->update(['status' => $status]);

                return [
                    'comment' => 'Запчасть ' . $part->name . ': '
                        . $status->value . '. ' . $comment,
                ];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($repairRequest, $request->user()),
        ]);
    }

    public function movement(MovePartRequest $request, string $id, string $partId): JsonResponse
    {
        $comment = trim((string) $request->validated('comment'));

        if ($comment === '') {
            Input::fail('Укажите причину возврата или обмена', 'comment');
        }

        $kind = PartMovementKind::from((string) $request->validated('kind'));
        $qty = (string) $request->validated('qty');
        $attachmentIds = $request->validated('attachmentIds');
        $replacementId = $request->validated('replacementPartId');

        $repairRequest = $this->service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'part_movement',
            function (RepairRequest $repairRequest) use (
                $request,
                $kind,
                $qty,
                $comment,
                $attachmentIds,
                $replacementId,
                $partId,
            ): array {
                $this->service->flag($repairRequest, $request->user(), 'canManageParts');

                $part = $repairRequest->parts()
                    ->where('uuid', $partId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!$part->status->isOrderedOrReceived()) {
                    Input::fail('Возврат доступен для заказанной или полученной запчасти');
                }

                $remaining = bcsub((string) $part->quantity, (string) $part->returned_quantity, 3);

                if (bccomp($qty, $remaining, 3) > 0) {
                    Input::fail('Количество превышает остаток позиции');
                }

                $replacement = $this->resolveReplacement(
                    $repairRequest,
                    $kind,
                    $replacementId,
                    $part,
                    $qty,
                );

                $files = $this->service->attachmentIds($repairRequest, $attachmentIds);

                PartMovement::create([
                    'request_part_id'     => $part->id,
                    'replacement_part_id' => $replacement?->id,
                    'actor_id'            => $request->user()->id,
                    'kind'                => $kind,
                    'quantity'            => $qty,
                    'comment'             => $comment,
                    'attachment_ids'      => $attachmentIds,
                ]);

                $part->returned_quantity = bcadd((string) $part->returned_quantity, $qty, 3);

                if (bccomp((string) $part->returned_quantity, (string) $part->quantity, 3) === 0) {
                    $part->status = $kind === PartMovementKind::Exchange
                        ? RequestPartStatus::Exchanged
                        : RequestPartStatus::Returned;
                }

                $part->save();

                return [
                    'comment' => $kind->label() . ' запчасти '
                        . $part->name . ': ' . $comment,
                    'after'   => function ($event) use ($files): void {
                        Attachment::query()
                            ->whereIn('id', array_map(static fn ($file) => $file->id, $files))
                            ->update(['request_event_id' => $event->id]);
                    },
                ];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($repairRequest, $request->user()),
        ]);
    }

    /**
     * Создание или обновление позиции запчасти.
     */
    private function save(StorePartRequest $request, string $id, ?string $partId): JsonResponse
    {
        $name = trim((string) $request->validated('name'));

        if ($name === '') {
            Input::fail('Укажите наименование запчасти', 'name');
        }

        $supplierId = $request->validated('supplierId');
        $catalogPartId = $request->validated('catalogPartId');
        $article = (string) $request->validated('article');
        $unit = (string) $request->validated('unit');
        $quantity = (string) $request->validated('qty');
        $price = $request->validated('price');

        $repairRequest = $this->service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'part',
            function (RepairRequest $repairRequest) use (
                $request,
                $name,
                $article,
                $unit,
                $catalogPartId,
                $supplierId,
                $quantity,
                $price,
                $partId,
            ): array {
                $this->service->flag($repairRequest, $request->user(), 'canEditParts');

                $part = $partId
                    ? $repairRequest->parts()->where('uuid', $partId)->firstOrFail()
                    : new RequestPart(['repair_request_id' => $repairRequest->id]);
                $supplier = $this->resolveSupplier($repairRequest, $supplierId);
                $catalogPart = ($part->exists && !$part->catalog_part_id && empty($catalogPartId)) ? null : $this->resolveCatalogPart(
                    $request->user(),
                    $repairRequest,
                    $catalogPartId ?: $part->catalogPart?->uuid,
                    $name,
                    $article,
                    $unit,
                );


                if ($part->exists && !$part->status->isEditable()) {
                    Input::fail('Позиция уже заказана. Используйте возврат или обмен');
                }

                if ($part->exists && $part->returned_quantity > 0) {
                    Input::fail('Позиция имеет возвраты; добавьте отдельную согласуемую позицию');
                }

                $part->fill([
                    'catalog_part_id'  => $catalogPart?->id,
                    'name'             => $catalogPart?->name ?? $name,
                    'article'          => $catalogPart?->article ?? $article,
                    'unit'             => $catalogPart?->unit ?? $unit,
                    'supplier_id'      => $supplier?->id,
                    'quantity'         => $quantity,
                    'price'            => $price,
                    'status'           => RequestPartStatus::Proposed,
                    'approved_version' => null,
                ]);

                $part->save();

                $repairRequest->estimate_version++;
                RequestPart::query()
                    ->where('repair_request_id', $repairRequest->id)
                    ->update(['approved_version' => null]);

                return ['comment' => 'Сохранена запчасть: ' . $part->name];
            },
        );

        return response()->json(
            ['request' => $this->apiPresenter->document($repairRequest, $request->user())],
            $partId ? 200 : 201,
        );
    }

    private function resolveSupplier(RepairRequest $repairRequest, mixed $supplierId): ?Supplier
    {
        if (empty($supplierId)) {
            return null;
        }

        return Supplier::query()
            ->where('tenant_id', $repairRequest->tenant_id)
            ->where('active', true)
            ->where('uuid', $supplierId)
            ->firstOrFail();
    }

    private function resolveCatalogPart(
        User $user,
        RepairRequest $repairRequest,
        mixed $catalogPartId,
        string $name,
        string $article,
        string $unit,
    ): ?CatalogPart {
        if (!empty($catalogPartId)) {
            return CatalogPart::query()
                ->where('tenant_id', $repairRequest->tenant_id)
                ->where('active', true)
                ->where('uuid', $catalogPartId)
                ->firstOrFail();
        }

        Input::fail('Выберите запчасть из справочника или сначала создайте её со всеми обязательными реквизитами', 'catalogPartId');
    }

    private function resolveReplacement(
        RepairRequest $repairRequest,
        PartMovementKind $kind,
        mixed $replacementId,
        RequestPart $part,
        string $qty,
    ): ?RequestPart {
        if ($kind !== PartMovementKind::Exchange) {
            return null;
        }

        if (empty($replacementId)) {
            Input::fail('Выберите согласованную позицию на замену');
        }

        $replacement = $repairRequest->parts()->where('uuid', $replacementId)->firstOrFail();

        $replacementRemaining = bcsub(
            (string) $replacement->quantity,
            (string) $replacement->returned_quantity,
            3,
        );

        if (
            $replacement->id === $part->id
            || $replacement->approved_version !== $repairRequest->estimate_version
            || bccomp($replacementRemaining, $qty, 3) < 0
        ) {
            Input::fail('Замена должна быть отдельно согласована и иметь достаточное количество');
        }

        return $replacement;
    }
}
