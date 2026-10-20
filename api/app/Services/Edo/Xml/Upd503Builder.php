<?php

declare(strict_types=1);

namespace App\Services\Edo\Xml;

use App\Enums\Edo\UpdFunction;
use App\Exceptions\EdoException;
use App\Services\Edo\Dto\EdoInvoicePayload;
use App\Services\Edo\Dto\EdoInvoiceRow;
use App\Services\Edo\Dto\EdoPartyInfo;
use App\Services\Edo\Dto\EdoSignerDto;

/**
 * Генератор XML УПД 5.03 (КНД 1115131, часть 997, версия 01 или 04).
 *
 * Источник: приказ ФНС России от 19.12.2023 № ЕД-7-26/970@
 * (в редакции от 15.11.2024, от 26.03.2025).
 *
 * XSD: resources/edo/xsd/ON_NSCHFDOPPR_1_997_01_05_03_05.xsd
 *
 * Результат — XML в кодировке windows-1251.
 *
 * Реализовано:
 *   - Функция СЧФ, СЧФДОП, ДОП (по UpdFunction);
 *   - СвСчФакт с обязательным ДенИзм;
 *   - ТаблСчФакт для СЧФ|СЧФДОП;
 *   - СвПродПер для ДОП|СЧФДОП (БезДокОснПер);
 *   - ВсегоОпл с обязательными СтТовБезНДСВсего и СтТовУчНалВсего;
 *   - Подписант (ФИО, СпосПодтПолном=1).
 *
 * НЕ реализовано (TODO после sandbox):
 *   - ИП (СвИП) — сейчас только ЮЛ (СвЮЛУч);
 *   - ГрузОт/ГрузПолуч;
 *   - прослеживаемость, маркировка;
 *   - акцизы;
 *   - коррекционные УПД (ИспрДок).
 */
final class Upd503Builder
{
    private const PROVIDER_LABEL = 'edoru';
    private const VERSION_FORM   = '5.03';
    private const KND_UPD_503    = '1115131';
    private const CURRENCY_CODE  = '643';
    private const CURRENCY_NAME  = 'Российский рубль';
    private const COUNTRY_CODE   = '643';
    private const COUNTRY_NAME   = 'РОССИЯ';

