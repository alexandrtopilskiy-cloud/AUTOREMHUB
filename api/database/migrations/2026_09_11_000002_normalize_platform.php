<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    private function entity(Blueprint $t): void { $t->id(); $t->uuid('uuid')->unique(); $t->timestampsTz(); }
    public function up(): void
    {
        foreach (['tenants', 'organizations', 'users', 'repair_requests'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->uuid('uuid')->nullable()->unique());
            DB::table($table)->orderBy('id')->each(function ($row) use ($table): void {
                DB::table($table)->where('id', $row->id)->update(['uuid' => isset($row->public_id) && Str::isUuid($row->public_id) ? $row->public_id : (string) Str::uuid()]);
            });
            DB::statement("ALTER TABLE {$table} ALTER COLUMN uuid SET NOT NULL");
        }
        Schema::create('vehicles', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('name'); $t->char('vin', 17)->unique(); $t->string('plate', 30)->default('');
            $t->string('plate_normalized', 30)->index(); $t->unsignedSmallInteger('year')->nullable();
            $t->unsignedBigInteger('baseline_service_mileage')->nullable(); $t->timestampTz('baseline_service_at')->nullable();
            $t->unique(['id', 'organization_id']); $t->unique(['id', 'tenant_id']);
            $t->foreign(['organization_id','tenant_id'])->references(['id','tenant_id'])->on('organizations')->restrictOnDelete();
        });
        Schema::create('stations', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $t->string('name'); $t->text('address')->default(''); $t->string('contact')->default('');
            $t->decimal('rating', 3, 2)->nullable(); $t->boolean('active')->default(true);
            $t->unique(['id', 'tenant_id']);
            $t->foreign(['organization_id','tenant_id'])->references(['id','tenant_id'])->on('organizations')->restrictOnDelete();
        });
        Schema::create('work_types', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name'); $t->boolean('is_maintenance')->default(false); $t->boolean('active')->default(true);
        });
        Schema::create('time_norms', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('work_type_id')->constrained()->restrictOnDelete();
            $t->string('vehicle_model')->default(''); $t->decimal('hours', 10, 3); $t->text('source')->default('');
        });
        Schema::create('service_reasons', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('service_reasons')->restrictOnDelete();
            $t->string('name'); $t->string('code', 100); $t->boolean('requires_diagnosis')->default(false);
            $t->boolean('is_maintenance')->default(false); $t->boolean('active')->default(true);
            $t->unique(['tenant_id','code']);
        });
        Schema::create('maintenance_regulations', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name'); $t->string('vehicle_model')->default('');
            $t->unsignedInteger('interval_km')->nullable(); $t->unsignedInteger('interval_months')->nullable();
            $t->text('source')->default(''); $t->boolean('active')->default(true);
        });
        Schema::create('maintenance_regulation_items', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('maintenance_regulation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('work_type_id')->constrained()->restrictOnDelete(); $t->foreignId('time_norm_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('quantity', 10, 3)->default(1);
        });
        Schema::create('suppliers', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name'); $t->string('contact')->default(''); $t->string('tax_id', 20)->default('');
            $t->boolean('active')->default(true);
        });
        Schema::table('repair_requests', function (Blueprint $t): void {
            $t->unsignedBigInteger('vehicle_id')->nullable();
            $t->foreign(['vehicle_id','customer_organization_id'])->references(['id','organization_id'])->on('vehicles')->restrictOnDelete();
            $t->unsignedBigInteger('assigned_station_id')->nullable();
            $t->foreign(['assigned_station_id','tenant_id'])->references(['id','tenant_id'])->on('stations')->restrictOnDelete();
            $t->foreignId('reason_id')->nullable()->constrained('service_reasons')->restrictOnDelete();
            $t->foreignId('reason_subtype_id')->nullable()->constrained('service_reasons')->restrictOnDelete();
            $t->unsignedBigInteger('previous_service_mileage')->nullable();
            $t->timestampTz('planned_completion_at')->nullable(); $t->timestampTz('repair_completed_at')->nullable();
            $t->unsignedInteger('estimate_version')->default(0);
        });
        DB::statement("ALTER TABLE repair_requests DROP CONSTRAINT request_stage");
        DB::statement("ALTER TABLE repair_requests ADD CONSTRAINT request_stage CHECK (stage BETWEEN 0 AND 13)");
        DB::statement("ALTER TABLE repair_requests ADD COLUMN number varchar(30) GENERATED ALWAYS AS ('RP-' || lpad(id::text, greatest(6,length(id::text)), '0')) STORED UNIQUE");
        Schema::create('request_events', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_label'); $t->string('action'); $t->text('comment')->default('');
            $t->smallInteger('stage'); $t->unsignedBigInteger('revision'); $t->jsonb('details')->nullable();
            $t->timestampTz('occurred_at')->index();
        });
        Schema::create('request_status_periods', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $t->smallInteger('stage'); $t->timestampTz('entered_at'); $t->timestampTz('exited_at')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX one_open_status_period ON request_status_periods (repair_request_id) WHERE exited_at IS NULL');
        Schema::create('attachments', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('request_status_period_id')->constrained()->restrictOnDelete();
            $t->foreignId('request_event_id')->nullable()->constrained()->nullOnDelete();
            $t->string('purpose', 40); $t->string('disk', 40); $t->string('object_key')->unique();
            $t->string('original_name'); $t->string('mime_type', 100); $t->unsignedBigInteger('size'); $t->char('sha256', 64);
        });
        Schema::create('diagnostic_reports', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('station_id')->constrained()->restrictOnDelete(); $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('mileage'); $t->text('conclusion'); $t->jsonb('attachment_ids');
        });
        Schema::create('request_works', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('work_type_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('time_norm_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name'); $t->decimal('quantity', 12, 3); $t->decimal('price', 14, 2);
            $t->decimal('norm_hours', 10, 3)->nullable(); $t->decimal('actual_hours', 10, 3)->nullable();
            $t->boolean('is_maintenance')->default(false);
        });
        Schema::create('request_parts', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name'); $t->string('article')->default(''); $t->decimal('quantity', 12, 3);
            $t->decimal('price', 14, 2); $t->decimal('returned_quantity', 12, 3)->default(0);
            $t->string('status', 30)->default('proposed'); $t->unsignedInteger('approved_version')->nullable();
        });
        Schema::create('part_movements', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('request_part_id')->constrained()->restrictOnDelete();
            $t->foreignId('replacement_part_id')->nullable()->constrained('request_parts')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 30); $t->decimal('quantity', 12, 3); $t->text('comment'); $t->jsonb('attachment_ids');
        });
        Schema::create('request_approvals', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('request_event_id')->constrained()->restrictOnDelete(); $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->smallInteger('stage'); $t->string('decision', 30); $t->text('comment');
            $t->unsignedInteger('estimate_version'); $t->jsonb('snapshot');
        });
        Schema::create('work_orders', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete(); $t->foreignId('station_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('mileage'); $t->timestampTz('completed_at'); $t->decimal('total', 16, 2);
            $t->text('conclusion'); $t->unsignedInteger('estimate_version');
        });
        DB::statement("ALTER TABLE work_orders ADD COLUMN number varchar(30) GENERATED ALWAYS AS ('ZN-' || lpad(id::text, greatest(6,length(id::text)), '0')) STORED UNIQUE");
        Schema::create('work_order_items', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20); $t->string('name'); $t->decimal('quantity', 12, 3); $t->decimal('price', 14, 2);
            $t->foreignId('work_type_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('norm_hours', 10, 3)->nullable(); $t->decimal('actual_hours', 10, 3)->nullable(); $t->boolean('is_maintenance')->default(false);
        });
        Schema::create('vehicle_service_records', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('repair_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('work_order_item_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('mileage'); $t->timestampTz('performed_at')->index(); $t->boolean('is_maintenance')->default(false);
        });
        Schema::create('invoices', function (Blueprint $t): void {
            $this->entity($t); $t->foreignId('repair_request_id')->unique()->constrained()->restrictOnDelete();
            $t->string('number'); $t->decimal('amount', 16, 2); $t->string('currency', 3)->default('RUB');
            $t->string('status', 30)->default('issued');
            $t->foreignId('document_attachment_id')->constrained('attachments')->restrictOnDelete();
            $t->foreignId('paid_document_attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $t->decimal('paid_amount', 16, 2)->nullable(); $t->timestampTz('paid_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestampTz('verified_at')->nullable();
        });
        foreach (['request_works','request_parts','work_order_items'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_money CHECK (quantity >= 0 AND price >= 0)");
        }
        DB::statement('ALTER TABLE request_parts ADD CONSTRAINT parts_returns CHECK (returned_quantity >= 0 AND returned_quantity <= quantity)');
        DB::statement('ALTER TABLE request_status_periods ADD CONSTRAINT period_time CHECK (exited_at IS NULL OR exited_at >= entered_at)');
        $this->migrateLegacy();
        $models = [
            'tenants'=>'App\\Models\\Tenant — контур управляющей компании', 'organizations'=>'App\\Models\\Organization — организации, их роли и принадлежность УК',
            'users'=>'App\\Models\\User — учётные записи организаций', 'vehicles'=>'App\\Models\\Vehicle — автомобили; VIN уникален, organization_id — владелец',
            'stations'=>'App\\Models\\Station — СТО-партнёры; рейтинг зарезервирован, история строится по заказ-нарядам',
            'work_types'=>'App\\Models\\WorkType — виды работ', 'time_norms'=>'App\\Models\\TimeNorm — нормы времени и источник норматива',
            'service_reasons'=>'App\\Models\\ServiceReason — причины и подвиды обращения', 'maintenance_regulations'=>'App\\Models\\MaintenanceRegulation — регламенты ТО',
            'maintenance_regulation_items'=>'App\\Models\\MaintenanceRegulationItem — состав работ регламента', 'suppliers'=>'App\\Models\\Supplier — поставщики запчастей',
            'repair_requests'=>'App\\Models\\RepairRequest — заявка, номер RP, UUID и глобальный integer ID', 'request_events'=>'App\\Models\\RequestEvent — неизменяемая история действий',
            'request_status_periods'=>'App\\Models\\RequestStatusPeriod — интервалы нахождения заявки в статусах', 'attachments'=>'App\\Models\\Attachment — метаданные приватных файлов в облаке',
            'diagnostic_reports'=>'App\\Models\\DiagnosticReport — заключения диагностики СТО', 'request_works'=>'App\\Models\\RequestWork — работы сметы',
            'request_parts'=>'App\\Models\\RequestPart — запчасти, согласование и поставщик', 'part_movements'=>'App\\Models\\PartMovement — возвраты и обмены поставщикам',
            'request_approvals'=>'App\\Models\\RequestApproval — решения с комментариями и снимками согласованной версии',
            'work_orders'=>'App\\Models\\WorkOrder — результат выполненного ремонта', 'work_order_items'=>'App\\Models\\WorkOrderItem — выполненные работы и использованные запчасти',
            'vehicle_service_records'=>'App\\Models\\VehicleServiceRecord — история ТО и работ по пробегу', 'invoices'=>'App\\Models\\Invoice — выставленный и закрытый счёт, проверка оплаты',
            'catalog_records'=>'LEGACY — старый универсальный справочник; Eloquent-модели API v2 нет',
            'sessions'=>'Laravel DatabaseSessionHandler — серверные сессии', 'cache'=>'Laravel DatabaseStore — кеш и rate limit', 'cache_locks'=>'Laravel DatabaseLock — блокировки кеша',
        ];
        foreach ($models as $table=>$description) DB::statement('COMMENT ON TABLE '.$table.' IS '.DB::getPdo()->quote($description));
        foreach (['organizations','vehicles','stations','repair_requests'] as $table) {
            DB::statement("COMMENT ON COLUMN {$table}.id IS 'Глобальный целочисленный идентификатор сущности внутри системы'");
            DB::statement("COMMENT ON COLUMN {$table}.uuid IS 'Внешний стабильный UUID сущности'");
        }
        DB::statement("COMMENT ON COLUMN repair_requests.history IS 'LEGACY: исходная история v1. Новые события хранятся в request_events'");
        DB::statement("COMMENT ON COLUMN repair_requests.works IS 'LEGACY: исходная смета v1. Новые работы хранятся в request_works и request_parts'");
    }
    private function row(array $data): array { return array_merge(['uuid'=>(string)Str::uuid(),'created_at'=>now(),'updated_at'=>now()],$data); }
    private function migrateLegacy(): void
    {
        $stations=[];
        foreach (DB::table('catalog_records')->orderBy('id')->get() as $record) {
            $d=json_decode($record->data,true);
            if ($record->kind==='stations') $stations[$record->id]=DB::table('stations')->insertGetId($this->row([
                'tenant_id'=>$record->tenant_id,'organization_id'=>$record->organization_id,'name'=>$d['name']??'', 'address'=>$d['address']??''
            ]));
            if ($record->kind==='vehicles' && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/',strtoupper($d['vin']??''))) {
                $vin=strtoupper($d['vin']);
                $existing=DB::table('vehicles')->where('vin',$vin)->first();
                if ($existing && (int)$existing->organization_id!==(int)$record->organization_id) throw new RuntimeException('VIN '.$vin.' принадлежит двум организациям. Уточните владельца до миграции.');
                if (!$existing) DB::table('vehicles')->insert($this->row(['tenant_id'=>$record->tenant_id,'organization_id'=>$record->organization_id,
                    'name'=>$d['vehicle']??'', 'vin'=>$vin,'plate'=>$d['plate']??'','plate_normalized'=>preg_replace('/[^\p{L}\p{N}]/u','',mb_strtoupper($d['plate']??''))]));
            }
        }
        foreach (DB::table('repair_requests')->orderBy('id')->get() as $r) {
            $data=json_decode($r->data,true)??[]; $vehicle=DB::table('vehicles')->where('organization_id',$r->customer_organization_id)->where('vin',strtoupper($data['vin']??''))->first();
            DB::table('repair_requests')->where('id',$r->id)->update(['vehicle_id'=>$vehicle?->id,'assigned_station_id'=>$stations[$r->station_id]??null]);
            $entered=$r->created_at; $stage=0;
            foreach (json_decode($r->history,true)??[] as $h) {
                $at=$h['at']??$r->created_at;
                DB::table('request_events')->insert($this->row(['repair_request_id'=>$r->id,'actor_id'=>isset($h['actorId'])&&DB::table('users')->where('id',$h['actorId'])->exists()?$h['actorId']:null,
                    'actor_label'=>$h['who']??'','action'=>$h['action']??'legacy','comment'=>$h['text']??'','stage'=>$h['stage']??0,'revision'=>$h['revision']??0,'occurred_at'=>$at]));
                if (in_array($h['action']??'', ['next','return'],true) && isset($h['stage'])) {
                    DB::table('request_status_periods')->insert($this->row(['repair_request_id'=>$r->id,'stage'=>$stage,'entered_at'=>$entered,'exited_at'=>$at]));
                    $stage=$h['stage']; $entered=$at;
                }
            }
            DB::table('request_status_periods')->insert($this->row(['repair_request_id'=>$r->id,'stage'=>$r->stage,'entered_at'=>$entered]));
            foreach (json_decode($r->works,true)??[] as $w) {
                $table=($w['type']??'Работа')==='Материал'?'request_parts':'request_works';
                DB::table($table)->insert($this->row(['repair_request_id'=>$r->id,'name'=>$w['name']??'','quantity'=>$w['qty']??0,'price'=>$w['price']??0]));
            }
        }
    }
    public function down(): void { throw new RuntimeException('Нормализация v2 содержит историю и финансовые данные. Автоматический откат отключён; восстановите согласованную резервную копию.'); }
};
