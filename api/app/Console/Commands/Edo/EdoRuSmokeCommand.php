<?php

declare(strict_types=1);

namespace App\Console\Commands\Edo;

use App\Exceptions\EdoException;
use App\Services\Edo\Providers\EdoRu\EdoRuClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Smoke-проверка API ЭДО.ру (ЭДО.Поток).
 *
 * Не трогает БД, не создаёт документы. Только безопасные GET/POST:
 *  - auth        — POST /clients/auth-global
 *  - find-self   — GET  /contractors/search-contractors?query=<ИНН>
 *  - find-cp     — GET  /contractors/search-contractors?query=<ИНН>
 *  - raw-find    — GET  /contractors/search-contractors?query=<пусто> (все контрагенты)
 *  - doc-types   — GET  /dictionary/get-document-type (справочник типов документов)
 *  - whoami      — auth + find-self + вывод edo_edi_code нашей организации
 *  - pull-events — GET  /documents/find?direction=in
 *  - all         — auth + whoami + pull-events
 *
 * Запуск:
 *   php artisan edo:edoru-smoke --step=auth
 *   php artisan edo:edoru-smoke --step=whoami --inn=<ваш_ИНН>
 *   php artisan edo:edoru-smoke --step=find-cp --inn=<ИНН_контрагента>
 *   php artisan edo:edoru-smoke --step=raw-find
 *   php artisan edo:edoru-smoke --step=doc-types
 *   php artisan edo:edoru-smoke --step=pull-events
 *   php artisan edo:edoru-smoke --step=all --inn=<ваш_ИНН>
 *
 * См. docs/edo/edoru.md.
 */
final class EdoRuSmokeCommand extends Command
{
    protected $signature = 'edo:edoru-smoke
                            {--step=auth : auth | find-self | find-cp | raw-find | doc-types | whoami | pull-events | all}
                            {--inn= : ИНН для поиска (find-self / find-cp / whoami)}
                            {--clear-token : Очистить кэш токена перед запуском}';

    protected $description = 'Smoke-проверка API ЭДО.ру (ЭДО.Поток) без создания документов';

