<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\ApprovalDto;
use App\DTO\NotificationDto;
use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;
use App\Enums\RequestPartStatus;
use App\Enums\UserRole;
use App\Enums\WorkChangeStatus;
use App\Models\Recommendation;
use App\Models\RecommendationItem;
use App\Models\RepairRequest;
use App\Models\RequestEvent;
use App\Models\RequestPart;
use App\Models\RequestWork;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DomainInput as Input;

/**
 * Изменение работ и запчастей на стадии «В работе».
 *
 * Перед первой правкой фиксируется согласованная смета (baseline). СТО вносит
 * изменения с примечанием к каждой изменённой строке и отправляет их на
 * согласование: УК → Заказчик. Несогласованные изменения возвращаются в СТО
 * с обязательным комментарием; СТО может доработать их или отменить, вернув
 * согласованную смету.
 */
final class WorkChangeService
{
    public const NOTE_DEFERRED = 'Перенесено в Рекомендации';

    private const SKIPPED_ATTRIBUTES = ['id', 'created_at', 'updated_at'];

    private const WORK_FIELDS = [
        'name'        => 'наименование',
        'norm_hours'  => 'норма времени, н/ч',
        'quantity'    => 'кол-во',
        'line_amount' => 'сумма, ₽',
    ];

    private const PART_FIELDS = [
        'name'        => 'наименование',
        'article'     => 'артикул',
        'unit'        => 'ед.',
        'supplier_id' => 'поставщик',
        'quantity'    => 'кол-во',
        'price'       => 'цена, ₽',
    ];

    private const NUMERIC_FIELDS = ['norm_hours', 'quantity', 'line_amount', 'price'];

    public function __construct(
        private readonly RepairRequestService $requests,
    ) {}

    /**
     * Фиксирует согласованную смету перед первой правкой на стадии «В работе».
     */
    public static function begin(RepairRequest $request): void
    {
        if ($request->stage !== RepairStage::RepairInProgress) {
            return;
        }

        $state = $request->work_change;

        if (is_array($state) && isset($state['baseline'])) {
            return;
        }

        $request->work_change = [
            'status'    => WorkChangeStatus::Editing->value,
            'startedAt' => now()->toISOString(),
            'baseline'  => [
                'estimateVersion' => $request->estimate_version,
                'works' => $request->workLines()->orderBy('id')->get()
                    ->map(static fn (RequestWork $work): array => self::rawAttributes($work))
                    ->all(),
                'parts' => $request->parts()->orderBy('id')->get()
                    ->map(static fn (RequestPart $part): array => self::rawAttributes($part))
                    ->all(),
            ],
            'removedNotes'      => [],
            'deferredItemIds'   => [],
            'rejection'         => null,
        ];
    }

    /**
     * Отмечает перенос строк в рекомендации как изменение сметы.
     *
     * @param array<int, string> $lineIds
     * @param array<int, string> $itemIds
     */
    public static function recordDeferred(RepairRequest $request, array $lineIds, array $itemIds, string $number): void
    {
        $state = $request->work_change;

        if (!is_array($state)) {
            return;
        }

        foreach ($lineIds as $lineId) {
            $state['removedNotes'][$lineId] = self::NOTE_DEFERRED . ' ' . $number;
        }

        $state['deferredItemIds'] = array_values(array_unique([...($state['deferredItemIds'] ?? []), ...$itemIds]));
        $request->work_change = $state;
    }

