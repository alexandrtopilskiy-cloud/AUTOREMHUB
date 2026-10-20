<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Edo\Dto\EdoFile;
use App\Services\Edo\EdoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EdoServicePersistPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_persist_payload_creates_attachment(): void
    {
        Storage::fake('media');

        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        $file = new EdoFile(
            filename: 'upd_123.xml',
            mimeType: 'text/xml',
            content:  base64_encode('<xml>test</xml>'),
        );

        $attachment = app(EdoService::class)->persistPayloadAsAttachment(
            file: $file,
            request: $request,
            uploadedByUserId: $user->id,
        );

        $this->assertSame(AttachmentPurpose::EdoPayload, $attachment->purpose);
        $this->assertSame(AttachmentMime::Xml, $attachment->mime_type);
        $this->assertSame('upd_123.xml', $attachment->original_name);
        $this->assertSame(strlen('<xml>test</xml>'), $attachment->size);
        $this->assertSame($request->id, $attachment->repair_request_id);
        $this->assertSame($user->id, $attachment->uploaded_by);
        $this->assertSame(
            hash('sha256', '<xml>test</xml>'),
            $attachment->sha256,
        );

        Storage::disk('media')->assertExists($attachment->object_key);
        $this->assertSame(
            '<xml>test</xml>',
            Storage::disk('media')->get($attachment->object_key),
        );
    }

    public function test_persist_payload_fails_without_open_period(): void
    {
        Storage::fake('media');

        [$tenant, $org, $user, $request] = $this->seedEnvironment(withPeriod: false);

        $this->expectException(\App\Exceptions\ApiException::class);

        app(EdoService::class)->persistPayloadAsAttachment(
            file: new EdoFile('upd.xml', 'text/xml', base64_encode('<x/>')),
            request: $request,
            uploadedByUserId: $user->id,
        );
    }

    public function test_persist_payload_rejects_unknown_mime(): void
    {
        Storage::fake('media');

        [$tenant, $org, $user, $request] = $this->seedEnvironment();

        $this->expectException(\App\Exceptions\ApiException::class);

        app(EdoService::class)->persistPayloadAsAttachment(
            file: new EdoFile('upd.pdf', 'application/pdf', base64_encode('%PDF-1.4')),
            request: $request,
            uploadedByUserId: $user->id,
        );
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: User, 3: RepairRequest}
     */
    private function seedEnvironment(bool $withPeriod = true): array
    {
        $tenant = Tenant::query()->create(['name' => 'Test Tenant']);

        $org = Organization::query()->create([
            'tenant_id'              => $tenant->id,
            'kind'                   => 'customer',
            'name'                   => 'ООО «Тестовый Заказчик»',
            'short_name'             => 'Тестовый Заказчик',
            'inn'                    => '7713026678',
            'kpp'                    => '771301001',
            'ogrn'                   => '1157746123456',
            'contact'                => 'Иванов И.И.',
            'legal_address'          => 'г. Москва, ул. Тестовая, д. 1',
            'actual_address'         => 'г. Москва, ул. Тестовая, д. 1',
            'postal_address'         => 'г. Москва, ул. Тестовая, д. 1',
            'director_name'          => 'Иванов Иван Иванович',
            'phone'                  => '+7 495 000-00-00',
            'email'                  => 'test@example.com',
            'bank_name'              => 'ПАО «Тест-Банк»',
            'bik'                    => '044525225',
            'settlement_account'     => '40817810000000000001',
            'correspondent_account'  => '30101810400000000225',
            'tax_regime'             => 'osn',
            'vat_rate'               => 20.00,
        ]);

        $user = User::query()->create([
            'organization_id' => $org->id,
            'login'           => 'test_user_' . uniqid(),
            'display_name'    => 'Test Manager',
            'role'            => 'manager',
            'password'        => bcrypt('x'),
            'active'          => true,
        ]);

        $request = RepairRequest::query()->create([
            'public_id'                => (string) Str::uuid(),
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $org->id,
            'revision'                 => 0,
            'stage'                    => 0,
            'data'                     => [],
            'works'                    => [],
            'history'                  => [],
            'estimate_version'         => 0,
            'review_context'           => 'initial',
            'review_baseline_version'  => 0,
            'created_by'               => $user->id,
        ]);

        if ($withPeriod) {
            DB::table('request_status_periods')->insert([
                'uuid'              => (string) Str::uuid(),
                'repair_request_id' => $request->id,
                'stage'             => 0,
                'entered_at'        => now(),
                'exited_at'         => null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        return [$tenant, $org, $user, $request];
    }
}
