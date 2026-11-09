<?php

declare(strict_types=1);

namespace App\Services\Edo\Xml;

use App\DTO\EdoPartyInfo;
use App\DTO\EdoSignerDto;
use DOMDocument;
use Illuminate\Support\Str;

final class AgentReportXmlBuilder
{
    private string $lastIdFile = '';

    public function build(
        string $number,
        \DateTimeImmutable $date,
        EdoPartyInfo $agent, // УК
        EdoPartyInfo $principal, // Заказчик
        string $amount,
        string $vatAmount,
        EdoSignerDto $signer,
    ): string {
        $idFile = $this->generateFileId($agent, $principal, $date);
        $this->lastIdFile = $idFile;

        $xml = $this->render($number, $date, $idFile, $agent, $principal, $amount, $vatAmount, $signer);

        return $xml;
    }

    public function getLastGeneratedIdFile(): string
    {
        return $this->lastIdFile;
    }

    private function generateFileId(EdoPartyInfo $agent, EdoPartyInfo $principal, \DateTimeImmutable $date): string
    {
        // Для неформализованного документа префикс ON_NSCHFDOPPR не подходит.
        // Используем простой префикс ON_AGENT_REPORT
        $prefix = 'ON_AGENT_REPORT';
        $guid = Str::uuid()->toString();
        $dateStr = $date->format('Ymd');

        return sprintf(
            '%s_%s_%s_%s_%s',
            $prefix,
            $principal->ediCode ?? 'UNKNOWN',
            $agent->ediCode ?? 'UNKNOWN',
            $dateStr,
            $guid
        );
    }

    private function render(
        string $number,
        \DateTimeImmutable $date,
        string $idFile,
        EdoPartyInfo $agent,
        EdoPartyInfo $principal,
        string $amount,
        string $vatAmount,
        EdoSignerDto $signer,
    ): string {
        $dom = new DOMDocument('1.0', 'windows-1251');
        $dom->formatOutput = true;

        $root = $dom->createElement('AgentReport');
        $root->setAttribute('ИдФайл', $idFile);
        $root->setAttribute('НомерДок', $number);
        $root->setAttribute('ДатаДок', $date->format('d.m.Y'));
        $dom->appendChild($root);

        // Агент (УК)
        $agentNode = $dom->createElement('Agent');
        $agentNode->setAttribute('НаимОрг', $agent->name);
        $agentNode->setAttribute('ИННЮЛ', $agent->inn);
        $agentNode->setAttribute('КПП', $agent->kpp);
        $root->appendChild($agentNode);

        // Принципал (Заказчик)
        $principalNode = $dom->createElement('Principal');
        $principalNode->setAttribute('НаимОрг', $principal->name);
        $principalNode->setAttribute('ИННЮЛ', $principal->inn);
        $principalNode->setAttribute('КПП', $principal->kpp);
        $root->appendChild($principalNode);

        // Сумма вознаграждения
        $amountNode = $dom->createElement('Amount', $amount);
        $amountNode->setAttribute('Валюта', '643');
        $amountNode->setAttribute('НДС', $vatAmount);
        $root->appendChild($amountNode);

        // Подписант
        $signerNode = $dom->createElement('Signer');
        $signerNode->setAttribute('Должность', $signer->title);
        $signerNode->setAttribute('ФИО', trim("{$signer->lastName} {$signer->firstName} {$signer->middleName}"));
        $root->appendChild($signerNode);

        return ltrim($dom->saveXML());
    }
}
