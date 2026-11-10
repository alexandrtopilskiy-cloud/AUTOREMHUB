<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\UserDto;
use App\Enums\RequestDocumentType;
use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\RequestDocument;
use App\Models\StockDocument;
use App\Models\StockDocumentPayment;
use App\Models\User;

/**
 * Отчёт «Движение денежных средств» (ДДС) с точки зрения организации
 * пользователя. Источники денег:
 *  - оплаты во «Взаиморасчётах» ЗР: оплата Заказчика по счёту УК→Заказчик
 *    (УК — поступление, Заказчик — расход) и оплата СТО по счёту СТО→УК
 *    (УК — расход, СТО — поступление);
 *  - оплаты по складским документам организации: Приход — оплата
 *    Поставщику (расход), Реализация — оплата от Заказчика (поступление).
 *    Заказчик видит оплаты Реализаций, где он покупатель, как расход.
 *
 * Отдаются все движения; сальдо на начало/конец периода и фильтры считает
 * фронт (сальдо на начало = сумма движений до начала периода, начальных
 * остатков в системе нет).
 */
final class CashFlowReportService
{
    public const ARTICLES = [
        'customer_payment' => 'Оплата ремонта Заказчиком',
        'station_payment' => 'Оплата ремонта СТО',
        'receipt_payment' => 'Оплата Поставщику ЗЧ',
        'sale_payment' => 'Реализация ЗЧ',
    ];

    /** @return array{rows: list<array<string, mixed>>, articles: list<array{value: string, label: string}>} */
    public function build(User $user): array
    {
        $dto = UserDto::fromUserModel($user);
        $rows = [...$this->requestPayments($dto), ...$this->stockPayments($user)];

        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);
        $rows = array_map(static function (array $row): array {
            unset($row['sort']);

            return $row;
        }, $rows);

        $labels = $this->articleLabels($dto->role);

