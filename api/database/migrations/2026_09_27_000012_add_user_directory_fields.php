<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('last_name', 100)->nullable()->after('display_name');
            $table->string('first_name', 100)->nullable()->after('last_name');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('email')->nullable()->after('middle_name');
            $table->char('phone', 12)->nullable()->after('email');
            $table->timestampTz('registered_at')->nullable()->after('active');
            $table->timestampTz('access_revoked_at')->nullable()->after('registered_at');
            $table->jsonb('access_rights')
                ->default(DB::raw("'[]'::jsonb"))
                ->after('access_revoked_at');

            $table->index(['organization_id', 'active', 'access_revoked_at']);
            $table->index('email');
        });

        DB::statement(
            'UPDATE users SET registered_at = COALESCE(created_at, CURRENT_TIMESTAMP) '
            . 'WHERE registered_at IS NULL',
        );
        DB::statement(
            "UPDATE users SET access_rights = CASE role "
            . "WHEN 'manager' THEN "
            . "'[\"requests.view\",\"requests.manage\","
            . "\"catalogs.manage\",\"users.manage\"]'::jsonb "
            . "WHEN 'station' THEN '[\"requests.view\",\"requests.station\"]'::jsonb "
            . "ELSE '[\"requests.view\",\"requests.create\","
            . "\"catalogs.vehicles\",\"catalogs.people\"]'::jsonb END "
            . "WHERE access_rights = '[]'::jsonb",
        );

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_phone_check "
            . "CHECK (phone IS NULL OR phone ~ '^\\+7[0-9]{10}$')",
        );
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_access_rights_array_check '
            . "CHECK (jsonb_typeof(access_rights) = 'array')",
        );

        $comments = [
            'users' => 'App\\Models\\User — пользователи, имеющие или ранее '
                . 'имевшие доступ к личным кабинетам',
            'users.organization_id' => 'Организация, к личному кабинету '
                . 'которой относится пользователь',
            'users.last_name' => 'Фамилия пользователя',
            'users.first_name' => 'Имя пользователя',
            'users.middle_name' => 'Отчество пользователя',
            'users.email' => 'Электронная почта пользователя',
            'users.phone' => 'Мобильный телефон РФ в формате +7XXXXXXXXXX',
            'users.registered_at' => 'Дата первичной регистрации пользователя',
            'users.access_revoked_at' => 'Дата отзыва доступа; любое непустое '
                . 'значение блокирует вход',
            'users.access_rights' => 'Перечень прав; базовая роль определяется '
                . 'видом организации',
        ];

        foreach ($comments as $target => $description) {
            $subject = $target === 'users' ? 'TABLE' : 'COLUMN';
            DB::statement(
                "COMMENT ON {$subject} {$target} IS "
                . DB::getPdo()->quote($description),
            );
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_phone_check');
        DB::statement(
            'ALTER TABLE users DROP CONSTRAINT IF EXISTS users_access_rights_array_check',
        );

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'active', 'access_revoked_at']);
            $table->dropIndex(['email']);
            $table->dropColumn([
                'last_name',
                'first_name',
                'middle_name',
                'email',
                'phone',
                'registered_at',
                'access_revoked_at',
                'access_rights',
            ]);
        });
    }
};
