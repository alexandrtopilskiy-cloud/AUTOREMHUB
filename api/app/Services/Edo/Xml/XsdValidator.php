<?php

declare(strict_types=1);

namespace App\Services\Edo\Xml;

use App\Exceptions\EdoException;

/**
 * Валидатор XML против XSD-схемы средствами libxml.
 *
 * Используется для самопроверки сгенерированных XML УПД/УКД перед
 * отправкой в ЭДО.Поток. Оператор отклонит невалидный XML — поэтому
 * проверяем локально.
 */
final class XsdValidator
{
    private const PROVIDER_LABEL = 'edoru';

    /**
     * Валидирует XML-строку против XSD-файла.
     *
     * @throws EdoException если XML невалиден или XSD-файл не найден
     */
    public function validate(string $xml, string $xsdPath): void
    {
        if (!is_file($xsdPath)) {
            throw EdoException::remoteError(
                self::PROVIDER_LABEL,
                'XSD_NOT_FOUND',
                "XSD-схема не найдена: {$xsdPath}",
            );
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            // DOMDocument::loadXML() ожидает UTF-8, а XML у нас в windows-1251.
            // Конвертируем во внутреннее представление UTF-8 для парсинга.
            $utf8 = mb_convert_encoding($xml, 'UTF-8', 'windows-1251');
            // Убираем декларацию с windows-1251, чтобы не сбивать парсер.
            $utf8 = preg_replace('/<\?xml[^?]*\?>/', '<?xml version="1.0" encoding="UTF-8"?>', $utf8, 1);

            $document = new \DOMDocument('1.0', 'UTF-8');
            $document->preserveWhiteSpace = false;

            if (! $document->loadXML($utf8, LIBXML_NONET)) {
                $errors = libxml_get_errors();
                throw EdoException::remoteError(
                    self::PROVIDER_LABEL,
                    'XML_PARSE_ERROR',
                    'Не удалось разобрать XML: ' . $this->formatErrors($errors),
                );
            }

            if (! $document->schemaValidate($xsdPath)) {
                $errors = libxml_get_errors();
                throw EdoException::remoteError(
                    self::PROVIDER_LABEL,
                    'XSD_VALIDATION_FAILED',
                    "XML не проходит XSD: \n" . $this->formatErrors($errors),
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  array<int, \LibXMLError>  $errors
     */
    private function formatErrors(array $errors): string
    {
        $lines = [];

        foreach ($errors as $error) {
            $lines[] = sprintf(
                'line %d, col %d: [%d] %s',
                $error->line,
                $error->column,
                $error->code,
                trim($error->message),
            );
        }

        return $lines === [] ? '(ошибки не детализированы)' : implode("\n", $lines);
    }
}
