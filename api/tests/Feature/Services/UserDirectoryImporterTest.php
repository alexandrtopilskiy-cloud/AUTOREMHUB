<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserDirectoryImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Feature-тест для UserDirectoryImporter.
 *
 * Покрывает:
 *   - dry-run: не пишет в БД
 *   - import: создание пользователей
 *   - автозаполнение ФИО и телефона при пустых полях
 *   - сопоставление организации по имени с разными кавычками
 *   - блокировку legacy-пользователей
 *   - перенос документов на первого пользователя организации
 *   - конфликт логинов (retireConflictingLogin)
 *   - валидация email, пароля, телефона, прав
 */
final class UserDirectoryImporterTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $managerOrg;
    private Organization $customerOrg;
    private Organization $stationOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-25 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->managerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'manager',
            'name'      => 'ООО "УК"',
        ]);
        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО "Альфа"',
        ]);
        $this->stationOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'station',
            'name'      => 'СТО-партнёр',
        ]);
    }

    /* ─────────────────── dry-run ─────────────────── */

    public function test_dry_run_does_not_write_to_database(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['УК', 'ООО "УК"', 'Топильский', 'Александр', 'Викторович',
                '89602417387', 'top@mail.ru', 'UK', '123456789123456789',
                '2026-09-27', '', 'да', 'UK'],
        ]);

        try {
            $result = app(UserDirectoryImporter::class)->import(
                $this->tenant,
                $path,
                true,
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, User::query()->count());
    }

    /* ─────────────────── создание ─────────────────── */

    public function test_import_creates_users_with_valid_password(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['УК', 'ООО "УК"', 'Топильский', 'Александр', 'Викторович',
                '89602417387', 'top@mail.ru', 'UK', 'manager-password-long',
                '2026-09-27', '', 'да', 'UK'],
            ['Закачик', 'ООО "Альфа"', 'Иванов', 'Иван', 'Иванович',
                '89991112233', 'ivan@mail.ru', 'Zakaz11', 'customer-password-long',
                '2026-09-27', '', 'да', 'Zakaz'],
        ]);

        try {
            $result = app(UserDirectoryImporter::class)->import(
                $this->tenant,
                $path,
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['created']);

        $manager = User::query()->where('login', 'UK')->firstOrFail();
        $this->assertSame($this->managerOrg->id, $manager->organization_id);
        $this->assertSame(UserRole::Manager, $manager->role);
        $this->assertSame('Топильский', $manager->last_name);
        $this->assertSame('+79602417387', $manager->phone);
        $this->assertTrue(Hash::check('manager-password-long', $manager->password));

        $customer = User::query()->where('login', 'Zakaz11')->firstOrFail();
        $this->assertSame(UserRole::Customer, $customer->role);
        $this->assertContains('requests.create', $customer->access_rights);
    }

    public function test_import_fills_missing_personal_data(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            // Пустые ФИО и телефон → автозаполнение
            ['Закачик', 'ООО "Альфа"', '', '', '',
                '', '3@mail.ru', 'Zakaz11', '123456789123456789',
                '2026-09-27', '', 'да', 'Zakaz'],
        ]);

        try {
            $result = app(UserDirectoryImporter::class)->import(
                $this->tenant,
                $path,
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $result['randomized']);

        $user = User::query()->where('login', 'Zakaz11')->firstOrFail();
        $this->assertNotEmpty($user->last_name);
        $this->assertNotEmpty($user->first_name);
        $this->assertNotEmpty($user->middle_name);
        $this->assertMatchesRegularExpression('/^\+7[0-9]{10}$/', $user->phone);
    }

    public function test_import_matches_organization_with_different_quotes(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            // В XLSX — «ёлочки», в БД — обычные кавычки
            ['Закачик', 'ООО «Альфа»', 'Иванов', 'Иван', 'Иванович',
                '89991112233', 'ivan@mail.ru', 'Zakaz11', 'customer-password-long',
                '2026-09-27', '', 'да', 'Zakaz'],
        ]);

        try {
            app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }

        $user = User::query()->where('login', 'Zakaz11')->firstOrFail();
        $this->assertSame($this->customerOrg->id, $user->organization_id);
    }

    /* ─────────────────── блокировка legacy ─────────────────── */

    public function test_import_revokes_legacy_users_not_in_xlsx(): void
    {
        $legacy = $this->makeUser($this->customerOrg, 'UK1', 'customer');

        $path = $this->xlsx([
            $this->headerRow(),
            ['Закачик', 'ООО "Альфа"', 'Новый', 'Пользователь', '',
                '89991112233', 'new@mail.ru', 'ZakazNew', 'customer-password-long',
                '2026-09-27', '', 'да', 'Zakaz'],
        ]);

        try {
            $result = app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $result['revoked']);

        $legacy->refresh();
        $this->assertFalse($legacy->active);
        $this->assertNotNull($legacy->access_revoked_at);
        $this->assertStringStartsWith('UK1', $legacy->login);
    }

    public function test_import_reassigns_documents_from_legacy_users(): void
    {
        $legacy = $this->makeUser($this->customerOrg, 'UK1', 'customer');

        // Создаём заявку от legacy
        $request = RepairRequest::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'public_id'                => (string) Str::uuid(),
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'created_by'               => $legacy->id,
            'data'                     => ['description' => ''],
            'works'                    => [],
            'history'                  => [],
            'stage'                    => 0,
            'revision'                 => 0,
        ]);

        $path = $this->xlsx([
            $this->headerRow(),
            ['Закачик', 'ООО "Альфа"', 'Новый', 'Пользователь', '',
                '89991112233', 'new@mail.ru', 'ZakazNew', 'customer-password-long',
                '2026-09-27', '', 'да', 'Zakaz'],
        ]);

        try {
            $result = app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $result['reassigned']);

        $newUser = User::query()->where('login', 'ZakazNew')->firstOrFail();
        $this->assertSame($newUser->id, $request->refresh()->created_by);
    }

    public function test_import_retires_conflicting_login_in_other_organization(): void
    {
        // Тот же логин, но в другой организации
        $existing = $this->makeUser($this->stationOrg, 'UK1', 'station');

        $path = $this->xlsx([
            $this->headerRow(),
            ['Закачик', 'ООО "Альфа"', 'Иванов', 'Иван', 'Иванович',
                '89991112233', 'ivan@mail.ru', 'UK1', 'customer-password-long',
                '2026-09-27', '', 'да', 'Zakaz'],
        ]);

        try {
            $result = app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $result['revoked']);

        $existing->refresh();
        $this->assertFalse($existing->active);
        $this->assertStringStartsWith('revoked-', $existing->login);

        // Новый пользователь — в customerOrg
        $newUser = User::query()->where('login', 'UK1')->firstOrFail();
        $this->assertSame($this->customerOrg->id, $newUser->organization_id);
    }

    /* ─────────────────── валидация ─────────────────── */

    public function test_import_rejects_duplicate_logins_in_xlsx(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['УК', 'ООО "УК"', 'A', 'B', 'C', '89991112233',
                'a@mail.ru', 'DUP', 'password-long-1', '2026-09-27', '', 'да', 'UK'],
            ['УК', 'ООО "УК"', 'D', 'E', 'F', '89991112244',
                'b@mail.ru', 'DUP', 'password-long-2', '2026-09-27', '', 'да', 'UK'],
        ]);

        try {
            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('логины не должны повторяться');

            app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_rejects_unknown_cabinet_type(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['Unknown', 'ООО "УК"', 'A', 'B', 'C', '89991112233',
                'a@mail.ru', 'UK', 'password-long-1', '2026-09-27', '', 'да', 'UK'],
        ]);

        try {
            $this->expectException(ApiException::class);

            app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_rejects_invalid_email(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['УК', 'ООО "УК"', 'A', 'B', 'C', '89991112233',
                'not-email', 'UK', 'password-long-1', '2026-09-27', '', 'да', 'UK'],
        ]);

        try {
            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('некорректная электронная почта');

            app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_rejects_short_password(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['УК', 'ООО "УК"', 'A', 'B', 'C', '89991112233',
                'a@mail.ru', 'UK', 'short', '2026-09-27', '', 'да', 'UK'],
        ]);

        try {
            $this->expectException(ApiException::class);

            app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_rejects_invalid_phone(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['УК', 'ООО "УК"', 'A', 'B', 'C', '123',
                'a@mail.ru', 'UK', 'password-long-1', '2026-09-27', '', 'да', 'UK'],
        ]);

        try {
            $this->expectException(ApiException::class);

            app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_rejects_unknown_access_right(): void
    {
        $path = $this->xlsx([
            $this->headerRow(),
            ['УК', 'ООО "УК"', 'A', 'B', 'C', '89991112233',
                'a@mail.ru', 'UK', 'password-long-1', '2026-09-27', '', 'да',
                'unknown.right'],
        ]);

        try {
            $this->expectException(ApiException::class);

            app(UserDirectoryImporter::class)->import($this->tenant, $path);
        } finally {
            @unlink($path);
        }
    }

    /* ─────────────────── helpers ─────────────────── */

    /**
     * @return array<int, string>
     */
    private function headerRow(): array
    {
        return [
            'ЛК', 'Организация', 'Фамилия', 'Имя', 'Отчество',
            'Мобильный телефон', 'Электронная почта', 'Логин', 'Пароль',
            'Дата первичной регистрации', 'Дата отзыва доступа',
            'Активен', 'Права доступа',
        ];
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'users-dir-');

        if ($path === false) {
            self::fail('Не удалось создать временный XLSX');
        }

        $xmlRows = '';

        foreach ($rows as $rowIndex => $row) {
            $cells = '';

            foreach ($row as $columnIndex => $value) {
                $column = chr(65 + $columnIndex);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<c r="' . $column . ($rowIndex + 1) . '" t="inlineStr">'
                . '<is><t>' . $escaped . '</t></is></c>';
            }

            $xmlRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $xmlRows . '</sheetData></worksheet>',
        );
        $zip->close();

        return $path;
    }

    private function makeUser(
        Organization $organization,
        string $login,
        string $role,
    ): User {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $login,
            'display_name'    => $login,
            'role'            => $role,
            'password'        => 'test-password-long',
            'active'          => true,
            'last_name'       => 'Старый',
            'first_name'      => 'Пользователь',
            'email'           => $login . '@old.test',
            'phone'           => '+79990000000',
            'access_rights'   => ['requests.view'],
        ]);
    }
}
