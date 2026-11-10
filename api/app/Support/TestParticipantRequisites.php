<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;

/**
 * Полные (вымышленные) реквизиты тестовых участников: УК, Заказчиков
 * (ООО «Альфа», «Бетта», «Тест») и СТО (ООО «КУБ», «Овал», «СТО-партнёр»).
 *
 * ИНН, ОГРН и расчётные счета проходят контрольные суммы, БИК и
 * корреспондентские счета — реальные банковские. Заполняются только
 * пустые поля: значения, внесённые вручную, не перезаписываются.
 */
final class TestParticipantRequisites
{
    /** Логин тестового пользователя ЛК УК: по нему находится организация УК. */
    public const MANAGER_LOGIN = 'UK';

    private const MANAGER = [
        'short_name'            => 'ООО «АвтоРемХаб»',
        'inn'                   => '7700133898',
        'kpp'                   => '770001001',
        'ogrn'                  => '1177708386371',
        'legal_address'         => '123112, г. Москва, Пресненская наб., д. 12, офис 45',
        'actual_address'        => '123112, г. Москва, Пресненская наб., д. 12, офис 45',
        'postal_address'        => '123112, г. Москва, Пресненская наб., д. 12, офис 45',
        'director_name'         => 'Соколов Андрей Викторович',
        'contact'               => 'Морозова Елена Сергеевна, менеджер по работе с партнёрами',
        'phone'                 => '+7 495 123 45 67',
        'email'                 => 'info@autoremhub.example.ru',
        'bank_name'             => 'ПАО Сбербанк',
        'bik'                   => '044525225',
        'settlement_account'    => '40702810710433218196',
        'correspondent_account' => '30101810400000000225',
        'tax_regime'            => 'ОСН',
        'vat_rate'              => 20,
    ];

    /** @var array<string, array<string, mixed>> */
    private const CUSTOMERS = [
        'ООО "Альфа"' => [
            'short_name'            => 'ООО «Альфа»',
            'inn'                   => '7716155946',
            'kpp'                   => '771601001',
            'ogrn'                  => '1157707816188',
            'legal_address'         => '129344, г. Москва, ул. Енисейская, д. 1, стр. 3',
            'actual_address'        => '129344, г. Москва, ул. Енисейская, д. 1, стр. 3',
            'postal_address'        => '129344, г. Москва, ул. Енисейская, д. 1, стр. 3, а/я 18',
            'director_name'         => 'Кузнецов Игорь Петрович',
            'contact'               => 'Белова Ольга Николаевна, начальник автопарка',
            'phone'                 => '+7 495 765 43 21',
            'email'                 => 'fleet@alfa.example.ru',
            'bank_name'             => 'АО «Альфа-Банк»',
            'bik'                   => '044525593',
            'settlement_account'    => '40702810694026542351',
            'correspondent_account' => '30101810200000000593',
            'tax_regime'            => 'ОСН',
            'vat_rate'              => 20,
        ],
        'ООО "Бетта"' => [
            'short_name'            => 'ООО «Бетта»',
            'inn'                   => '5016475256',
            'kpp'                   => '501601001',
            'ogrn'                  => '1185053419286',
            'legal_address'         => '141070, Московская обл., г. Королёв, ул. Пионерская, д. 30',
            'actual_address'        => '141070, Московская обл., г. Королёв, ул. Пионерская, д. 30',
            'postal_address'        => '141070, Московская обл., г. Королёв, ул. Пионерская, д. 30',
            'director_name'         => 'Васильева Марина Александровна',
            'contact'               => 'Григорьев Павел Олегович, механик автопарка',
            'phone'                 => '+7 495 222 33 44',
            'email'                 => 'park@betta.example.ru',
            'bank_name'             => 'Банк ВТБ (ПАО)',
            'bik'                   => '044525187',
            'settlement_account'    => '40702810249593103413',
            'correspondent_account' => '30101810700000000187',
            'tax_regime'            => 'УСН «доходы минус расходы»',
            'vat_rate'              => 0,
        ],
        'ООО "Тест"' => [
            'short_name'            => 'ООО «Тест»',
            'inn'                   => '7852601815',
            'kpp'                   => '785201001',
            'ogrn'                  => '1167859083017',
            'legal_address'         => '191186, г. Санкт-Петербург, наб. реки Мойки, д. 42, литера А, пом. 7-Н',
            'actual_address'        => '191186, г. Санкт-Петербург, наб. реки Мойки, д. 42, литера А, пом. 7-Н',
            'postal_address'        => '191186, г. Санкт-Петербург, наб. реки Мойки, д. 42, литера А, пом. 7-Н',
            'director_name'         => 'Смирнов Алексей Николаевич',
            'contact'               => 'Фёдорова Наталья Игоревна, логист автопарка',
            'phone'                 => '+7 812 555 66 77',
            'email'                 => 'fleet@test-org.example.ru',
            'bank_name'             => 'Северо-Западный банк ПАО Сбербанк',
            'bik'                   => '044030653',
            'settlement_account'    => '40702810266131860913',
            'correspondent_account' => '30101810500000000653',
            'tax_regime'            => 'ОСН',
            'vat_rate'              => 20,
        ],
    ];

