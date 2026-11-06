{{-- Акт передачи ТС от СТО Заказчику = «Лист приёма-передачи ТС» (направление out). --}}
@include('pdf.handover_sheet', ['sheet' => ($payload['sheet'] ?? []) + [
    'direction'     => 'out',
    'number'        => $doc->number,
    'date'          => $doc->document_date?->toDateString(),
    'requestNumber' => $payload['request']['number'] ?? '',
    'workOrder'     => $payload['workOrder'] ?? null,
    'acceptanceNumber' => $payload['acceptanceAct'] ?? null,
    'customer'      => $payload['buyer'] ?? [],
    'station'       => $payload['seller'] ?? [],
    'driver'        => $payload['driver'] ?? [],
    'powerOfAttorney' => $payload['powerOfAttorney'] ?? [],
    'vehicle'       => $payload['vehicle'] ?? [],
]])
