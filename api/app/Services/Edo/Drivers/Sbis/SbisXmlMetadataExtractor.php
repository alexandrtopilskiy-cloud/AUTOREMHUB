<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use RuntimeException;
use SimpleXMLElement;

class SbisXmlMetadataExtractor
{
    public static function extractUpdMetadata(string $xmlContent): array
    {
        libxml_use_internal_errors(true);

        // 1. Убираем BOM
        $xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent);

        // 2. Убираем нулевые байты (следы UTF-16) и прочий мусор
        // Оставляем только символы от 0x20 до 0x7E (латиница/цифры), а также кириллицу (0xC0-0xFF)
        // и базовые символы XML (< > / = " и т.д.)
        $xmlContent = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xC0-\xFF\x{0400}-\x{04FF}]/u', '', $xmlContent);

        // 3. Если это все еще похоже на UTF-16, конвертируем
        // (Иногда VS Code сохраняет как UTF-16 с BOM, но мы его уже убрали)
        if (strpos($xmlContent, "\x00") !== false) {
            $xmlContent = mb_convert_encoding($xmlContent, 'UTF-8', 'UTF-16');
        }

        // 4. Отрезаем всё до первого тега <
        $xmlContent = trim($xmlContent);
        $firstTag = strpos($xmlContent, '<');
        if ($firstTag !== false) {
            $xmlContent = substr($xmlContent, $firstTag);
        }

        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $errors = array_map(fn($e) => "Line {$e->line}: {$e->message}", libxml_get_errors());
            throw new RuntimeException('Не удалось распарсить XML УПД: ' . implode(' | ', $errors));
        }

        $document = $xml->Документ;
        $svSchFact = $document->СвСчФакт;

        return [
            'Название'         => 'Универсальный передаточный документ',
            'Тип'              => 'ДокОтгрИсх',
            'Подтип'           => 'УПД',
            'Версия'           => (string)$xml['ВерсФорм'],
            'ПодВерсия'        => (string)$document['КНД'],
            'Дата'             => (string)$svSchFact['ДатаДок'],
            'Номер'            => (string)$svSchFact['НомерДок'],
        ];
    }
}