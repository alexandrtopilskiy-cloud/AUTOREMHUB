<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\UserDto;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Enums\WorkChangeStatus;
use App\Models\RepairRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Реестр ЗР: серверные страницы, фильтры колонок и счётчики меню.
 *
 * Раньше список отдавал все ЗР полными объектами (история, документы,
 * вложения) — при ~1000 ЗР ответ весил десятки мегабайт и строился минутами.
 * Теперь реестр отдаёт страницу лёгких строк, а фильтры применяются в SQL
 * с той же логикой, что была на клиенте (каждое слово запроса должно
 * найтись в тексте колонки).
 */
final class RequestRegistryService
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    /** Подписи владельцев этапов, как в колонке «Статус» (front/src/data/workflow.js OWNERS). */
    private const STAGE_OWNERS = [
        'Заказчик',
        'УК',
        'СТО-партнёр',
        'Заказчик',
        'СТО-партнёр',
        'СТО-партнёр',
        'СТО-партнёр',
        'УК',
        'УК',
        'УК',
        'Архив',
    ];

    private const OVERDUE_TEXT = 'Превышен плановый срок';

    /**
     * Сумма ЗР в SQL — так же, как RepairRequestCalculator::total().
     */
    public const TOTAL_SQL = '(COALESCE((SELECT SUM(COALESCE(rw.line_amount, ROUND(rw.quantity * COALESCE(rw.price, 0), 2)))'
        . ' FROM request_works rw WHERE rw.repair_request_id = repair_requests.id), 0)'
        . ' + COALESCE((SELECT SUM(ROUND((rp.quantity - rp.returned_quantity) * rp.price, 2))'
        . ' FROM request_parts rp WHERE rp.repair_request_id = repair_requests.id), 0))';

    /**
     * @param array{document?: ?string, vehicle?: ?string, status?: ?string, amount?: ?string} $filters
     */
    public function page(UserDto $dto, string $scope, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = RepairRequest::forUser($dto)
            ->select('repair_requests.*')
            ->selectRaw(self::TOTAL_SQL . ' AS registry_total');

        if ($scope === 'action') {
            $this->requiresAction($query, $dto);
        }

        $this->applyFilters($query, $dto, $filters);

        return $query
            ->with(['vehicleRecord.organization', 'stationRecord', 'creator', 'periods'])
            ->orderByDesc('repair_requests.id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Счётчики меню «Ремонт»: все ЗР и ожидающие действия пользователя.
     *
     * @return array{all: int, action: int}
     */
    public function counts(UserDto $dto): array
    {
        $action = RepairRequest::forUser($dto);
        $this->requiresAction($action, $dto);

        return [
            'all'    => RepairRequest::forUser($dto)->count(),
            'action' => $action->count(),
        ];
    }

    /**
     * SQL-вариант Workflow::permissions()['requiresAction']: владелец этапа
     * (кроме закрытой ЗР и времени согласования изменений «В работе») или
     * участник, чья очередь согласовывать изменения работ/ЗЧ.
     */
    public function requiresAction(Builder $query, UserDto $dto): void
    {
        $role = $dto->role;
        $owned = array_values(array_map(
            static fn (RepairStage $stage): int => $stage->value,
            array_filter(
                RepairStage::cases(),
                static fn (RepairStage $stage): bool => $stage !== RepairStage::Draft
                    && $stage !== RepairStage::Closed
                    && $stage->ownerRole() === $role,
            ),
        ));
        $reviewStatuses = array_values(array_map(
            static fn (WorkChangeStatus $status): string => $status->value,
            array_filter(WorkChangeStatus::cases(), static fn (WorkChangeStatus $status): bool => $status->isReview()),
        ));
        $reviewerStatus = collect(WorkChangeStatus::cases())
            ->first(static fn (WorkChangeStatus $status): bool => $status->isReview() && $status->ownerRole() === $role)
            ?->value;
        $inWork = RepairStage::RepairInProgress->value;

        $query->where(function (Builder $q) use ($owned, $reviewStatuses, $reviewerStatus, $inWork, $role): void {
            if ($owned !== []) {
                $q->orWhere(function (Builder $own) use ($owned, $reviewStatuses, $inWork): void {
                    $own->whereIn('repair_requests.stage', $owned)
                        ->whereRaw(
                            'NOT (repair_requests.stage = ? AND COALESCE(repair_requests.work_change::json->>\'status\', \'\') IN ('
                                . implode(', ', array_fill(0, count($reviewStatuses), '?')) . '))',
                            [$inWork, ...$reviewStatuses],
                        );
                });
            }

            // Черновик принадлежит создателю (Workflow: владелец = роль создателя).
            $q->orWhere(function (Builder $draft) use ($role): void {
                $draft->where('repair_requests.stage', RepairStage::Draft->value)
                    ->whereIn('repair_requests.created_by', User::query()->select('id')->where('role', $role->value));
            });

            if ($reviewerStatus !== null) {
                $q->orWhere(function (Builder $review) use ($inWork, $reviewerStatus): void {
                    $review->where('repair_requests.stage', $inWork)
                        ->whereRaw('repair_requests.work_change::json->>\'status\' = ?', [$reviewerStatus]);
                });
            }
        });
    }

    /**
     * @param array<string, ?string> $filters
     */
    private function applyFilters(Builder $query, UserDto $dto, array $filters): void
    {
        $document = $this->fragments($filters['document'] ?? null);
        if ($document !== []) {
            $timezone = (string) config('sto.service_timezone', 'Europe/Moscow');
            if (!preg_match('/^[A-Za-z0-9_\/+\-]+$/', $timezone)) {
                $timezone = 'UTC';
            }
            $created = "TO_CHAR((repair_requests.created_at AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}', 'DD.MM.YYYY, HH24:MI')";
            $this->matchEach($query, $document, ['repair_requests.number', $created]);
        }

        $vehicle = $this->fragments($filters['vehicle'] ?? null);
        if ($vehicle !== []) {
            $showCompany = $dto->role !== UserRole::Station;
            foreach ($vehicle as $fragment) {
                $query->where(function (Builder $q) use ($fragment, $showCompany): void {
                    $q->whereHas('vehicleRecord', function (Builder $v) use ($fragment): void {
                        $this->contains($v, $fragment, ['vehicles.name', 'vehicles.plate', 'vehicles.vin']);
                    });
                    if ($showCompany) {
                        $q->orWhereHas('vehicleRecord.organization', function (Builder $o) use ($fragment): void {
                            $this->contains($o, $fragment, ['organizations.name']);
                        });
                    }
                    // Пустой черновик в колонке подписан «Автомобиль не выбран · Пустой черновик».
                    if (str_contains('автомобиль не выбран пустой черновик', $fragment)) {
                        $q->orWhereNull('repair_requests.vehicle_id');
                    }
                });
            }
        }

        $status = $this->fragments($filters['status'] ?? null);
        foreach ($status as $fragment) {
            $query->where(function (Builder $q) use ($fragment): void {
                foreach ($this->statusCategories() as [$text, $condition]) {
                    if (str_contains(mb_strtolower($text), $fragment)) {
                        $q->orWhere($condition);
                    }
                }
                if (str_contains(mb_strtolower(self::OVERDUE_TEXT), $fragment)) {
                    $q->orWhere(function (Builder $overdue): void {
                        $overdue->where('repair_requests.stage', '>=', RepairStage::RepairInProgress->value)
                            ->whereNotNull('repair_requests.planned_completion_at')
                            ->whereRaw('COALESCE(repair_requests.repair_completed_at, NOW()) > repair_requests.planned_completion_at');
                    });
                }
                // Ни одна категория не подошла — пустой результат.
                $q->orWhereRaw('1 = 0');
            });
        }

        $amount = str_replace([' ', "\u{00A0}", '₽', 'р.', 'руб'], '', mb_strtolower(trim((string) ($filters['amount'] ?? ''))));
        $amount = str_replace(',', '.', $amount);
        if ($amount !== '') {
            $query->whereRaw('STRPOS(CAST(ROUND(' . self::TOTAL_SQL . ', 2) AS TEXT), ?) > 0', [$amount]);
        }
    }

    /**
     * Текст колонки «Статус» по категориям ЗР и условие категории.
     *
     * @return list<array{0: string, 1: \Closure}>
     */
    private function statusCategories(): array
    {
        $categories = [];
        $owner = static fn (RepairStage $stage): string => self::STAGE_OWNERS[$stage->value] ?? '';
        $inWork = RepairStage::RepairInProgress;
        $reviewStatuses = ['manager', 'customer'];

        foreach (RepairStage::cases() as $stage) {
            if ($stage === RepairStage::ManagerRouting) {
                $categories[] = [WorkChangeStatus::STATUS_LABEL . ' ' . $owner($stage), static fn (Builder $q) => $q
                    ->where('repair_requests.stage', $stage->value)
                    ->where('repair_requests.review_context', 'station_changes')];
                $categories[] = ['Маршрутизация УК (отказ СТО) ' . $owner($stage), static fn (Builder $q) => $q
                    ->where('repair_requests.stage', $stage->value)
                    ->where('repair_requests.review_context', 'station_declined')];
                $categories[] = [$stage->label() . ' ' . $owner($stage), static fn (Builder $q) => $q
                    ->where('repair_requests.stage', $stage->value)
                    ->where(fn (Builder $c) => $c->whereNull('repair_requests.review_context')
                        ->orWhereNotIn('repair_requests.review_context', ['station_changes', 'station_declined']))];
                continue;
            }

            if ($stage === $inWork) {
                $categories[] = [WorkChangeStatus::STATUS_LABEL . ' ' . $owner($stage), static fn (Builder $q) => $q
                    ->where('repair_requests.stage', $stage->value)
                    ->whereRaw('COALESCE(repair_requests.work_change::json->>\'status\', \'\') IN (?, ?)', $reviewStatuses)];
                $categories[] = [$stage->label() . ' ' . $owner($stage), static fn (Builder $q) => $q
                    ->where('repair_requests.stage', $stage->value)
                    ->whereRaw('COALESCE(repair_requests.work_change::json->>\'status\', \'\') NOT IN (?, ?)', $reviewStatuses)];
                continue;
            }

            $categories[] = [$stage->label() . ' ' . $owner($stage), static fn (Builder $q) => $q
                ->where('repair_requests.stage', $stage->value)];
        }

        return $categories;
    }

    /**
     * @return list<string>
     */
    private function fragments(?string $value): array
    {
        return preg_split('/\s+/u', mb_strtolower(trim((string) $value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Каждое слово должно найтись хотя бы в одной из колонок.
     *
     * @param list<string> $fragments
     * @param list<string> $columns выражения из кода приложения, не ввод пользователя
     */
    private function matchEach(Builder $query, array $fragments, array $columns): void
    {
        foreach ($fragments as $fragment) {
            $query->where(fn (Builder $q) => $this->contains($q, $fragment, $columns));
        }
    }

    /**
     * @param list<string> $columns
     */
    private function contains(Builder $query, string $fragment, array $columns): void
    {
        $query->where(function (Builder $inner) use ($fragment, $columns): void {
            foreach ($columns as $column) {
                $inner->orWhereRaw('STRPOS(LOWER(COALESCE(CAST(' . $column . ' AS TEXT), \'\')), ?) > 0', [$fragment]);
            }
        });
    }
}
