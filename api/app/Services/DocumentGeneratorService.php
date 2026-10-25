<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;

final class DocumentGeneratorService
{
    /**
     * Генерирует простейший PDF с текстом (заглушка)
     */
    public function generatePdf(string $title, array $data = []): string
    {
        // Для тестов просто возвращаем base64-строку,
        // которую ЭДО-провайдеры смогут принять как "файл".
        // В реальности здесь должен быть DomPDF / mPDF / Snappy.

        $text = "Документ: {$title}\n";
        foreach ($data as $key => $value) {
            $text .= "{$key}: {$value}\n";
        }

        // Возвращаем просто строку в base64 (для отладки)
        // Если хотите настоящий PDF — используйте DomPDF.
        return base64_encode($text);
    }

    public function generateAgentContract(Organization $customer): string
    {
        return $this->generatePdf('Агентский договор', [
            'Заказчик' => $customer->name,
            'ИНН' => $customer->inn,
            'Дата' => now()->toDateString(),
        ]);
    }
}
