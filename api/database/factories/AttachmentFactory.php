<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Models\Attachment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
final class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'uuid'       => (string) Str::uuid(),
            'tenant_id'  => Tenant::factory(),
            'purpose'    => AttachmentPurpose::EdoPayload->value,
            'mime_type'  => AttachmentMime::Xml->value,
            'filename'   => 'file.xml',
            'size'       => 0,
            'path'       => 'attachments/' . Str::uuid() . '.xml',
        ];
    }
}
