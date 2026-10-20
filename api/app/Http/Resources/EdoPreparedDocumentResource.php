<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Edo\Dto\EdoPreparedDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EdoPreparedDocument
 */
final class EdoPreparedDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid'            => $this->uuid,
            'payloadBase64'   => $this->payloadBase64,
            'payloadFilename' => $this->payloadFilename,
            'signatureHint'   => $this->signatureHint,
        ];
    }
}
