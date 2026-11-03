<?php

declare(strict_types=1);

namespace App\Services\Edo\Xml;

use App\DTO\EdoInvoicePayload;
use App\DTO\EdoInvoiceRow;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use App\Enums\Edo\UpdFunction;
use App\Exceptions\EdoException;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

final class Upd503Builder
{
    private const PROVIDER_LABEL = 'diadoc';
    private const VERSION_FORM   = '5.03';
    private const KND_UPD_503    = '1115131';
    private const CURRENCY_CODE  = '643';
    private const CURRENCY_NAME  = 'Российский рубль';
    private const COUNTRY_CODE   = '643';
    private const COUNTRY_NAME   = 'РОССИЯ';

    /**
     * Сгенерированный ИдФайл (чтобы потом отдать в API для FileName).
     */
    private string $lastIdFile = '';

    public function __construct(
        private readonly XsdValidator $validator,
        private readonly string $xsdPath,
        private readonly string $versionProgram = 'MSTO-API-1.0',
    ) {
        if (mb_strlen($this->versionProgram) > 40) {
            throw new EdoException("versionProgram > 40 символов", 0);
        }
    }

    public function build(
        EdoInvoicePayload $payload,
        UpdFunction $function,
        string $number,
        DateTimeImmutable $date,
        EdoSignerDto $signer,
    ): string {
        // 1. Генерируем уникальный ИдФайл по стандарту ФНС
        $idFile = $this->generateFileId($payload, $function, $date);
        $this->lastIdFile = $idFile;

        // 2. Рендерим XML
        $xml = $this->render($payload, $function, $number, $date, $idFile, $signer);

        // 3. Валидируем по XSD (если файл существует)
        // Если XSD нет, или он не подходит, временно можно отключить валидацию
        $this->validator->validate($xml, $this->xsdPath);

        return $xml;
    }

    /**
     * Возвращает ИдФайл из последнего успешного вызова build().
     * Используется для передачи в API Диадока (FileName).
     */
    public function getLastGeneratedIdFile(): string
    {
        return $this->lastIdFile;
    }

    private function generateFileId(EdoInvoicePayload $payload, UpdFunction $function, DateTimeImmutable $date): string
    {
        $senderEdi = $payload->seller->ediCode;
        $receiverEdi = $payload->buyer->ediCode;

        if (empty($senderEdi) || empty($receiverEdi)) {
            throw new \RuntimeException('Не заполнен edo_edi_code у отправителя или получателя');
        }

        // Для УПД используется префикс ON_NSCHFDOPPR
        $prefix = 'ON_NSCHFDOPPR';
        $guid = Str::uuid()->toString();
        $dateStr = $date->format('Ymd');

        // Формат: ON_NSCHFDOPPR_ИдПол_ИдОтпр_ГГГГММДД_GUID_0_0_0_0_0_00
        return sprintf(
            '%s_%s_%s_%s_%s_0_0_0_0_0_00',
            $prefix,
            $receiverEdi,
            $senderEdi,
            $dateStr,
            $guid
        );
    }

    private function render(
        EdoInvoicePayload $payload,
        UpdFunction $function,
        string $number,
        DateTimeImmutable $date,
        string $idFile,
        EdoSignerDto $signer,
    ): string {
        if ($payload->rows === []) {
            throw new \RuntimeException('Нельзя сформировать УПД без строк');
        }

        $funcCode = $function->value;
        $requiresTable = in_array($funcCode, ['СЧФ', 'СЧФДОП'], true);
        $requiresTransfer = in_array($funcCode, ['ДОП', 'СЧФДОП'], true);

        $dom = new DOMDocument('1.0', 'windows-1251');
        $dom->formatOutput = true;

        // Корневой тег Файл
        $file = $dom->createElement('Файл');
        $file->setAttribute('ИдФайл', $idFile);
        $file->setAttribute('ВерсФорм', self::VERSION_FORM);
        $file->setAttribute('ВерсПрог', $this->versionProgram);
        $dom->appendChild($file);

        // Тег Документ
        $doc = $dom->createElement('Документ');
        $doc->setAttribute('КНД', self::KND_UPD_503);
        $doc->setAttribute('Функция', $funcCode);
        $doc->setAttribute('ПоФактХЖ', 'Документ об отгрузке товаров (выполнении работ), передаче имущественных прав (документ об оказании услуг)');
        if ($requiresTransfer) {
            $doc->setAttribute('НаимДокОпр', 'Документ об отгрузке товаров (выполнении работ), передаче имущественных прав (документ об оказании услуг)');
        }
        $doc->setAttribute('ДатаИнфПр', $date->format('d.m.Y'));
        $doc->setAttribute('ВремИнфПр', $date->format('H.i.s'));
        $doc->setAttribute('НаимЭконСубСост', $payload->seller->name);
        $file->appendChild($doc);

        // СвСчФакт
        $svSchFact = $dom->createElement('СвСчФакт');
        $svSchFact->setAttribute('НомерДок', $number);
        $svSchFact->setAttribute('ДатаДок', $date->format('d.m.Y'));
        $doc->appendChild($svSchFact);

        $this->appendParty($dom, $svSchFact, 'СвПрод', $payload->seller);
        $this->appendParty($dom, $svSchFact, 'СвПокуп', $payload->buyer);

        // ДенИзм
        $denIzm = $dom->createElement('ДенИзм');
        $denIzm->setAttribute('КодОКВ', self::CURRENCY_CODE);
        $denIzm->setAttribute('НаимОКВ', self::CURRENCY_NAME);
        $svSchFact->appendChild($denIzm);

        // ТаблСчФакт
        if ($requiresTable) {
            $table = $dom->createElement('ТаблСчФакт');
            $doc->appendChild($table);

            $position = 1;
            $totalWithoutNds = 0.0;
            $totalWithNds = 0.0;
            $totalNds = 0.0;

            foreach ($payload->rows as $row) {
                $svTov = $this->buildRow($dom, $row, $position++);
                $table->appendChild($svTov);

                $totalWithoutNds += (float) $row->netSum;
                $totalWithNds += (float) $row->totalSum;
                $totalNds += (float) $row->vatSum;
            }

            $vsego = $dom->createElement('ВсегоОпл');
            $vsego->setAttribute('СтТовБезНДСВсего', $this->num($totalWithoutNds));
            $vsego->setAttribute('СтТовУчНалВсего', $this->num($totalWithNds));

            $sumNalVsego = $dom->createElement('СумНалВсего');
            $sumNal = $dom->createElement('СумНал', $this->num($totalNds));
            $sumNalVsego->appendChild($sumNal);
            $vsego->appendChild($sumNalVsego);

            $table->appendChild($vsego);
        }

        // СвПродПер
        if ($requiresTransfer) {
            $svProdPer = $dom->createElement('СвПродПер');
            $svPer = $dom->createElement('СвПер');
            $svPer->setAttribute('СодОпер', 'Товары (работы, услуги) переданы');
            $svPer->setAttribute('ДатаПер', $date->format('d.m.Y'));

            // БезДокОснПер обязателен для ДОП/СЧФДОП, если нет ссылки на другой документ
            $bezDok = $dom->createElement('БезДокОснПер', '1');
            $svPer->appendChild($bezDok);

            $svProdPer->appendChild($svPer);
            $doc->appendChild($svProdPer);
        }

        // Подписант
        $this->appendSigner($dom, $doc, $signer);

        $xml = $dom->saveXML();

        // Убираем BOM и декларацию (если XSD требует)
        return ltrim($xml);
    }