    /** @var array<string, array{organization: array<string, mixed>, station: array<string, mixed>, profile: array<string, mixed>}> */
    private const STATIONS = [
        'ООО "КУБ"' => [
            'organization' => [
                'short_name'            => 'ООО «КУБ»',
                'inn'                   => '7756413953',
                'kpp'                   => '775601001',
                'ogrn'                  => '1127737672424',
                'legal_address'         => '115280, г. Москва, ул. Ленинская Слобода, д. 19, стр. 2',
                'actual_address'        => '115280, г. Москва, ул. Ленинская Слобода, д. 19, стр. 2',
                'postal_address'        => '115280, г. Москва, ул. Ленинская Слобода, д. 19, стр. 2',
                'director_name'         => 'Павлов Дмитрий Сергеевич',
                'contact'               => 'Орлов Максим Юрьевич, мастер-приёмщик',
                'phone'                 => '+7 495 333 22 11',
                'email'                 => 'service@kub.example.ru',
                'bank_name'             => 'АО «ТБанк»',
                'bik'                   => '044525974',
                'settlement_account'    => '40702810532764835030',
                'correspondent_account' => '30101810900000000974',
                'tax_regime'            => 'ОСН',
                'vat_rate'              => 20,
            ],
            'station' => [
                'address'                => 'г. Москва, ул. Ленинская Слобода, д. 19, стр. 2',
                'contact'                => 'Орлов Максим Юрьевич, +74953332211',
                'rating'                 => 4.7,
                'agent_discount_percent' => 10,
            ],
            'profile' => [
                'mechanicalBays'              => 8,
                'lifts'                       => 6,
                'mechanicsCount'              => 10,
                'electriciansCount'           => 3,
                'electricianBays'             => 2,
                'alignmentBays'               => 1,
                'engineBays'                  => 2,
                'paintBooths'                 => 1,
                'frameRacks'                  => 1,
                'bodyworkBays'                => 2,
                'engineRepair'                => true,
                'automaticTransmissionRepair' => true,
                'manualTransmissionRepair'    => true,
                'steeringRackRepair'          => true,
                'bodyRepair'                  => true,
                'brakeDiscSkimming'           => true,
                'wheelAlignment'              => true,
                'tireService'                 => true,
                'tireStorage'                 => true,
                'evacuation'                  => false,
                'warrantyService'             => false,
                'reception'                   => true,
                'waitingArea'                 => true,
                'photoVideoFixation'          => true,
                'itAccounting'                => true,
                'insuranceFleetExperience'    => true,
                'trainingCertificates'        => true,
                'passengerCars'               => true,
                'lcv'                         => true,
                'trucks'                      => false,
                'slaDescription'              => 'Приёмка ТС в день обращения, дефектовка — до 4 часов, ТО — в течение 1 рабочего дня.',
                'vehicleRestrictions'         => 'Полная масса до 3,5 т, высота до 2,7 м.',
                'customerAmenities'           => 'Зона ожидания с Wi-Fi и кофе, парковка для клиентов.',
                'uniqueServices'              => 'Ремонт АКПП с гарантией 12 месяцев, развал-схождение 3D.',
                'yearsOperating'              => 12,
            ],
        ],
        'ООО "Овал"' => [
            'organization' => [
                'short_name'            => 'ООО «Овал»',
                'inn'                   => '5087101225',
                'kpp'                   => '508701001',
                'ogrn'                  => '1195069166973',
                'legal_address'         => '143005, Московская обл., г. Одинцово, Минское ш., д. 7',
                'actual_address'        => '143005, Московская обл., г. Одинцово, Минское ш., д. 7',
                'postal_address'        => '143005, Московская обл., г. Одинцово, Минское ш., д. 7',
                'director_name'         => 'Тихонов Роман Андреевич',
                'contact'               => 'Лебедева Анна Викторовна, администратор',
                'phone'                 => '+7 495 444 55 66',
                'email'                 => 'zakaz@oval.example.ru',
                'bank_name'             => 'АО «Райффайзенбанк»',
                'bik'                   => '044525700',
                'settlement_account'    => '40702810838849696532',
                'correspondent_account' => '30101810200000000700',
                'tax_regime'            => 'УСН «доходы»',
                'vat_rate'              => 0,
            ],
            'station' => [
                'address'                => 'Московская обл., г. Одинцово, Минское ш., д. 7',
                'contact'                => 'Лебедева Анна Викторовна, +74954445566',
                'rating'                 => 4.4,
                'agent_discount_percent' => 10,
            ],
            'profile' => [
                'mechanicalBays'              => 4,
                'lifts'                       => 4,
                'mechanicsCount'              => 5,
                'electriciansCount'           => 1,
                'electricianBays'             => 1,
                'alignmentBays'               => 1,
                'engineBays'                  => 1,
                'paintBooths'                 => 0,
                'frameRacks'                  => 0,
                'bodyworkBays'                => 0,
                'engineRepair'                => true,
                'automaticTransmissionRepair' => false,
                'manualTransmissionRepair'    => true,
                'steeringRackRepair'          => false,
                'bodyRepair'                  => false,
                'brakeDiscSkimming'           => true,
                'wheelAlignment'              => true,
                'tireService'                 => true,
                'tireStorage'                 => false,
                'evacuation'                  => true,
                'warrantyService'             => false,
                'reception'                   => true,
                'waitingArea'                 => false,
                'photoVideoFixation'          => true,
                'itAccounting'                => false,
                'insuranceFleetExperience'    => false,
                'trainingCertificates'        => true,
                'passengerCars'               => true,
                'lcv'                         => true,
                'trucks'                      => true,
                'slaDescription'              => 'Запись на следующий рабочий день, срочный ремонт — по согласованию.',
                'vehicleRestrictions'         => 'Грузовые ТС до 7,5 т только на шиномонтаж.',
                'customerAmenities'           => 'Бесплатная парковка.',
                'uniqueServices'              => 'Эвакуатор собственный, выезд по Одинцовскому району.',
                'yearsOperating'              => 6,
            ],
        ],
        'СТО-партнёр' => [
            'organization' => [
                'short_name'            => 'ООО «СТО-партнёр»',
                'inn'                   => '7790996039',
                'kpp'                   => '779001001',
                'ogrn'                  => '1147708246289',
                'legal_address'         => '109428, г. Москва, Рязанский пр-т, д. 24, корп. 2',
                'actual_address'        => '109428, г. Москва, Рязанский пр-т, д. 24, корп. 2',
                'postal_address'        => '109428, г. Москва, Рязанский пр-т, д. 24, корп. 2',
                'director_name'         => 'Захаров Виктор Степанович',
                'contact'               => 'Николаев Артём Валерьевич, мастер-приёмщик',
                'phone'                 => '+7 495 666 77 88',
                'email'                 => 'service@sto-partner.example.ru',
                'bank_name'             => 'Филиал «Центральный» Банка ВТБ (ПАО)',
                'bik'                   => '044525411',
                'settlement_account'    => '40702810019482199351',
                'correspondent_account' => '30101810145250000411',
                'tax_regime'            => 'ОСН',
                'vat_rate'              => 20,
            ],
            'station' => [
                'address'                => 'г. Москва, Рязанский пр-т, д. 24, корп. 2',
                'contact'                => 'Николаев Артём Валерьевич, +74956667788',
                'rating'                 => 4.5,
                'agent_discount_percent' => 10,
            ],
            'profile' => [
                'mechanicalBays'              => 6,
                'lifts'                       => 5,
                'mechanicsCount'              => 7,
                'electriciansCount'           => 2,
                'electricianBays'             => 1,
                'alignmentBays'               => 1,
                'engineBays'                  => 1,
                'paintBooths'                 => 1,
                'frameRacks'                  => 0,
                'bodyworkBays'                => 1,
                'engineRepair'                => true,
                'automaticTransmissionRepair' => false,
                'manualTransmissionRepair'    => true,
                'steeringRackRepair'          => true,
                'bodyRepair'                  => true,
                'brakeDiscSkimming'           => false,
                'wheelAlignment'              => true,
                'tireService'                 => true,
                'tireStorage'                 => true,
                'evacuation'                  => false,
                'warrantyService'             => true,
                'reception'                   => true,
                'waitingArea'                 => true,
                'photoVideoFixation'          => true,
                'itAccounting'                => true,
                'insuranceFleetExperience'    => true,
                'trainingCertificates'        => false,
                'passengerCars'               => true,
                'lcv'                         => true,
                'trucks'                      => false,
                'slaDescription'              => 'Приёмка в день обращения, согласование сметы — до 2 часов.',
                'vehicleRestrictions'         => 'Легковые и LCV полной массой до 3,5 т.',
                'customerAmenities'           => 'Зона ожидания, Wi-Fi, кофе, подменный автомобиль по запросу.',
                'uniqueServices'              => 'Гарантийное ТО с сохранением гарантии производителя.',
                'yearsOperating'              => 9,
            ],
        ],
    ];

