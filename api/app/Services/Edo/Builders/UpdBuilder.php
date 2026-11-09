<?php

declare(strict_types=1);

namespace App\Services\Edo\Builders;

use App\DTO\EdoOrganizationDto;
use App\DTO\EdoStructuredRequest;
use App\Enums\Edo\EdoDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Services\DocumentGeneratorService;
use DateTimeImmutable;

final class UpdBuilder
{
    public function __construct(
        private readonly DocumentGeneratorService $generator,
    ) {}

    public function build(
        RepairRequest $request,
        EdoDocument $document,
        Organization $sender,
        Organization $receiver
    ): EdoStructuredRequest {
        // 1. Генерация XML (Здесь должен быть вызов вашего Upd503Builder)
        // Пока используем тестовый XML для проверки пайплайна
        $xmlContent = $this->generateMockXml($document);

        // 2. Генерация PDF (визуализация для пользователя и мок-режима)
        $pdfContent = $this->generator->generateUpd(
            $request,
            $request->workOrder,
            $sender,  // Organization модель
            $receiver // Organization модель
        );

        return new EdoStructuredRequest(
            type: EdoDocumentType::UpdDop,
            number: $document->number,
            date: new DateTimeImmutable($document->document_date->format('Y-m-d')),
            sender: new EdoOrganizationDto(
                id: $sender->uuid,
                name: $sender->name,
                inn: $sender->inn,
                kpp: $sender->kpp ?? '',
            ),
            receiver: new EdoOrganizationDto(
                id: $receiver->uuid,
                name: $receiver->name,
                inn: $receiver->inn,
                kpp: $receiver->kpp ?? '',
            ),
            items: [],
            totalSum: '0.00',
            vatSum: '0.00',
            vatIncluded: false,
            meta: [
                'xml_content'       => $xmlContent,
                'file_name'         => "УПД_{$document->number}.pdf",
                'file_content'      => base64_encode($pdfContent),
                'repair_request_id' => $request->id,
                'uploaded_by'       => $request->created_by,
            ],
        );
    }

    private function generateMockXml(EdoDocument $document): string
    {
        // Временная заглушка. Замените на вызов Upd503Builder
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Файл ВерсПрог="СБиС3" ВерсФорм="5.03" ИдФайл="ON_NSCHFDOPPR_2BEdc1f515a297c4a789845b05f2c92092c_2BEb835f2f0fbf943d396053d0f195a04c8_20240610_FB23D23F-2776-4494-9FEA-52C20ACCAD6E_0_0_0_0_0_00">
    <Документ ВремИнфПр="08.50.38" ДатаИнфПр="10.06.2024" КНД="1115131" НаимДокОпр="Документ об отгрузке товаров" НаимЭконСубСост="ООО Наша компания" ПоФактХЖ="Документ об отгрузке товаров" Функция="ДОП">
        <СвСчФакт ДатаДок="{$document->document_date->format('d.m.Y')}" НомерДок="{$document->number}">
            <СвПрод ОКПО="10000001">
                <ИдСв>
                    <СвЮЛУч ИННЮЛ="1000000001" КПП="100000001" НаимОрг="ООО Наша компания"/>
                </ИдСв>
            </СвПрод>
            <СвПокуп ОКПО="20000002">
                <ИдСв>
                    <СвЮЛУч ИННЮЛ="2000000002" КПП="200000002" НаимОрг="ООО Покупатель"/>
                </ИдСв>
            </СвПокуп>
            <ДенИзм КодОКВ="643" НаимОКВ="Российский рубль"/>
        </СвСчФакт>
        <Подписант Должн="ГЕНЕРАЛЬНЫЙ ДИРЕКТОР" СпосПодтПолном="1">
            <ФИО Имя="Иван" Отчество="Иванович" Фамилия="Иванов"/>
        </Подписант>
    </Документ>
</Файл>
XML;
    }
}
