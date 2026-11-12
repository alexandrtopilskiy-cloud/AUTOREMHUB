<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PartMovementKind;
use App\Enums\RequestPartStatus;
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
use App\Services\WorkChangeService;
use App\Support\ApiInput;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Illuminate\Http\JsonResponse;
use App\DTO\UserDto;

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
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $req = $this->service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            'part_removed',
            function (RepairRequest $req) use ($userDto, $partId): array {
                $this->service->flag($req, $userDto, 'canEditParts');
                WorkChangeService::begin($req);

                $part = $req->parts()->where('uuid', $partId)->firstOrFail();

                $hasMovements = PartMovement::query()
                    ->where('request_part_id', $part->id)
                    ->exists();

                if (!$part->status->isEditable() || $hasMovements) {
                    Input::fail('Поставленную позицию удалять нельзя: оформите возврат');
                }

                $name = $part->name;
                $part->delete();

                $this->service->markEstimateChanged($req);

                return ['comment' => 'Удалена несогласованная позиция: ' . $name];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($req, $userDto),
        ]);
    }

    public function status(UpdatePartStatusRequest $request, string $id, string $partId): JsonResponse
    {
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $comment = trim((string) $request->validated('comment'));

        if ($comment === '') {
            Input::fail('Укажите комментарий', 'comment');
        }

        $status = RequestPartStatus::from((string) $request->validated('status'));

        $req = $this->service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            'part_status',
            function (RepairRequest $req) use ($userDto, $status, $comment, $partId): array {
                $this->service->flag($req, $userDto, 'canManageParts');

                $part = $req->parts()->where('uuid', $partId)->firstOrFail();

                if ($part->approved_version !== $req->estimate_version) {
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
                    'comment' => 'Запчасть ' . $part->name . ': ' . $status->value . '. ' . $comment,
                ];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($req, $userDto),
        ]);
    }

    public function movement(MovePartRequest $request, string $id, string $partId): JsonResponse
    {
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $comment = trim((string) $request->validated('comment'));

        if ($comment === '') {
            Input::fail('Укажите причину возврата или обмена', 'comment');
        }

        $kind = PartMovementKind::from((string) $request->validated('kind'));
        $qty = (string) $request->validated('qty');
        $attachmentIds = $request->validated('attachmentIds');
        $replacementId = $request->validated('replacementPartId');

        $req = $this->service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            'part_movement',
            function (RepairRequest $req) use (
                $userDto,
                $kind,
                $qty,
                $comment,
                $attachmentIds,
                $replacementId,
                $partId,
            ): array {
                $this->service->flag($req, $userDto, 'canManageParts');

                $part = $req->parts()
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
                    $req,
                    $kind,
                    $replacementId,
                    $part,
                    $qty,
                );

                $files = $this->service->attachmentIds($req, $attachmentIds);

                PartMovement::create([
                    'request_part_id'     => $part->id,
                    'replacement_part_id' => $replacement?->id,
                    'actor_id'            => $userDto->userId,
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
                    'comment' => $kind->label() . ' запчасти ' . $part->name . ': ' . $comment,
                    'after'   => function ($event) use ($files): void {
                        Attachment::query()
                            ->whereIn('id', array_map(static fn ($file) => $file->id, $files))
                            ->update(['request_event_id' => $event->id]);
                    },
                ];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($req, $userDto),
        ]);
    }

    /**
     * Создание или обновление позиции запчасти.
     */
    private function save(StorePartRequest $request, string $id, ?string $partId): JsonResponse
    {
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
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

        $req = $this->service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            'part',
            function (RepairRequest $req) use (
                $userDto,
                $name,
                $article,
                $unit,
                $catalogPartId,
                $supplierId,
                $quantity,
                $price,
                $partId,
            ): array {
                $this->service->flag($req, $userDto, 'canEditParts');
                WorkChangeService::begin($req);

                $part = $partId
                    ? $req->parts()->where('uuid', $partId)->firstOrFail()
                    : new RequestPart(['repair_request_id' => $req->id]);
                $supplier = $this->resolveSupplier($req, $supplierId);
                $catalogPart = ($part->exists && !$part->catalog_part_id && empty($catalogPartId))
                    ? null
                    : $this->resolveCatalogPart($req, $catalogPartId ?: $part->catalogPart?->uuid);


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

                $this->service->markEstimateChanged($req);

                return ['comment' => 'Сохранена запчасть: ' . $part->name];
            },
        );

        return response()->json(
            ['request' => $this->apiPresenter->document($req, $userDto)],
            $partId ? 200 : 201,
        );
    }

    private function resolveSupplier(RepairRequest $req, mixed $supplierId): ?Supplier
    {
        if (empty($supplierId)) {
            return null;
        }

        return Supplier::query()
            ->where('tenant_id', $req->tenant_id)
            ->where('active', true)
            ->where('uuid', $supplierId)
            ->firstOrFail();
    }

    private function resolveCatalogPart(RepairRequest $req, mixed $catalogPartId): ?CatalogPart
    {
        if (empty($catalogPartId)) {
            Input::fail('Выберите запчасть из справочника или сначала создайте её со всеми обязательными реквизитами', 'catalogPartId');
        }

        return CatalogPart::query()
            ->where('tenant_id', $req->tenant_id)
            ->where('active', true)
            ->where('uuid', $catalogPartId)
            ->firstOrFail();
    }

    private function resolveReplacement(
        RepairRequest $req,
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

        $replacement = $req->parts()->where('uuid', $replacementId)->firstOrFail();

        $replacementRemaining = bcsub(
            (string) $replacement->quantity,
            (string) $replacement->returned_quantity,
            3,
        );

        if (
            $replacement->id === $part->id
            || $replacement->approved_version !== $req->estimate_version
            || bccomp($replacementRemaining, $qty, 3) < 0
        ) {
            Input::fail('Замена должна быть отдельно согласована и иметь достаточное количество');
        }

        return $replacement;
    }
}