    public function handle(EdoRuClient $client): int
    {
        $this->printConfig();

        if ($this->option('clear-token')) {
            Cache::flush();
            $this->warn('Кэш токена очищен (Cache::flush).');
        }

        $step = (string) $this->option('step');

        try {
            match ($step) {
                'auth'        => $this->stepAuth($client),
                'find-self',
                'find-cp'     => $this->stepFindOrganization($client, $step),
                'raw-find'    => $this->stepRawFind($client),
                'doc-types'   => $this->stepDocumentTypes($client),
                'whoami'      => $this->stepWhoAmI($client),
                'pull-events' => $this->stepPullEvents($client),
                'all'         => $this->stepAll($client),
                default       => throw new \RuntimeException("Неизвестный --step={$step}"),
            };
        } catch (EdoException $e) {
            $this->newLine();
            $this->error(sprintf('EdoException: %s', $e->getMessage()));
            $this->line(sprintf('errorCode: %s', $e->errorCode ?? '—'));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error(sprintf('[%s] %s', get_class($e), $e->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function printConfig(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('edo.providers.edoru', []);

        $clientId = (string) ($config['client_id'] ?? '');
        $password = (string) ($config['password'] ?? '');

        $this->line('─── ЭДО.ру smoke ───────────────────────────────────');
        $this->line('base_url:  ' . (string) ($config['base_url'] ?? '—'));
        $this->line('auth_mode: ' . (string) ($config['auth_mode'] ?? '—'));
        $this->line('client_id: ' . $this->mask($clientId, 4, 2));
        $this->line('password:  ' . ($password === '' ? '(пусто)' : '(задан, ' . strlen($password) . ' символов)'));
        $this->line('default:   ' . (string) config('edo.default', '—'));
        $this->line('────────────────────────────────────────────────────');
        $this->newLine();
    }

    private function stepAuth(EdoRuClient $client): void
    {
        $this->info('→ POST /clients/auth-global');
        $start = microtime(true);

        $result = $client->authGlobal();
        $elapsed = round((microtime(true) - $start) * 1000, 1);

        $this->newLine();
        $this->info("✅ Токен получен за {$elapsed} мс");
        $this->line('   token: ' . $this->mask($result['token'], 8, 4));
        $this->line('   длина: ' . strlen($result['token']) . ' символов');
        $this->line('   edoOrgId: ' . ($result['edoOrgId'] ?? '—'));
    }

    private function stepFindOrganization(EdoRuClient $client, string $step): void
    {
        $inn = (string) $this->option('inn');

        if ($inn === '') {
            $this->error("Для --step={$step} обязателен --inn=<ИНН>");

            return;
        }

        $this->info("→ GET /contractors/search-contractors?query={$inn}");

        $payload = $client->get('/contractors/search-contractors', ['query' => $inn]);

        $rows = (array) ($payload['result']['data'] ?? []);

        $this->printContractorRows($rows, $inn);
    }

    /**
     * raw-find: показать всех доступных контрагентов (query пустой).
     * Полезно для диагностики — увидеть, с кем вообще возможен обмен.
     */
    private function stepRawFind(EdoRuClient $client): void
    {
        $this->info('→ GET /contractors/search-contractors?query= (все)');

        $payload = $client->get('/contractors/search-contractors', ['query' => '']);

        $rows = (array) ($payload['result']['data'] ?? []);

        $this->printContractorRows($rows, '');
    }

    /**
     * doc-types: справочник типов документов ЭДО.ру.
     * Нужен для сверки нашего маппинга docType (EdoRuMapper).
     */
    private function stepDocumentTypes(EdoRuClient $client): void
    {
        $this->info('→ GET /dictionary/get-document-type');

        $payload = $client->get('/dictionary/get-document-type');

        $rows = (array) ($payload['result'] ?? []);

        $this->newLine();
        $this->info(sprintf('✅ Получено типов документов: %d', count($rows)));
        $this->newLine();

        $this->table(
            ['id', 'name', 'formalized', 'technical', 'primary', 'pokTitle'],
            array_map(
                static fn (array $row): array => [
                    (string) ($row['id'] ?? '—'),
                    (string) ($row['name'] ?? '—'),
                    ($row['formalized'] ?? false) ? 'true' : 'false',
                    ($row['technical'] ?? false) ? 'true' : 'false',
                    ($row['primary'] ?? false) ? 'true' : 'false',
                    ($row['pokTitle'] ?? false) ? 'true' : 'false',
                ],
                array_filter($rows, 'is_array'),
            ),
        );

        $this->newLine();
        $this->comment('Сверь с маппингом в EdoRuMapper::DOC_TYPE_MAP:');
        $this->line('  UpdSf→1, UpdSfDop→8, UpdDop→8, UcdCsf→12, Invoice→1, UpdBuyerTitle→11, UcdBuyerTitle→13');
    }

    /**
     * whoami: auth + find-self → показать edo_edi_code (ИдУчЭДО) нашей организации.
     * Это ключевое поле для @ИдФайл в XML УПД и для field `to` в /documents/send.
     */
    private function stepWhoAmI(EdoRuClient $client): void
    {
        $inn = (string) $this->option('inn');

        if ($inn === '') {
            $this->error('Для --step=whoami обязателен --inn=<ваш_ИНН>');

            return;
        }

        $this->stepAuth($client);
        $this->newLine();

        $this->info("→ GET /contractors/search-contractors?query={$inn} (поиск себя)");

        $payload = $client->get('/contractors/search-contractors', ['query' => $inn]);
        $rows = (array) ($payload['result']['data'] ?? []);

        $this->newLine();
        $this->info(sprintf('✅ Найдено записей по ИНН %s: %d', $inn, count($rows)));
        $this->newLine();

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->line(sprintf('#%d  %s', $i + 1, (string) ($row['fullName'] ?? '—')));
            $this->line('    clientId (→ edo_client_id): ' . (string) ($row['clientId'] ?? '—'));
            $this->line('    edo_edi_code (→ ИдУчЭДО):   ' . (string) ($row['clientId'] ?? '—'));
            $this->line('    inn / kpp:                  ' . (string) ($row['inn'] ?? '—') . ' / ' . (string) ($row['kpp'] ?? '—'));
            $this->line('    ogrn:                       ' . (string) ($row['ogrn'] ?? '—'));
            $this->line('    status:                     ' . (string) ($row['status'] ?? '—'));
            $this->line('    operatorMnemo:              ' . (string) ($row['operatorMnemo'] ?? '—'));
            $this->newLine();
        }

        if ($rows === []) {
            $this->warn('Себя не нашли. Возможные причины:');
            $this->line('  • организация ещё не завершила регистрацию в ЭДО.Поток;');
            $this->line('  • ИНН неверный;');
            $this->line('  • поиск чувствителен к формату.');
        }
    }

    private function stepPullEvents(EdoRuClient $client): void
    {
        $this->info('→ GET /documents/find (direction=in, page 1, 20 записей)');

        $payload = $client->get('/documents/find', [
            'direction'     => 'in',
            'typeOfDate'    => 'STATUS_CHANGE_DATE',
            'pageIndex'     => 1,
            'pageRecords'   => 20,
            'pageCount'     => 1,
            'sortKey'       => 'doc_id',
            'sortDirection' => 'desc',
        ]);

        $data = (array) ($payload['result']['data'] ?? []);
        $pageInfo = (array) ($payload['result']['pageInfo'] ?? []);

        $this->newLine();
        $this->info(sprintf('✅ Получено документов: %d', count($data)));
        $this->line('   pageInfo: ' . json_encode($pageInfo, JSON_UNESCAPED_UNICODE));
        $this->newLine();

        foreach ($data as $i => $doc) {
            if (! is_array($doc)) {
                continue;
            }

            $this->line(sprintf(
                '#%d  docId=%s  docType=%s  state=%s  from=%s',
                $i + 1,
                (string) ($doc['docId'] ?? '—'),
                (string) ($doc['docTypeName'] ?? '—'),
                (string) ($doc['docStateName'] ?? '—'),
                (string) ($doc['fromOrgName'] ?? '—'),
            ));
        }

        if ($data === []) {
            $this->warn('Входящих документов нет — это нормально для свежего ЛК.');
        }
    }

    private function stepAll(EdoRuClient $client): void
    {
        $inn = (string) $this->option('inn');

        $this->stepAuth($client);
        $this->newLine();

        if ($inn !== '') {
            $this->stepWhoAmI($client);
            $this->newLine();
        } else {
            $this->warn('--inn не задан, шаг whoami пропущен.');
            $this->newLine();
        }

        $this->stepDocumentTypes($client);
        $this->newLine();

        $this->stepPullEvents($client);
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function printContractorRows(array $rows, string $innFilter): void
    {
        $this->newLine();
        $this->info(sprintf('✅ Найдено контрагентов: %d', count($rows)));
        $this->newLine();

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->line(sprintf('#%d  %s', $i + 1, (string) ($row['fullName'] ?? '—')));
            $this->line('    clientId:       ' . (string) ($row['clientId'] ?? '—'));
            $this->line('    inn:            ' . (string) ($row['inn'] ?? '—'));
            $this->line('    kpp:            ' . (string) ($row['kpp'] ?? '—'));
            $this->line('    ogrn:           ' . (string) ($row['ogrn'] ?? '—'));
            $this->line('    status:         ' . (string) ($row['status'] ?? '—'));
            $this->line('    operatorMnemo:  ' . (string) ($row['operatorMnemo'] ?? '—'));
            $this->newLine();
        }

        if ($rows === [] && $innFilter !== '') {
            $this->warn('Ничего не найдено. Возможные причины:');
            $this->line('  • контрагент не зарегистрирован в ЭДО.Поток;');
            $this->line('  • не настроен роуминг с его оператором;');
            $this->line('  • ИНН неверный или поиск чувствителен к формату.');
        }
    }

    /**
     * Маскирует строку: показывает первые N и последние M символов.
     */
    private function mask(string $value, int $head = 4, int $tail = 2): string
    {
        $len = strlen($value);

        if ($len === 0) {
            return '(пусто)';
        }

        if ($len <= $head + $tail) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, $head)
            . str_repeat('*', max(1, $len - $head - $tail))
            . substr($value, -$tail);
    }
}
