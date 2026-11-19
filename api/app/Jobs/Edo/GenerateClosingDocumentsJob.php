<?php

declare(strict_types=1);

namespace App\Jobs\Edo;

use App\DTO\UserDto;
use App\Enums\OrganizationKind;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\ClosingDocumentService;
use App\Services\Edo\EdoClosingDocumentsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Закрывающие документы ЗР (этап 7 «Выдан. Подготовка документов»):
 * 1) формирует ЗН, УПД, Счёт СТО → УК и УК → Заказчик и отчёт агента —
 *    документы ЗР с PDF, которые видны и скачиваются в карточке ЗР;
 * 2) отправляет ЗН, Счёт и УПД оператору ЭДО (СБИС).
 */
final class GenerateClosingDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $requestId,
        public readonly ?int $userId = null,
        public readonly bool $validateOnly = false,
    ) {}

    public function handle(ClosingDocumentService $documents, EdoClosingDocumentsService $edo): void
    {
        $logger = Log::channel('edo');
        $logger->info('Generate Closing Documents', [
            'request id' => $this->requestId,
            'user id' => $this->userId,
            'validate only' => $this->validateOnly
        ]);
        $request = RepairRequest::query()->find($this->requestId);
        $user = $this->actor($request);
        $userDto = UserDto::fromUserModel($user);

        if ($request === null) {
            $logger->warning("Закрывающие документы: ЗР #{$this->requestId} не найдена");

            return;
        }

        if ($request->stage !== RepairStage::Issued) {
            // ЗР уже ушла с этапа 7 — документы не переформировываем.
            $logger->info("Закрывающие документы: ЗР {$request->number} на этапе {$request->stage->value}, пропуск");

            return;
        }

        $logger->info("Закрывающие документы: старт для ЗР {$request->number}");
        $documents->saveGenerationState($request, 'generating');

        try {
            DB::transaction(function () use ($documents, $request, $userDto): void {
                $documents->generateClosingDocuments($request, $userDto);
            });
        } catch (Throwable $e) {
            $logger->error("Закрывающие документы: ошибка формирования ЗР {$request->number}", ['message' => $e->getMessage()]);
            $documents->saveGenerationState($request, 'error', $e->getMessage());

            throw $e;
        }

        $result = $edo->send($request, $this->validateOnly);
        $errors = array_filter($result, static fn (string $value): bool => str_starts_with($value, 'error'));

        $documents->saveGenerationState(
            $request,
            $errors === [] ? 'done' : 'edo_error',
            $errors === [] ? null : implode('; ', array_map(
                static fn (string $number, string $value): string => $number . ' — ' . mb_substr($value, 7),
                array_keys($errors),
                $errors,
            )),
        );

        $logger->info("Закрывающие документы: ЗР {$request->number} готово", $result);
    }

    public function failed(Throwable $e): void
    {
        $request = RepairRequest::query()->find($this->requestId);
        if ($request !== null) {
            app(ClosingDocumentService::class)->saveGenerationState($request, 'error', $e->getMessage());
        }
    }

    /** Пользователь, от имени которого сохраняются документы: инициатор или любой пользователь УК. */
    private function actor(RepairRequest $request): User
    {
        $user = $this->userId !== null ? User::query()->find($this->userId) : null;

        if ($user !== null) {
            return $user;
        }

        $managerOrg = Organization::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('kind', OrganizationKind::Manager->value)
            ->value('id');

        return User::query()
            ->where('organization_id', $managerOrg)
            ->where('role', UserRole::Manager->value)
            ->orderBy('id')
            ->first()
            ?? User::query()->findOrFail($request->created_by);
    }
}