    /**
     * @return array<int, string> Названия организаций, у которых что-то изменилось.
     */
    public static function apply(int $tenantId): array
    {
        $changed = [];

        $managerId = User::query()
            ->where('login', self::MANAGER_LOGIN)
            ->whereHas('organization', static fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('kind', OrganizationKind::Manager->value))
            ->value('organization_id');
        $manager = $managerId !== null ? Organization::query()->find($managerId) : null;

        if ($manager !== null && self::fill($manager, self::MANAGER)) {
            $changed[] = $manager->name;
        }

        foreach (self::CUSTOMERS as $name => $attributes) {
            $organization = self::organization($tenantId, OrganizationKind::Customer, $name);

            if ($organization !== null && self::fill($organization, $attributes)) {
                $changed[] = $name;
            }
        }

        foreach (self::STATIONS as $name => $spec) {
            $organization = self::organization($tenantId, OrganizationKind::Station, $name)
                ?? Organization::query()->whereKey(
                    Station::query()
                        ->where('tenant_id', $tenantId)
                        ->get()
                        ->first(static fn (Station $station): bool => self::sameName($station->name, $name))
                        ?->organization_id,
                )->first();

            if ($organization === null) {
                continue;
            }

            $dirty = self::fill($organization, $spec['organization']);
            $station = Station::query()->where('organization_id', $organization->id)->first();

            if ($station !== null) {
                $stationDirty = false;

                foreach ($spec['station'] as $attribute => $value) {
                    if (self::isEmpty($station->getAttribute($attribute))) {
                        $station->setAttribute($attribute, $value);
                        $stationDirty = true;
                    }
                }

                $profile = $station->profile ?? [];
                foreach ($spec['profile'] as $field => $value) {
                    if (!array_key_exists($field, $profile) || self::isEmpty($profile[$field])) {
                        $profile[$field] = $value;
                        $stationDirty = true;
                    }
                }

                if ($stationDirty) {
                    $station->profile = $profile;
                    $station->save();
                    $dirty = true;
                }
            }

            if ($dirty) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    private static function organization(int $tenantId, OrganizationKind $kind, string $name): ?Organization
    {
        return Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', $kind->value)
            ->get()
            ->first(static fn (Organization $organization): bool => self::sameName($organization->name, $name));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function fill(Organization $organization, array $attributes): bool
    {
        foreach ($attributes as $attribute => $value) {
            if (self::isEmpty($organization->getAttribute($attribute))) {
                $organization->setAttribute($attribute, $value);
            }
        }

        if (!$organization->isDirty()) {
            return false;
        }

        $organization->save();

        return true;
    }

/** Сравнение названий без учёта регистра, пробелов и вида кавычек. */
    private static function sameName(string $left, string $right): bool
    {
        $normalize = static fn (string $value): string => mb_strtolower(
            (string) preg_replace(['/[«»“”„"\']/u', '/\s+/u'], ['"', ' '], trim($value)),
        );

        return $normalize($left) === $normalize($right);
    }

        private static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
