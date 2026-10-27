<?php

declare(strict_types=1);

namespace App\Console\Commands\Edo;

use App\Enums\OrganizationKind;
use App\Exceptions\ApiException;
use App\Exceptions\EdoException;
use App\Models\Organization;
use App\Services\Edo\EdoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RegisterEdoOrganizationCommand extends Command
{
    protected $signature = 'edo:register-organization
                            {organization_id : ID организации в MSTO}
                            {--force    : Перезаписать, если уже зарегистрирована}
                            {--dry-run  : Только показать, что будет записано}';

    protected $description = 'Находит организацию в ЭДО оператора и сохраняет edo_client_id / edo_edi_code';

    public function handle(EdoService $edo): int
    {
        $id = (int) $this->argument('organization_id');

        $organization = Organization::query()->find($id);
        if (!$organization instanceof Organization) {
            $this->error(sprintf('Организация #%d не найдена', $id));

            return self::FAILURE;
        }

        $kind = $organization->kind instanceof OrganizationKind
            ? $organization->kind->value
            : (string) $organization->kind;

        $this->line(sprintf(
            'Организация: #%d %s (kind=%s, ИНН=%s, КПП=%s)',
            $organization->id,
            $organization->short_name !== '' ? $organization->short_name : $organization->name,
            $kind,
            $organization->inn !== '' ? $organization->inn : '—',
            $organization->kpp !== '' ? $organization->kpp : '—',
        ));

        if ($organization->isRegisteredInEdo() && !$this->option('force')) {
            $this->warn(sprintf(
                'Уже зарегистрирована: client_id=%s, edi_code=%s (используйте --force для перезаписи)',
                $organization->edo_client_id,
                $organization->edo_edi_code,
            ));

            return self::SUCCESS;
        }

        try {
            $dto = $edo->resolveOrganization($organization);
        } catch (EdoException $e) {
            $this->error(sprintf('Ошибка оператора ЭДО: %s', $e->getMessage()));
            Log::warning('edo:register-organization operator error', [
                'organization_id' => $organization->id,
                'exception'       => $e,
            ]);

            return self::FAILURE;
        } catch (ApiException $e) {
            $this->error(sprintf('Ошибка резолва: [%s] %s', $e->errorCode, $e->getMessage()));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error(sprintf('Неожиданная ошибка: %s', $e->getMessage()));
            Log::error('edo:register-organization failed', [
                'organization_id' => $organization->id,
                'exception'       => $e,
            ]);

            return self::FAILURE;
        }

        $this->table(
            ['Поле', 'Значение'],
            [
                ['Наименование',  $dto->name],
                ['ИНН',           $dto->inn],
                ['КПП',           $dto->kpp ?? '—'],
                ['edo_client_id', $dto->id],
                ['edo_edi_code',  $dto->ediCode ?? '—'],
                ['operator_code', $dto->operatorCode ?? '—'],
            ],
        );

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: в БД ничего не записано');

            return self::SUCCESS;
        }

        try {
            $edo->registerOrganization($organization, force: (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error(sprintf('Ошибка записи: %s', $e->getMessage()));
            Log::error('edo:register-organization save failed', [
                'organization_id' => $organization->id,
                'exception'       => $e,
            ]);

            return self::FAILURE;
        }

        $this->info(sprintf('OK. Организация #%d зарегистрирована', $organization->id));

        return self::SUCCESS;
    }
}
