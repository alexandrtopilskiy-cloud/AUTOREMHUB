<?php

declare(strict_types=1);

// ОПФ определяет реквизиты ЮЛ/ИП; система налогообложения задаётся отдельно.
return [
    'ooo' => ['name' => 'ООО', 'individual' => false],
    'ip' => ['name' => 'ИП', 'individual' => true],
    'ao' => ['name' => 'АО', 'individual' => false],
    'pao' => ['name' => 'ПАО', 'individual' => false],
    'fgup' => ['name' => 'ФГУП', 'individual' => false],
    'gup' => ['name' => 'ГУП', 'individual' => false],
    'mup' => ['name' => 'МУП', 'individual' => false],
    'institution' => ['name' => 'Учреждение', 'individual' => false],
    'ano' => ['name' => 'Автономная некоммерческая организация', 'individual' => false],
    'fund' => ['name' => 'Фонд', 'individual' => false],
    'association' => ['name' => 'Ассоциация (союз)', 'individual' => false],
    'production_cooperative' => ['name' => 'Производственный кооператив', 'individual' => false],
    'consumer_cooperative' => ['name' => 'Потребительский кооператив', 'individual' => false],
    'partnership' => ['name' => 'Полное товарищество', 'individual' => false],
    'limited_partnership' => ['name' => 'Товарищество на вере', 'individual' => false],
    'economic_partnership' => ['name' => 'Хозяйственное партнёрство', 'individual' => false],
    'kfh' => ['name' => 'КФХ — юридическое лицо', 'individual' => false],
    'kfh_ip' => ['name' => 'Глава КФХ — ИП', 'individual' => true],
    'tsn' => ['name' => 'Товарищество собственников недвижимости', 'individual' => false],
    'public_organization' => ['name' => 'Общественная организация', 'individual' => false],
    'religious_organization' => ['name' => 'Религиозная организация', 'individual' => false],
    'other' => ['name' => 'Иная организационно-правовая форма ЮЛ', 'individual' => false],
];
