<?php

declare(strict_types=1);

namespace App\Enums\Edo;

enum EdoDocumentType: string
{
    case UpdSf         = 'upd_sf';          // УПД.СЧФ (СТО ↔ УК, УК ↔ Заказчик)
    case UpdDop        = 'upd_dop';         // УПД.ДОП (акт-торг)
    case UpdSfDop      = 'upd_sf_dop';      // УПД.СЧФ.ДОП
    case UcdCsf        = 'ucd_csf';         // УКД.КСЧФ
    case Invoice       = 'invoice';         // Счёт-фактура
    case ActWorks      = 'act_works';       // Акт выполненных работ
    case WorkOrder     = 'work_order';      // Заказ-наряд
    case Contract      = 'contract';        // Договор
    case AgencyContract = 'agency_contract';// Агентский договор
    case AcceptanceAct = 'acceptance_act';  // Акт приёма-передачи авто
    case PowerOfAttorney = 'power_of_attorney'; // Доверенность на управление авто
    case NonFormalized = 'non_formalized';  // Прочий неформализованный документ

    public function label(): string
    {
        return match ($this) {
            self::UpdSf            => 'УПД. Счёт-фактура',
            self::UpdDop           => 'УПД. Документ об отгрузке',
            self::UpdSfDop         => 'УПД. Счёт-фактура и документ об отгрузке',
            self::UcdCsf           => 'УКД. Корректировочный счёт-фактура',
            self::Invoice          => 'Счёт-фактура',
            self::ActWorks         => 'Акт выполненных работ',
            self::WorkOrder        => 'Заказ-наряд',
            self::Contract         => 'Договор',
            self::AgencyContract   => 'Агентский договор',
            self::AcceptanceAct    => 'Акт приёма-передачи авто',
            self::PowerOfAttorney  => 'Доверенность на управление авто',
            self::NonFormalized    => 'Неформализованный документ',
        };
    }

    /**
     * Код типа документа в API СберКоруса (поле TypeCode / DocumentTypeCode).
     * Для неформализованных — общий код «DOCUMENT» (см. TransportDocumentType).
     */
    public function sberKorusCode(): string
    {
        return match ($this) {
            self::UpdSf            => 'UPD_SF',
            self::UpdDop           => 'UPD_DOP',
            self::UpdSfDop         => 'UPD_SF_DOP',
            self::UcdCsf           => 'UCD_CSF',
            self::Invoice          => 'SF',
            self::ActWorks         => 'ACT',
            self::WorkOrder        => 'ORDER_REQUEST',
            self::Contract         => 'CONTRACT',
            self::AgencyContract   => 'AGREEMENT',
            self::AcceptanceAct    => 'ACT_MH1', // акт приёма-передачи ТМЦ (МХ-1) — ближайший аналог
            self::PowerOfAttorney  => 'WARRANT', // см. Warrant в структурах API
            self::NonFormalized    => 'DOCUMENT',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}