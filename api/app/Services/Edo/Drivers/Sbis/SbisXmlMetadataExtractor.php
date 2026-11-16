<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use RuntimeException;

class SbisXmlMetadataExtractor
{
    public static function extractUpdMetadata(string $xmlContent): array
    {
        libxml_use_internal_errors(true);

        $xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent);

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
            'Дата'             => (string)$svSchFact['ДатаДок'],
            'Номер'            => (string)$svSchFact['НомерДок'],
        ];
    }
}