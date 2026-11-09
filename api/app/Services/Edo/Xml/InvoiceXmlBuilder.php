<?php

declare(strict_types=1);

namespace App\Services\Edo\Xml;

use App\DTO\EdoInvoicePayload;
use App\DTO\EdoInvoiceRow;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use App\Exceptions\EdoException;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

final class InvoiceXmlBuilder
{
    private const VERSION_FORM = 'proformainvoice29_05_01_01';
    private const KND = '1115133'; // КНД для счёта на оплату (уточните по XSD, если есть)
    private const CURRENCY_CODE = '643';
    private const CURRENCY_NAME = 'Российский рубль';
    private const COUNTRY_CODE = '643';
    private const COUNTRY_NAME = 'РОССИЯ';

    private string $lastIdFile = '';

    public function build(
        EdoInvoicePayload $payload,
        string $number,
        \DateTimeImmutable $date,
        EdoSignerDto $signer,
    ): string {
        $idFile = $this->generateFileId($payload, $date);
        $this->lastIdFile = $idFile;

        $xml = $this->render($payload, $number, $date, $idFile, $signer);

        // Валидация по XSD (если есть)
        // $this->validator->validate($xml, $this->xsdPath);

        return $xml;
    }

    public function getLastGeneratedIdFile(): string
    {
        return $this->lastIdFile;
    }

    private function generateFileId(EdoInvoicePayload $payload, \DateTimeImmutable $date): string
    {
        $senderEdi = $payload->seller->ediCode;
        $receiverEdi = $payload->buyer->ediCode;

        if (empty($senderEdi) || empty($receiverEdi)) {
            throw EdoException::remoteError('edoru', 'MISSING_EDI_CODE', 'Не заполнен edo_edi_code у отправителя или получателя');
        }

        // Префикс для счёта на оплату (уточните: ON_SCHET или ON_CHETOP)
        $prefix = 'ON_SCHET';
        $guid = Str::uuid()->toString();
        $dateStr = $date->format('Ymd');

        // Формат: ON_SCHET_ИдПол_ИдОтпр_ГГГГММДД_GUID
        return sprintf(
            '%s_%s_%s_%s_%s',
            $prefix,
            $receiverEdi,
            $senderEdi,
            $dateStr,
            $guid
        );
    }

    private function render(
        EdoInvoicePayload $payload,
        string $number,
        \DateTimeImmutable $date,
        string $idFile,
        EdoSignerDto $signer,
    ): string {
        $dom = new DOMDocument('1.0', 'windows-1251');
        $dom->formatOutput = true;

        // Корневой тег Файл
        $file = $dom->createElement('Файл');
        $file->setAttribute('ИдФайл', $idFile);
        $file->setAttribute('ВерсФорм', self::VERSION_FORM);
        $file->setAttribute('ВерсПрог', 'MSTO-API-1.0');
        $dom->appendChild($file);

        // Тег Документ
        $doc = $dom->createElement('Документ');
        $doc->setAttribute('КНД', self::KND);
        $doc->setAttribute('Функция', '0'); // 0 = счёт на оплату
        $doc->setAttribute('ДатаИнфПр', $date->format('d.m.Y'));
        $doc->setAttribute('ВремИнфПр', $date->format('H.i.s'));
        $doc->setAttribute('НаимЭконСубСост', $payload->seller->name);
        $file->appendChild($doc);

        // СвСчФакт (Заголовок счёта)
        $svSchFact = $dom->createElement('СвСчФакт');
        $svSchFact->setAttribute('НомерДок', $number);
        $svSchFact->setAttribute('ДатаДок', $date->format('d.m.Y'));
        $doc->appendChild($svSchFact);

        // СвПрод (Продавец)
        $this->appendParty($dom, $svSchFact, 'СвПрод', $payload->seller);
        // СвПлат (Плательщик) - в счёте это покупатель
        $this->appendParty($dom, $svSchFact, 'СвПлат', $payload->buyer);

        // ДенИзм
        $denIzm = $dom->createElement('ДенИзм');
        $denIzm->setAttribute('КодОКВ', self::CURRENCY_CODE);
        $denIzm->setAttribute('НаимОКВ', self::CURRENCY_NAME);
        $svSchFact->appendChild($denIzm);

        // ТаблСчФакт (Таблица товаров/услуг)
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

        // Подписант
        $this->appendSigner($dom, $doc, $signer);

        return ltrim($dom->saveXML());
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
        $adrInf->setAttribute('АдрТекст', $party->address ?? 'Адрес не указан');
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
            '018' => 'усл. ед.',
            '796' => 'шт',
            default => 'шт',
        };
    }

    private function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
