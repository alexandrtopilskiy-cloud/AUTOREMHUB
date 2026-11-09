<?php

declare(strict_types=1);

namespace App\Services\Edo;

use DOMDocument;
use RuntimeException;

final class EdoXmlValidator
{
    private const XSD_STORAGE_PATH = 'app/edo/xsd/';

    /**
     * Валидирует XML по схеме XSD.
     */
    public function validate(string $xmlContent, string $xsdFileName): void
    {
        $xsdPath = storage_path(self::XSD_STORAGE_PATH . $xsdFileName);

        if (!file_exists($xsdPath)) {
            throw new RuntimeException("XSD-схема не найдена по пути: {$xsdPath}");
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!$dom->loadXML($xmlContent)) {
            throw new RuntimeException('Не удалось загрузить XML для валидации.');
        }

        libxml_use_internal_errors(true);

        if (!$dom->schemaValidate($xsdPath)) {
            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = "Line {$error->line}: " . trim($error->message);
            }
            libxml_clear_errors();

            throw new RuntimeException("Ошибка валидации XML по XSD:\n" . implode("\n", $errors));
        }
    }
}
