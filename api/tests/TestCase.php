<?php
declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Миграции прогоняются один раз за весь тест-ран. */
    private static bool $migrated = false;

    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        /** @var Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        $this->assertSafeTestDatabase($app);

        if (!self::$migrated) {
            $this->createTestSchemaIfNeeded($app);
            $this->runMigrations($kernel);
            self::$migrated = true;
        }

        return $app;
    }

    private function createTestSchemaIfNeeded(Application $app): void
    {
        $db = $app['config']['database.connections.pgsql'];
        $schema = $db['search_path'];
        $connection = $app['db']->connection();

        $exists = $connection->selectOne(
            "SELECT 1 FROM information_schema.schemata WHERE schema_name = ?",
            [$schema],
        );

        if ($exists === null) {
            $connection->statement("CREATE SCHEMA {$schema}");
        }
    }

    /**
     * Тесты идут только на отдельной *_test БД с *_test search_path
     * и без DB_URL. Это защищает от случайного запуска на продовой базе.
     */
    private function assertSafeTestDatabase(Application $app): void
    {
        $db = $app['config']['database.connections.pgsql'];

        if (! $app->environment('testing')) {
            throw new RuntimeException('APP_ENV must be testing.');
        }

        if (! str_ends_with((string) $db['database'], '_test')) {
            throw new RuntimeException(
                'DB_DATABASE must end with _test, got: ' . $db['database'],
            );
        }

        if (! preg_match('/^[a-z][a-z0-9_]*_test$/', (string) $db['search_path'])) {
            throw new RuntimeException(
                'DB_SCHEMA must match *_test, got: ' . $db['search_path'],
            );
        }

        if (! empty($db['url'])) {
            throw new RuntimeException('DB_URL must not be set for tests.');
        }

        $version = (int) $app['db']
        ->selectOne("SELECT current_setting('server_version_num') AS version")
        ->version;

        if ($version < 150000) {
            throw new RuntimeException(
                'PostgreSQL 15+ required, got: ' . $version,
            );
        }
    }

    private function runMigrations(Kernel $kernel): void
    {
        if ($kernel->call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException(
                "Migrations failed:\n" . $kernel->output(),
            );
        }
    }

    /**
     * Отправляет POST с JSON-объектом ({}) в теле.
     *
     * postJson([], ...) сериализует в "[]" (массив), а EnsureJsonBody
     * требует именно JSON-объект — иначе 422 VALIDATION_ERROR.
     *
     * @param array<string, mixed> $body
     */
    protected function postJsonObject(string $uri, array $body = []): TestResponse
    {
        return $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ],
            json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    protected function postJsonObjectAs(string $uri, ?string $token = null, array $body = []): TestResponse
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];

        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        return $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            $headers,
            json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Формирует HTTP-ответ в кодировке ЭДО.Поток (Windows-1251).
     *
     * Возвращаемый тип — PromiseInterface, потому что Http::response()
     * возвращает именно его. Тип Illuminate\Http\Client\Response здесь
     * невозможен (это уже результат выполнения запроса, не «заготовка»).
     *
     * @param  array<string, mixed>  $payload
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function edoRuResponse(array $payload, int $status = 200): \GuzzleHttp\Promise\PromiseInterface
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return \Illuminate\Support\Facades\Http::response(
            mb_convert_encoding($json, 'windows-1251', 'UTF-8'),
            $status,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $edoProvider = env('EDO_PROVIDER', 'sberkorus');

        // Принудительно применяем конфиг
        config(['edo.default' => $edoProvider]);

        // Подставляем фейковые ключи, чтобы провайдеры не падали на инициализации
        if ($edoProvider === 'sberkorus') {
            config([
                'edo.providers.sberkorus.username' => 'test-user',
                'edo.providers.sberkorus.password' => 'test-password',
                'edo.providers.sberkorus.api_key'  => 'test-api-key',
            ]);
        } elseif ($edoProvider === 'edoru') {
            config([
                'edo.providers.edoru.client_id' => 'test-client',
                'edo.providers.edoru.password'  => 'test-password',
            ]);
        }
    }
}