    public function __construct(
        private readonly XsdValidator $validator,
        private readonly string $xsdPath,
        private readonly string $versionProgram = 'MSTO-API-1.0',
    ) {
        if (mb_strlen($this->versionProgram) > 40) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'INVALID_VERSION_PROGRAM',
                'versionProgram > 40 символов',
            );
        }
    }

    /**
     * @param  array{
     *     lastName:string, firstName:string, middleName?:?string,
     *     title:string
     * }  $signer
     */
    public function build(
        EdoInvoicePayload $payload,
        UpdFunction $function,
        string $number,
        \DateTimeImmutable $date,
        string $fileName,
        EdoSignerDto $signer,
    ): string {
        $xml = $this->render($payload, $function, $number, $date, $fileName, $signer);

        $this->validator->validate($xml, $this->xsdPath);

        return $xml;
    }

    /**
     * @param  array{lastName:string, firstName:string, middleName?:?string, title:string}  $signer
     */
    private function render(
        EdoInvoicePayload $payload,
        UpdFunction $function,
        string $number,
        \DateTimeImmutable $date,
        string $fileName,
        EdoSignerDto $signer,
    ): string {
        if ($payload->rows === []) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'UPD_NO_ROWS',
                'Нельзя сформировать УПД без строк',
            );
        }

        $funcCode = $function->value;
        $requiresTable    = in_array($funcCode, ['СЧФ', 'СЧФДОП'], true);
        $requiresTransfer = in_array($funcCode, ['ДОП', 'СЧФДОП'], true);

        $dom = new \DOMDocument('1.0', 'windows-1251');
        $dom->formatOutput = true;

        $file = $dom->createElement('Файл');
        $file->setAttribute('ИдФайл', $fileName);
        $file->setAttribute('ВерсФорм', self::VERSION_FORM);
        $file->setAttribute('ВерсПрог', $this->versionProgram);
        $dom->appendChild($file);

        $doc = $dom->createElement('Документ');
        $doc->setAttribute('КНД', self::KND_UPD_503);
        $doc->setAttribute('Функция', $funcCode);
        $doc->setAttribute('ПоФактХЖ', $this->documentTitle($funcCode));
        if (in_array($funcCode, ['СЧФДОП', 'ДОП'], true)) {
            $doc->setAttribute('НаимДокОпр', $this->documentTitle($funcCode));
        }
        $doc->setAttribute('ДатаИнфПр', $date->format('d.m.Y'));
        $doc->setAttribute('ВремИнфПр', $date->format('H.i.s'));
        $doc->setAttribute('НаимЭконСубСост', $payload->seller->name);
        $file->appendChild($doc);

        // ─── СвСчФакт ───
        $svSchFact = $dom->createElement('СвСчФакт');
        $svSchFact->setAttribute('НомерДок', $number);
        $svSchFact->setAttribute('ДатаДок', $date->format('d.m.Y'));
        $doc->appendChild($svSchFact);

        $this->appendParty($dom, $svSchFact, 'СвПрод', $payload->seller, true);
        $this->appendParty($dom, $svSchFact, 'СвПокуп', $payload->buyer, true);

        // ДенИзм — обязателен всегда
        $denIzm = $dom->createElement('ДенИзм');
        $denIzm->setAttribute('КодОКВ', self::CURRENCY_CODE);
        $denIzm->setAttribute('НаимОКВ', self::CURRENCY_NAME);
        $svSchFact->appendChild($denIzm);

        // ─── ТаблСчФакт ───
        if ($requiresTable) {
            $table = $dom->createElement('ТаблСчФакт');
            $doc->appendChild($table);

            $position = 1;
            $totalWithoutNds = 0.0;
            $totalWithNds    = 0.0;
            $totalNds        = 0.0;

            foreach ($payload->rows as $row) {
                $svTov = $this->buildRow($dom, $row, $position++);
                $table->appendChild($svTov);

                $totalWithoutNds += (float) $row->netSum;
                $totalWithNds    += (float) $row->totalSum;
                $totalNds        += (float) $row->vatSum;
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

        // ─── СвПродПер ───
        if ($requiresTransfer) {
            $svProdPer = $dom->createElement('СвПродПер');
            $svPer = $dom->createElement('СвПер');
            $svPer->setAttribute('СодОпер', 'Товары (работы, услуги) переданы');
            $svPer->setAttribute('ДатаПер', $date->format('d.m.Y'));

            $bezDok = $dom->createElement('БезДокОснПер', '1');
            $svPer->appendChild($bezDok);

            $svProdPer->appendChild($svPer);
            $doc->appendChild($svProdPer);
        }

        // ─── Подписант ───
        $this->appendSigner($dom, $doc, $signer);

        $xml = $dom->saveXML();

        return ltrim($xml);
    }

    private function appendParty(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $tag,
        EdoPartyInfo $party,
        bool $requireAddress,
    ): void {
        $node = $dom->createElement($tag);
        $parent->appendChild($node);

        $idSv = $dom->createElement('ИдСв');
        $node->appendChild($idSv);

        // TODO: определять ИП по длине ИНН (12) или по OrganizationKind.
        $svUl = $dom->createElement('СвЮЛУч');
        $svUl->setAttribute('НаимОрг', $party->name);
        $svUl->setAttribute('ИННЮЛ', $party->inn);
        if ($party->kpp !== '' && $party->kpp !== null) {
            $svUl->setAttribute('КПП', $party->kpp);
        }
        $idSv->appendChild($svUl);

        if ($requireAddress) {
            $adres = $dom->createElement('Адрес');
            $node->appendChild($adres);

            $adrInf = $dom->createElement('АдрИнф');
            $adrInf->setAttribute('КодСтр', self::COUNTRY_CODE);
            $adrInf->setAttribute('НаимСтран', self::COUNTRY_NAME);
            $adrInf->setAttribute(
                'АдрТекст',
                $party->address !== null && $party->address !== ''
                    ? $party->address
                    : 'Адрес не указан',
            );
            $adres->appendChild($adrInf);
        }
    }

    private function buildRow(\DOMDocument $dom, EdoInvoiceRow $row, int $position): \DOMElement
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

    private function appendSigner(\DOMDocument $dom, \DOMElement $parent, EdoSignerDto $signer): void
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

    private function documentTitle(string $funcCode): string
    {
        return match ($funcCode) {
            'СЧФ'    => 'Счет-фактура',
            'СЧФДОП' => 'Счет-фактура и документ об отгрузке товаров (выполнении работ), передаче имущественных прав (документ об оказании услуг)',
            'ДОП'    => 'Документ об отгрузке товаров (выполнении работ), передаче имущественных прав (документ об оказании услуг)',
            default  => 'Документ',
        };
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