    /**
     * Перечень изменений относительно согласованной сметы.
     *
     * @return array<int, array<string, mixed>>
     */
    public function diff(RepairRequest $request, ?User $viewer = null): array
    {
        $state = $request->work_change;

        if (!is_array($state) || !isset($state['baseline'])) {
            return [];
        }

        $removedNotes = is_array($state['removedNotes'] ?? null) ? $state['removedNotes'] : [];
        $hideSupplier = $viewer?->role === UserRole::Customer;
        $works = $request->relationLoaded('workLines')
            ? $request->workLines
            : $request->workLines()->orderBy('id')->get();
        $parts = $request->relationLoaded('parts')
            ? $request->parts
            : $request->parts()->orderBy('id')->get();

        $supplierIds = array_filter([
            ...array_column($state['baseline']['parts'] ?? [], 'supplier_id'),
            ...$parts->pluck('supplier_id')->all(),
        ]);
        $suppliers = $hideSupplier ? collect() : Supplier::query()->whereIn('id', $supplierIds)->pluck('name', 'id');

        $fields = self::PART_FIELDS;
        if ($hideSupplier) {
            unset($fields['supplier_id']);
        }

        return [
            ...$this->lineDiff(
                'work',
                $state['baseline']['works'] ?? [],
                $works->map(static fn (RequestWork $work): array => self::rawAttributes($work))->all(),
                self::WORK_FIELDS,
                $removedNotes,
                $suppliers,
            ),
            ...$this->lineDiff(
                'part',
                $state['baseline']['parts'] ?? [],
                $parts->map(static fn (RequestPart $part): array => self::rawAttributes($part))->all(),
                $fields,
                $removedNotes,
                $suppliers,
            ),
        ];
    }

    /**
     * Данные для карточки заявки.
     *
     * @return array<string, mixed>|null
     */
    public function present(RepairRequest $request, User $viewer): ?array
    {
        $status = WorkChangeStatus::forRequest($request);

        if ($status === null) {
            return null;
        }

        $state = $request->work_change;
        $items = $this->diff($request, $viewer);

        // Правились только комментарии — показывать нечего.
        if ($items === [] && $status === WorkChangeStatus::Editing) {
            return null;
        }

        // Заказчик видит изменения только после согласования УК.
        if ($viewer->role === UserRole::Customer && $status !== WorkChangeStatus::CustomerReview) {
            $items = [];
        }

        return [
            'status'      => $status->value,
            'statusLabel' => $status->label(),
            'owner'       => $status->ownerLabel(),
            'items'       => $items,
            'submittedAt' => $state['submittedAt'] ?? null,
            'rejection'   => $state['rejection'] ?? null,
            'managerApproval' => $state['managerApproval'] ?? null,
        ];
    }

    /**
     * СТО отправляет изменения на согласование в УК.
     *
     * @param array<string, string> $removedNotes примечания к удалённым строкам
     */
    public function submit(User $user, string $key, int $revision, array $removedNotes): RepairRequest
    {
        return $this->requests->mutate($user, $key, $revision, 'work_change_submitted', function (RepairRequest $request) use ($user, $removedNotes): array {
            $this->requests->flag($request, $user, 'canSubmitWorkChange');

            $state = $request->work_change;
            foreach ($removedNotes as $lineId => $note) {
                if (!is_string($lineId) || !is_string($note)) {
                    Input::fail('Некорректные примечания к удалённым позициям', 'removedNotes');
                }
                $note = trim($note);
                if ($note !== '') {
                    $state['removedNotes'][$lineId] = mb_substr($note, 0, 2000);
                }
            }
            $request->work_change = $state;

            $items = $this->diff($request);
            if ($items === []) {
                Input::fail('Работы и запчасти не изменены. Отмените изменения, чтобы продолжить работу.');
            }

            foreach ($items as $item) {
                if (trim((string) $item['note']) === '') {
                    Input::fail(sprintf(
                        'Укажите примечание к изменению: %s «%s»',
                        $item['kind'] === 'work' ? 'работа' : 'запчасть',
                        $item['name'],
                    ), 'changeNote');
                }
            }

            $this->validateEstimate($request);

            $state['status'] = WorkChangeStatus::ManagerReview->value;
            $state['submittedAt'] = now()->toISOString();
            $state['submittedBy'] = $user->id;
            $state['rejection'] = null;
            $state['managerApproval'] = null;
            $request->work_change = $state;
            $changes = $this->describe($items);

            return [
                'comment' => 'СТО отправила изменения работ / з/ч на согласование',
                'details' => ['changes' => $changes],
                'after'   => function (RequestEvent $event) use ($request, $changes): void {
                    $this->requests->notifyUsers(NotificationDto::fromRequest(
                        $this->requests->managerUsers($request->tenant_id),
                        $request->id,
                        $event->id,
                        'manager_review_required',
                        'Согласование работ / з/ч',
                        "СТО изменила работы или запчасти по заявке {$request->number} в ходе ремонта. "
                        . 'Изменения: ' . implode('; ', $changes),
                    ));
                },
            ];
        });
    }