        return [
            'rows' => $rows,
            'articles' => array_values(array_map(
                static fn (string $key): array => ['value' => $key, 'label' => $labels[$key]],
                $this->articlesFor($dto->role),
            )),
        ];
    }

    /** @return list<string> */
    private function articlesFor(UserRole $role): array
    {
        return match ($role) {
            UserRole::Manager => ['customer_payment', 'station_payment', 'receipt_payment', 'sale_payment'],
            UserRole::Station => ['station_payment', 'receipt_payment', 'sale_payment'],
            UserRole::Customer => ['customer_payment', 'sale_payment'],
        };
    }

    /** @return array<string, string> */
    private function articleLabels(UserRole $role): array
    {
        return match ($role) {
            UserRole::Station => ['station_payment' => 'Оплата ремонта от УК'] + self::ARTICLES,
            UserRole::Customer => ['customer_payment' => 'Оплата ремонта', 'sale_payment' => 'Покупка ЗЧ'] + self::ARTICLES,
            default => self::ARTICLES,
        };
    }

    /** @return list<array<string, mixed>> */
    private function requestPayments(UserDto $dto): array
    {
        $types = match ($dto->role) {
            UserRole::Manager => [RequestDocumentType::CustomerInvoice, RequestDocumentType::StationInvoice],
            UserRole::Station => [RequestDocumentType::StationInvoice],
            UserRole::Customer => [RequestDocumentType::CustomerInvoice],
        };
        $labels = $this->articleLabels($dto->role);

        $documents = RequestDocument::query()
            ->whereIn('type', array_map(static fn (RequestDocumentType $type): string => $type->value, $types))
            ->whereNotNull('paid_at')
            ->whereIn('repair_request_id', RepairRequest::forUser($dto)->select('id'))
            ->with(['repairRequest.stationRecord', 'repairRequest.customerOrganization', 'repairRequest.vehicleRecord', 'seller', 'buyer'])
            ->get();

        return $documents->map(function (RequestDocument $doc) use ($dto, $labels): array {
            $request = $doc->repairRequest;
            $isCustomerInvoice = $doc->type === RequestDocumentType::CustomerInvoice;
            $article = $isCustomerInvoice ? 'customer_payment' : 'station_payment';
            $incoming = match ($dto->role) {
                UserRole::Manager => $isCustomerInvoice,
                UserRole::Station => true,
                UserRole::Customer => false,
            };
            $counterparty = $incoming ? $doc->buyer?->name : $doc->seller?->name;
            $vehicle = $request?->vehicleRecord;

            return [
                'id' => 'request:' . $doc->uuid,
                'date' => $doc->paid_at->toDateString(),
                'direction' => $incoming ? 'in' : 'out',
                'amount' => round((float) ($doc->paid_amount ?? $doc->amount), 2),
                'article' => $article,
                'articleLabel' => $labels[$article],
                'source' => 'request',
                'document' => $doc->number,
                'requestId' => $request?->uuid,
                'requestNumber' => $request?->number,
                'stockDocumentId' => null,
                'stockDocumentType' => null,
                'counterparty' => (string) ($counterparty ?? ''),
                // СТО не видит наименование Заказчика (как в карточке ЗР и отчёте по ЗР).
                'customer' => $dto->role === UserRole::Station ? '' : (string) ($request?->customerOrganization?->name ?? ''),
                'station' => (string) ($request?->stationRecord?->name ?? ''),
                'vehicle' => $vehicle ? trim($vehicle->plate . ' ' . $vehicle->make . ' ' . $vehicle->model) : '',
                'method' => '',
                'comment' => (string) ($doc->paid_comment ?? ''),
                'sort' => 'r' . str_pad((string) $doc->id, 10, '0', STR_PAD_LEFT),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function stockPayments(User $user): array
    {
        $role = $user->role;
        $labels = $this->articleLabels($role);
        $query = StockDocumentPayment::query()
            ->with(['document.supplier', 'document.customer', 'document.vehicle', 'document.organization']);

        if ($role === UserRole::Customer) {
            $query->whereHas('document', fn ($doc) => $doc
                ->where('type', StockDocument::SALE)
                ->where('customer_organization_id', $user->organization_id));
        } else {
            $query->whereHas('document', fn ($doc) => $doc->where('organization_id', $user->organization_id));
        }

        return $query->get()->map(function (StockDocumentPayment $payment) use ($role, $labels): array {
            $doc = $payment->document;
            $isReceipt = $doc->type === StockDocument::RECEIPT;
            $article = $isReceipt ? 'receipt_payment' : 'sale_payment';
            $incoming = !$isReceipt && $role !== UserRole::Customer;
            $seller = $doc->organization;
            $counterparty = match (true) {
                $isReceipt => $doc->supplier?->name,
                $role === UserRole::Customer => $seller?->name,
                default => $doc->customer?->name,
            };
            $vehicle = $doc->vehicle;

            return [
                'id' => 'stock:' . $payment->uuid,
                'date' => $payment->paid_on->toDateString(),
                'direction' => $incoming ? 'in' : 'out',
                'amount' => round((float) $payment->amount, 2),
                'article' => $article,
                'articleLabel' => $labels[$article],
                'source' => $doc->type,
                'document' => $doc->number,
                'requestId' => null,
                'requestNumber' => null,
                'stockDocumentId' => $doc->uuid,
                'stockDocumentType' => $doc->type,
                'counterparty' => (string) ($counterparty ?? ''),
                'customer' => $isReceipt ? '' : (string) ($role === UserRole::Customer ? '' : ($doc->customer?->name ?? '')),
                'station' => $role === UserRole::Customer && $seller?->kind?->value === 'station' ? (string) $seller->name : '',
                'vehicle' => $vehicle ? trim($vehicle->plate . ' ' . $vehicle->make . ' ' . $vehicle->model) : '',
                'method' => StockDocumentPayment::METHODS[$payment->method] ?? '',
                'comment' => (string) ($payment->comment ?? ''),
                'sort' => 's' . str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT),
            ];
        })->values()->all();
    }
}
