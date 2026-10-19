<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE repair_requests DROP CONSTRAINT IF EXISTS request_json');
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE repair_requests
            ADD CONSTRAINT request_json
            CHECK (
                jsonb_typeof(data)    = 'object' AND
                jsonb_typeof(works)   = 'array'  AND
                jsonb_typeof(history) = 'array'
            )
        ");
    }
};