    /**
     * Решение УК или Заказчика по изменениям.
     */
    public function decide(User $user, string $key, int $revision, bool $approved, string $comment): RepairRequest
    {
        $comment = trim($comment);
        $action = $approved ? 'work_change_approved' : 'work_change_rejected';

        return $this->requests->mutate($user, $key, $revision, $action, function (RepairRequest $request) use ($user, $approved, $comment): array {
            $this->requests->flag($request, $user, 'canDecideWorkChange');

            if (!$approved && $comment === '') {
                Input::fail('Укажите, почему изменения не согласованы', 'comment');
            }

            $status = WorkChangeStatus::forRequest($request);
            $state = $request->work_change;
            $changes = $this->describe($this->diff($request));
            $who = $user->role === UserRole::Manager ? 'УК' : 'Заказчик';
            $snapshot = ['changes' => $changes];

            if (!$approved) {
                $state['status'] = WorkChangeStatus::Rejected->value;
                $state['rejection'] = [
                    'role'    => $user->role->value,
                    'by'      => $who,
                    'actor'   => $user->display_name,
                    'comment' => $comment,
                    'at'      => now()->toISOString(),
                ];
                $request->work_change = $state;

                return [
                    'comment' => "$who не согласовал(а) изменения работ / з/ч: $comment",
                    'details' => ['changes' => $changes],
                    'after'   => function (RequestEvent $event) use ($request, $user, $comment, $who, $snapshot): void {
                        $this->approval($request, $event, $user, RepairStage::RepairInProgress, ApprovalDecision::Returned, $comment, $snapshot);
                        $this->requests->notifyUsers(NotificationDto::fromRequest(
                            $this->requests->stationUsers($request),
                            $request->id,
                            $event->id,
                            'work_change_rejected',
                            'Изменения работ / з/ч не согласованы',
                            "$who не согласовал(а) изменения по заявке {$request->number}: $comment",
                        ));
                    },
                ];
            }

            if ($status === WorkChangeStatus::ManagerReview) {
                $state['status'] = WorkChangeStatus::CustomerReview->value;
                $state['managerApproval'] = [
                    'actor'   => $user->display_name,
                    'comment' => $comment,
                    'at'      => now()->toISOString(),
                ];
                $request->work_change = $state;

                return [
                    'comment' => 'УК согласовала изменения работ / з/ч' . ($comment !== '' ? ": $comment" : ''),
                    'details' => ['changes' => $changes],
                    'after'   => function (RequestEvent $event) use ($request, $user, $comment, $snapshot): void {
                        $this->approval($request, $event, $user, RepairStage::RepairInProgress, ApprovalDecision::Approved, $comment, $snapshot);
                        $this->requests->notifyUsers(NotificationDto::fromRequest(
                            $this->requests->customerUsers($request),
                            $request->id,
                            $event->id,
                            'customer_work_change_approval',
                            'Согласование работ / з/ч',
                            "По заявке {$request->number} в ходе ремонта изменены работы или запчасти. Согласуйте изменения.",
                        ));
                    },
                ];
            }

            // Заказчик согласовал: изменения становятся согласованной сметой.
            $request->estimate_version++;
            foreach ($request->parts()->get() as $part) {
                $part->approved_version = $request->estimate_version;
                if ($part->status === RequestPartStatus::Proposed) {
                    $part->status = RequestPartStatus::Approved;
                }
                $part->change_note = null;
                $part->save();
            }
            RequestWork::query()->where('repair_request_id', $request->id)->update(['change_note' => null]);
            $request->work_change = null;

            return [
                'comment' => 'Заказчик согласовал изменения работ / з/ч' . ($comment !== '' ? ": $comment" : ''),
                'details' => ['changes' => $changes],
                'after'   => function (RequestEvent $event) use ($request, $user, $comment, $snapshot): void {
                    // Запись «Принято Заказчиком» для текущей версии сметы нужна
                    // для завершения работ (проверка согласованной сметы).
                    $this->approval($request, $event, $user, RepairStage::CustomerApproval, ApprovalDecision::Accepted, $comment, $snapshot);
                    $this->requests->notifyUsers(NotificationDto::fromRequest(
                        $this->requests->stationUsers($request),
                        $request->id,
                        $event->id,
                        'work_change_approved',
                        'Изменения работ / з/ч согласованы',
                        "УК и Заказчик согласовали изменения работ и запчастей по заявке {$request->number}.",
                    ));
                },
            ];
        });
    }

