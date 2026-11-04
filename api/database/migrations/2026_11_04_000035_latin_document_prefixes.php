<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Латинские префиксы номеров документов (решение от 04.11.2026):
 * ZR — заявка, ZN — заказ-наряд, SCH — счёт, UPD — УПД, AO — агентский отчёт,
 * DV — доверенность, APP — акт приёма-передачи ТС, AVR — акт выполненных работ,
 * AD — агентский договор с Заказчиком. Цифры — 6-значный номер ЗР
 * (у агентского договора — номер Заказчика).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE repair_requests DROP COLUMN number');
        DB::statement("ALTER TABLE repair_requests ADD COLUMN number varchar(30) GENERATED ALWAYS AS ('ZR-' || lpad(id::text, greatest(6,length(id::text)), '0')) STORED UNIQUE");

        // Заказ-наряд один на ЗР и получает её номер.
        DB::statement('ALTER TABLE work_orders DROP COLUMN number');
        DB::statement("ALTER TABLE work_orders ADD COLUMN number varchar(30) GENERATED ALWAYS AS ('ZN-' || lpad(repair_request_id::text, greatest(6,length(repair_request_id::text)), '0')) STORED UNIQUE");

        DB::statement("UPDATE invoices SET number = 'SCH-' || lpad(repair_request_id::text, 6, '0')");
        DB::statement("UPDATE vehicle_acceptance_acts SET number = 'APP-' || lpad(repair_request_id::text, 6, '0')");

        $request = 'App\\Models\\RepairRequest';
        foreach ([
            '^POA-' => 'DV',
            '^REP-' => 'AO',
            '^(ACT|АКТ)-' => 'AVR',
            '^INV-' => 'SCH',
            '^УПД-' => 'UPD',
            '^УКД-' => 'UKD',
            '^ДОК-' => 'DOC',
        ] as $pattern => $prefix) {
            DB::update(
                "UPDATE edo_documents SET number = ? || '-' || lpad(source_id::text, 6, '0') WHERE source_type = ? AND number ~ ?",
                [$prefix, $request, $pattern],
            );
        }
        DB::update(
            "UPDATE edo_documents SET number = regexp_replace(number, 'RP-', 'ZR-') WHERE source_type = ? AND number ~ 'RP-'",
            [$request],
        );
        DB::update(
            "UPDATE edo_documents SET number = 'AD-' || lpad(source_id::text, 6, '0') WHERE number ~ '^AG-' AND source_type = ?",
            ['App\\Models\\Organization'],
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE repair_requests DROP COLUMN number');
        DB::statement("ALTER TABLE repair_requests ADD COLUMN number varchar(30) GENERATED ALWAYS AS ('RP-' || lpad(id::text, greatest(6,length(id::text)), '0')) STORED UNIQUE");
        DB::statement('ALTER TABLE work_orders DROP COLUMN number');
        DB::statement("ALTER TABLE work_orders ADD COLUMN number varchar(30) GENERATED ALWAYS AS ('ZN-' || lpad(id::text, greatest(6,length(id::text)), '0')) STORED UNIQUE");
    }
};