    private function appendParty(DOMDocument $dom, DOMElement $parent, string $tag, EdoPartyInfo $party): void
    {
        $node = $dom->createElement($tag);
        $parent->appendChild($node);

        $idSv = $dom->createElement('ИдСв');
        $node->appendChild($idSv);

        $svUl = $dom->createElement('СвЮЛУч');
        $svUl->setAttribute('НаимОрг', $party->name);
        $svUl->setAttribute('ИННЮЛ', $party->inn);
        if ($party->kpp !== '') {
            $svUl->setAttribute('КПП', $party->kpp);
        }
        $idSv->appendChild($svUl);

        $adres = $dom->createElement('Адрес');
        $node->appendChild($adres);

        $adrInf = $dom->createElement('АдрИнф');
        $adrInf->setAttribute('КодСтр', self::COUNTRY_CODE);
        $adrInf->setAttribute('НаимСтран', self::COUNTRY_NAME);
        $adrInf->setAttribute(
            'АдрТекст',
            $party->address ?? 'Адрес не указан',
        );
        $adres->appendChild($adrInf);
    }

    private function buildRow(DOMDocument $dom, EdoInvoiceRow $row, int $position)
    {
        $svTov = $dom->createElement('СведТов');
        $svTov->setAttribute('НомСтр', (string) $position);
        $svTov->setAttribute('НаимТов', $row->name);
        $svTov->setAttribute('ОКЕИ_Тов', $row->unit);
        $svTov->setAttribute('НаимЕдИзм', $this->unitName($row->unit));
        $svTov->setAttribute('КолТов', $row->amount);
        $svTov->setAttribute('ЦенаТов', $row->price);
        $svTov->setAttribute('СтТовБезНДС', $row->netSum);
        $svTov->setAttribute('НалСт', $row->vatType);
        $svTov->setAttribute('СтТовУчНал', $row->totalSum);

        $sumNal = $dom->createElement('СумНал');
        if ($row->vatType === 'без НДС') {
            $bezNds = $dom->createElement('БезНДС', 'без НДС');
            $sumNal->appendChild($bezNds);
        } else {
            $sumNalInner = $dom->createElement('СумНал', $row->vatSum);
            $sumNal->appendChild($sumNalInner);
        }
        $svTov->appendChild($sumNal);

        return $svTov;
    }

    private function appendSigner(DOMDocument $dom, DOMElement $parent, EdoSignerDto $signer): void
    {
        $podp = $dom->createElement('Подписант');
        if ($signer->title !== '') {
            $podp->setAttribute('Должн', $signer->title);
        }
        $podp->setAttribute('СпосПодтПолном', $signer->authorityScope);
        $podp->setAttribute('ТипПодпис', $signer->signatureType);

        $fio = $dom->createElement('ФИО');
        $fio->setAttribute('Фамилия', $signer->lastName);
        $fio->setAttribute('Имя', $signer->firstName);
        if ($signer->middleName !== null && $signer->middleName !== '') {
            $fio->setAttribute('Отчество', $signer->middleName);
        }
        $podp->appendChild($fio);

        $parent->appendChild($podp);
    }

    private function unitName(string $code): string
    {
        return match ($code) {
            '018'   => 'усл. ед.',
            '796'   => 'шт',
            default => 'шт',
        };
    }

    private function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
