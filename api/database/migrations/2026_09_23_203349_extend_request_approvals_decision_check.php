<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE request_approvals '
            . 'DROP CONSTRAINT IF EXISTS request_approvals_decision_check'
        );

        DB::statement(
            "ALTER TABLE request_approvals "
            . "ADD CONSTRAINT request_approvals_decision_check "
            . "CHECK (decision IN ("
            . "'approved', "
            . "'returned', "
            . "'confirmed', "
            . "'rescheduled', "
            . "'declined', "
            . "'accepted'"
            . "))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE request_approvals '
            . 'DROP CONSTRAINT IF EXISTS request_approvals_decision_check'
        );

        DB::statement(
            "ALTER TABLE request_approvals "
            . "ADD CONSTRAINT request_approvals_decision_check "
            . "CHECK (decision IN ("
            . "'approved', "
            . "'returned', "
            . "'confirmed', "
            . "'rescheduled', "
            . "'declined'"
            . "))"
        );
    }
};