    /**
     * СТО отменяет изменения и возвращает согласованную смету.
     */
    public function discard(User $user, string $key, int $revision): RepairRequest
    {
        return $this->requests->mutate($user, $key, $revision, 'work_change_discarded', function (RepairRequest $request) use ($user): array {
            $this->requests->flag($request, $user, 'canDiscardWorkChange');
            $this->restoreBaseline($request);
            $request->work_change = null;
            $this->requests->recalculatePlannedCompletion($request);

            return ['comment' => 'СТО отменила изменения работ / з/ч, восстановлена согласованная смета'];
        });
    }

    /**
     * Есть ли несогласованные изменения (используется при завершении работ).
     */
    public function hasPendingChanges(RepairRequest $request): bool
    {
        if (WorkChangeStatus::forRequest($request) === null) {
            return false;
        }

        if ($this->diff($request) !== []) {
            return true;
        }

        // Правились только комментарии — согласование не требуется.
        $request->work_change = null;

        return false;
    }

    /**
     * После сохранения на стадии «В работе»: если смета совпадает с
     * согласованной (правились только комментарии), согласование не нужно.
     */
    public function clearIfUnchanged(RepairRequest $request): void
    {
        if (WorkChangeStatus::forRequest($request) !== WorkChangeStatus::Editing) {
            return;
        }

        $request->unsetRelation('workLines');
        $request->unsetRelation('parts');
        $this->hasPendingChanges($request);
    }

    private function restoreBaseline(RepairRequest $request): void
    {
        $baseline = $request->work_change['baseline'] ?? null;

        if (!is_array($baseline)) {
            return;
        }

        // Комментарии к строкам сохраняются: отменяются только изменения сметы.
        $workComments = $request->workLines()->pluck('comment', 'uuid')->all();
        RequestWork::query()->where('repair_request_id', $request->id)->delete();
        foreach ($baseline['works'] ?? [] as $attributes) {
            (new RequestWork())->forceFill(array_merge($attributes, [
                'repair_request_id' => $request->id,
                'comment' => $workComments[$attributes['uuid']] ?? $attributes['comment'] ?? null,
                'change_note' => null,
            ]))->save();
        }

        $partService = app(RequestPartService::class);
        $existing = $request->parts()->lockForUpdate()->get()->keyBy('uuid');
        $kept = [];
        foreach ($baseline['parts'] ?? [] as $attributes) {
            $part = $existing->get($attributes['uuid']) ?? new RequestPart();
            $part->forceFill(array_merge($attributes, [
                'repair_request_id' => $request->id,
                'comment' => $part->exists ? $part->comment : ($attributes['comment'] ?? null),
                'change_note' => null,
            ]))->save();
            $kept[] = $attributes['uuid'];
        }
        foreach ($existing as $part) {
            if (!in_array($part->uuid, $kept, true)) {
                $partService->assertEditable($part);
                $part->delete();
            }
        }

        // Позиции, перенесённые в рекомендации в ходе отменённого изменения,
        // вернулись в заявку — убираем их из рекомендаций.
        $itemIds = $request->work_change['deferredItemIds'] ?? [];
        if ($itemIds !== []) {
            $items = RecommendationItem::query()->whereIn('uuid', $itemIds)->whereNull('moved_at')->get();
            $documentIds = $items->pluck('recommendation_id')->unique()->all();
            RecommendationItem::query()->whereIn('id', $items->pluck('id'))->delete();
            foreach (Recommendation::query()->whereIn('id', $documentIds)->get() as $document) {
                if (!$document->items()->exists()) {
                    $document->delete();
                }
            }
        }
    }

