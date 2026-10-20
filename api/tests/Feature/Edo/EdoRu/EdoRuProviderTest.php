<?php

declare(strict_types=1);

namespace Tests\Feature\Edo\EdoRu;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Exceptions\EdoException;
use App\Services\Edo\Dto\EdoInvoicePayload;
use App\Services\Edo\Dto\EdoInvoiceRow;
use App\Services\Edo\Dto\EdoPartyInfo;
use App\Services\Edo\Dto\EdoSignerDto;
use App\Services\Edo\Dto\EdoStructuredRequest;
use App\Services\Edo\Providers\EdoRu\EdoRuClient;
use App\Services\Edo\Providers\EdoRu\EdoRuMapper;
use App\Services\Edo\Providers\EdoRu\EdoRuProvider;
use App\Services\Edo\Xml\Upd503Builder;
use App\Services\Edo\Xml\XsdValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EdoRuProviderTest extends TestCase
{
    private function provider(): EdoRuProvider
    {
        config()->set('edo.providers.edoru.base_url', 'https://edoru.example.test/api/edo/v1');
        config()->set('edo.providers.edoru.auth_mode', 'auth-global');
        config()->set('edo.providers.edoru.client_id', '2PS-0078414651');
        config()->set('edo.providers.edoru.password', 'PASSWORD');
        config()->set('edo.providers.edoru.encoding', 'windows-1251');

        Cache::put('edo:edoru:token:2PS-0078414651', 'cached-token', now()->addHour());

        return new EdoRuProvider(
            client:     EdoRuClient::fromConfig(config('edo.providers.edoru')),
            mapper:     new EdoRuMapper(),
            updBuilder: new Upd503Builder(
                validator: new XsdValidator(),
                xsdPath:   base_path('resources/edo/xsd/ON_NSCHFDOPPR_1_997_01_05_03_05.xsd'),
            ),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
    }

    public function test_find_organization_returns_null_when_not_found(): void
    {
        Http::fake([
            'edoru.example.test/api/edo/v1/contractors/search-contractors*' => $this->edoRuResponse([
                'status' => ['code' => 0, 'message' => 'OK'],
                'result' => ['data' => [], 'pageInfo' => []],
            ]),
        ]);

        $this->assertNull($this->provider()->findOrganization('9999999999'));
    }

    public function test_find_organization_matches_inn_and_kpp(): void
    {
        Http::fake([
            'edoru.example.test/api/edo/v1/contractors/search-contractors*' => $this->edoRuResponse([
                'status' => ['code' => 0, 'message' => 'OK'],
                'result' => [
                    'data' => [
                        [
                            'clientId'       => '2PS-00278567668300483440610014472643',
                            'fullName'       => 'ООО "Рога и копыта"',
                            'inn'            => '2785676683',
                            'kpp'            => '048344061',
                            'ogrn'           => '5097571958996',
                            'operatorMnemo'  => 'ТЕНЗОР',
                        ],
                    ],
                    'pageInfo' => [],
                ],
            ]),
        ]);

        $org = $this->provider()->findOrganization('2785676683', '048344061');

        $this->assertNotNull($org);
        $this->assertSame('2785676683', $org->inn);
        $this->assertSame('048344061', $org->kpp);
        $this->assertSame('2PS-00278567668300483440610014472643', $org->id);
        $this->assertSame('2PS-00278567668300483440610014472643', $org->ediCode);
        $this->assertSame('ТЕНЗОР', $org->operatorCode);
    }

    public function test_get_document_returns_dto(): void
    {
        Http::fake([
            'edoru.example.test/api/edo/v1/documents/document*' => $this->edoRuResponse([
                'status' => ['code' => 0, 'message' => 'OK'],
                'result' => [
                    'docId'         => 4483,
                    'fromOrgName'   => 'ООО ПС-СТ',
                    'toOrgName'     => 'ОФД.РУ',
                    'docTypeId'     => 8,
                    'docTypeName'   => 'УПД',
                    'docStateId'    => 0,
                    'docStateName'  => 'Ожидается подпись',
                    'sfStateId'     => 2,
                    'docNumber'     => 'N-345',
                    'docDate'       => '17.10.2018 11:15:01',
                    'guid'          => 'abc-123',
                    'fileName'      => 'ON_UPD.xml',
                    'content'       => base64_encode('<xml/>'),
                ],
            ]),
        ]);

        $doc = $this->provider()->getDocument('4483');

        $this->assertSame('4483', $doc->externalId);
        $this->assertSame('abc-123', $doc->externalUuid);
        $this->assertSame(EdoDocumentType::UpdSfDop, $doc->type);
        $this->assertSame(EdoDocumentStatus::ToSign, $doc->status);
        $this->assertSame('N-345', $doc->number);
        $this->assertSame('ООО ПС-СТ', $doc->senderName);
        $this->assertSame('ОФД.РУ', $doc->receiverName);
    }

    public function test_get_document_content_returns_binary_file(): void
    {
        $xml = '<?xml version="1.0"?><Файл/>';

        Http::fake([
            'edoru.example.test/api/edo/v1/documents/download-doc*' => Http::response($xml, 200),
        ]);

        $file = $this->provider()->getDocumentContent('4483');

        $this->assertSame('text/xml', $file->mimeType);
        $this->assertSame($xml, $file->toBinary());
    }

    public function test_download_pdf_returns_pdf_file(): void
    {
        $pdf = '%PDF-1.4 fake';

        Http::fake([
            'edoru.example.test/api/edo/v1/documents/show-doc-pdf*' => Http::response($pdf, 200),
        ]);

        $file = $this->provider()->downloadPdf('4483');

        $this->assertSame('application/pdf', $file->mimeType);
        $this->assertSame($pdf, $file->toBinary());
    }

    public function test_pull_events_filters_by_cursor(): void
    {
        Http::fake([
            'edoru.example.test/api/edo/v1/documents/find*' => $this->edoRuResponse([
                'status' => ['code' => 0, 'message' => 'OK'],
                'result' => [
                    'data' => [
                        ['docId' => 1, 'docTypeId' => 8, 'docStateName' => 'Ожидается подпись'],
                        ['docId' => 5, 'docTypeId' => 8, 'docStateName' => 'Доставлен'],
                        ['docId' => 9, 'docTypeId' => 8, 'docStateName' => 'Подписан'],
                    ],
                    'pageInfo' => [],
                ],
            ]),
        ]);

        $result = $this->provider()->pullEvents(fromEventId: 4, count: 100);

        $this->assertCount(2, $result->events); // docId 5 и 9
        $this->assertSame(9, $result->lastEventId);
    }

    public function test_create_structured_document_builds_valid_upd(): void
    {
        $payload = new EdoInvoicePayload(
            seller: new EdoPartyInfo(
                name:    'ООО «СТО Тест»',
                inn:     '7713026678',
                kpp:     '771301001',
                address: 'г Москва, ул Хуторская 2-я, д 38А',
                ediCode: '2PS-AAA',
            ),
            buyer: new EdoPartyInfo(
                name:    'ООО «УК Тест»',
                inn:     '7728639738',
                kpp:     '772801001',
                address: 'г Москва, ул Ленина, д 1',
                ediCode: '2PS-BBB',
            ),
            rows: [
                new EdoInvoiceRow(
                    name:     'Замена масла',
                    unit:     '018',
                    amount:   '1.000',
                    price:    '1200.00',
                    netSum:   '1000.00',
                    vatSum:   '200.00',
                    totalSum: '1200.00',
                    vatType:  '20%',
                ),
            ],
        );

        $signer = new EdoSignerDto(
            lastName:  'Иванов',
            firstName: 'Иван',
            middleName:'Иванович',
            title:     'Директор',
        );

        $dto = $this->provider()->createStructuredDocument(
            new EdoStructuredRequest(
                type:            EdoDocumentType::UpdSfDop,
                receiverEdiCode: '2PS-BBB',
                number:          'УПД-1',
                date:            new \DateTimeImmutable('2026-10-13'),
                payload:         $payload,
                signer:          $signer,
                senderEdiCode:   '2PS-AAA',
            ),
        );

        $this->assertSame('', $dto->externalId);
        $this->assertNull($dto->externalUuid);
        $this->assertSame(EdoDocumentType::UpdSfDop, $dto->type);
        $this->assertSame(EdoDocumentStatus::Draft, $dto->status);
        $this->assertSame('УПД-1', $dto->number);
        $this->assertNotNull($dto->content);
        $this->assertSame('text/xml', $dto->content->mimeType);
        $this->assertStringStartsWith('ON_NSCHFDOPPR_2PS-BBB_2PS-AAA_20261013_', $dto->content->filename);

        // Проверяем, что XML — валидный по XSD.
        $xml = $dto->content->toBinary();
        $this->assertStringContainsString('windows-1251', mb_convert_encoding($xml, 'UTF-8', 'windows-1251'));
        $this->assertStringContainsString('<Файл', mb_convert_encoding($xml, 'UTF-8', 'windows-1251'));
    }

    public function test_create_structured_document_throws_when_no_signer(): void
    {
        $this->expectException(EdoException::class);
        $this->expectExceptionMessage('signer');

        $this->provider()->createStructuredDocument(
            new EdoStructuredRequest(
                type:            EdoDocumentType::UpdSfDop,
                receiverEdiCode: '2PS-BBB',
                number:          'УПД-1',
                date:            new \DateTimeImmutable(),
                payload:         new EdoInvoicePayload(
                    seller: new EdoPartyInfo('S', '1111111111', '111111111'),
                    buyer:  new EdoPartyInfo('B', '2222222222', '222222222'),
                    rows: [
                        new EdoInvoiceRow('Товар', '796', '1.000', '100.00', '83.33', '16.67', '100.00', '20%'),
                    ],
                ),
                // signer НЕ передан
            ),
        );
    }

    public function test_create_structured_document_throws_when_no_rows(): void
    {
        $this->expectException(EdoException::class);
        $this->expectExceptionMessage('без строк');

        $this->provider()->createStructuredDocument(
            new EdoStructuredRequest(
                type:            EdoDocumentType::UpdSfDop,
                receiverEdiCode: '2PS-BBB',
                number:          'УПД-1',
                date:            new \DateTimeImmutable(),
                payload:         new EdoInvoicePayload(
                    seller: new EdoPartyInfo('S', '1111111111', '111111111'),
                    buyer:  new EdoPartyInfo('B', '2222222222', '222222222'),
                    rows: [],
                ),
                signer: new EdoSignerDto('Иванов', 'Иван'),
            ),
        );
    }
}