    private function validateEstimate(RepairRequest $request): void
    {
        if (!$request->workLines()->exists()) {
            Input::fail('Добавьте хотя бы одну работу');
        }

        foreach ($request->workLines()->get() as $work) {
            if (trim((string) $work->name) === '' || $work->quantity <= 0) {
                Input::fail('Заполните название и положительное количество работы');
            }

            $this->requests->validateWorkPricing($work);
        }

        foreach ($request->parts()->get() as $part) {
            if (!$part->supplier_id || trim((string) $part->name) === '' || $part->quantity <= 0) {
                Input::fail('Укажите поставщика, название и количество каждой запчасти');
            }
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function approval(
        RepairRequest $request,
        RequestEvent $event,
        User $user,
        RepairStage $stage,
        ApprovalDecision $decision,
        string $comment,
        array $snapshot,
    ): void {
        $this->requests->createApproval(ApprovalDto::fromRequest(
            request: $request,
            eventId: $event->id,
            userId: $user->id,
            stage: $stage,
            decision: $decision,
            comment: $comment,
            snapshot: $snapshot,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, string>
     */
    private function describe(array $items): array
    {
        return array_map(static function (array $item): string {
            $label = $item['kind'] === 'work' ? 'Работа' : 'Запчасть';
            $text = match ($item['action']) {
                'added'   => "$label добавлена: «{$item['name']}»",
                'removed' => "$label удалена: «{$item['name']}»",
                default   => "$label «{$item['name']}»: " . implode(', ', array_map(
                    static fn (array $field): string => "{$field['label']} {$field['before']} → {$field['after']}",
                    $item['fields'],
                )),
            };

            return $text . ($item['note'] !== '' ? " (примечание: {$item['note']})" : '');
        }, $items);
    }

    /**
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     * @param array<string, string> $fields
     * @param array<string, string> $removedNotes
     * @param \Illuminate\Support\Collection<int, string> $suppliers
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineDiff(string $kind, array $before, array $after, array $fields, array $removedNotes, $suppliers): array
    {
        $old = [];
        foreach ($before as $line) {
            $old[$line['uuid']] = $line;
        }

        $items = [];
        $seen = [];
        foreach ($after as $line) {
            $seen[$line['uuid']] = true;
            $previous = $old[$line['uuid']] ?? null;
            $note = trim((string) ($line['change_note'] ?? ''));

            if ($previous === null) {
                $items[] = $this->item($kind, 'added', $line, [], $note);

                continue;
            }

            $changed = [];
            foreach ($fields as $field => $label) {
                if (!$this->same($field, $previous[$field] ?? null, $line[$field] ?? null)) {
                    $changed[] = [
                        'field'  => $field,
                        'label'  => $label,
                        'before' => $this->display($field, $previous[$field] ?? null, $suppliers),
                        'after'  => $this->display($field, $line[$field] ?? null, $suppliers),
                    ];
                }
            }

            if ($changed !== []) {
                $items[] = $this->item($kind, 'changed', $line, $changed, $note);
            }
        }

        foreach ($before as $line) {
            if (!isset($seen[$line['uuid']])) {
                $items[] = $this->item($kind, 'removed', $line, [], trim((string) ($removedNotes[$line['uuid']] ?? '')));
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<int, array<string, string>> $fields
     *
     * @return array<string, mixed>
     */
    private function item(string $kind, string $action, array $line, array $fields, string $note): array
    {
        return [
            'kind'   => $kind,
            'action' => $action,
            'id'     => $line['uuid'],
            'name'   => (string) ($line['name'] ?? ''),
            'qty'    => isset($line['quantity']) ? (float) $line['quantity'] : null,
            'fields' => $fields,
            'note'   => $note,
        ];
    }

    private function same(string $field, mixed $before, mixed $after): bool
    {
        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            if ($before === null || $after === null || $before === '' || $after === '') {
                return ($before === null || $before === '') && ($after === null || $after === '');
            }

            return bccomp((string) $before, (string) $after, 3) === 0;
        }

        return (string) $before === (string) $after;
    }

    /**
     * @param \Illuminate\Support\Collection<int, string> $suppliers
     */
    private function display(string $field, mixed $value, $suppliers): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($field === 'supplier_id') {
            return (string) ($suppliers->get($value) ?? '—');
        }

        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
        }

        return (string) $value;
    }

    /**
     * Атрибуты строки в виде, пригодном для восстановления.
     *
     * @return array<string, mixed>
     */
    private static function rawAttributes(RequestWork|RequestPart $line): array
    {
        $attributes = $line->getAttributes();

        foreach (self::SKIPPED_ATTRIBUTES as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }
}
