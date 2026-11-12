<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogImportController;
use App\Http\Controllers\ChangeRegistryController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\DiagnosticController;
use App\Http\Controllers\DocumentLockController;
use App\Http\Controllers\EdoDocumentController;
use App\Http\Controllers\EdoSettingsController;
use App\Http\Controllers\ClosingDocumentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationPersonController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PartManufacturerController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\RequestChatController;
use App\Http\Controllers\RepairRequestController;
use App\Http\Controllers\WorkChangeController;
use App\Http\Controllers\TimeNormController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleCatalogController;
use App\Http\Controllers\VehicleReferenceController;
use App\Http\Controllers\WorkCatalogImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Глобальные паттерны параметров
|--------------------------------------------------------------------------
|
| `{id}` в заявках может быть UUID или номером вида ZR-000001.
| Задаём паттерн один раз, чтобы не повторять ->where() на каждой группе.
|
*/

Route::pattern(
    'id',
    '(?:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}|ZR-[0-9]{6,})',
);

/*
|--------------------------------------------------------------------------
| Публичные маршруты
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['login.origin', 'throttle:login'])
    ->name('auth.login');

/*
|--------------------------------------------------------------------------
| Защищённые маршруты (требуют аутентификации)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    /* ─── Сессия ─── */

    Route::get('/session', [AuthController::class, 'session'])->name('auth.session');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    /* ─── Блокировки совместного открытия ─── */

    Route::post('/document-locks', [DocumentLockController::class, 'acquire'])->name('document-locks.acquire');
    Route::post('/document-locks/{id}/heartbeat', [DocumentLockController::class, 'heartbeat'])->whereUuid('id')->name('document-locks.heartbeat');
    Route::delete('/document-locks/{id}', [DocumentLockController::class, 'release'])->whereUuid('id')->name('document-locks.release');

    /* ─── Заявки на ремонт ─── */

    Route::prefix('requests')->name('requests.')->group(function (): void {
        Route::get('/', [RepairRequestController::class, 'index'])->name('index');
        Route::post('/', [RepairRequestController::class, 'store'])->name('store');
        Route::post('/drafts/delete', [RepairRequestController::class, 'destroyDrafts'])->name('drafts.destroy');

        Route::get('/{id}', [RepairRequestController::class, 'show'])->name('show');
        Route::patch('/{id}', [RepairRequestController::class, 'update'])->name('update');
        Route::delete('/{id}', [RepairRequestController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/next', [RepairRequestController::class, 'next'])->name('next');
        Route::post('/{id}/return', [RepairRequestController::class, 'returnBack'])->name('return');
        Route::post('/{id}/station-decision', [RepairRequestController::class, 'stationDecision'])->name('station-decision');
        Route::post('/{id}/customer-slot-decision', [RepairRequestController::class, 'customerSlotDecision'])->name('customer-slot-decision');
        Route::post('/{id}/acceptance-act', [RepairRequestController::class, 'acceptVehicle'])->name('acceptance-act');
        Route::post('/{id}/manager-parts/accept', [RepairRequestController::class, 'acceptManagerParts'])->name('manager-parts.accept');
        Route::post('/{id}/work-change/submit', [WorkChangeController::class, 'submit'])->name('work-change.submit');
        Route::post('/{id}/work-change/decision', [WorkChangeController::class, 'decide'])->name('work-change.decision');
        Route::post('/{id}/work-change/discard', [WorkChangeController::class, 'discard'])->name('work-change.discard');
        Route::get('/{id}/history', [RepairRequestController::class, 'history'])->name('history');
        Route::get('/{id}/work-order', [RepairRequestController::class, 'workOrder'])->name('workOrder');
        Route::get('/{id}/chat', [RequestChatController::class, 'show'])->name('chat.show');
        Route::post('/{id}/chat', [RequestChatController::class, 'create'])->name('chat.create');
        Route::post('/{id}/chat/messages', [RequestChatController::class, 'send'])->name('chat.send');
        Route::post('/{id}/chat/read', [RequestChatController::class, 'read'])->name('chat.read');
    });

    Route::post('/recommendations/restore', [RecommendationController::class, 'restoreMany']);
    Route::get('/recommendations', [RecommendationController::class, 'index']);
    Route::post('/requests/{id}/recommendations', [RecommendationController::class, 'defer']);
    Route::post('/recommendations/{id}/restore', [RecommendationController::class, 'restore'])->whereUuid('id');
    Route::post('/recommendations/{id}/delete-items', [RecommendationController::class, 'deleteItems'])->whereUuid('id');

    Route::get('/chats/summary', [RequestChatController::class, 'summary'])->name('chats.summary');
    Route::get('/chat-files/{fileId}/download', [RequestChatController::class, 'download'])->whereUuid('fileId')->name('chat-files.download');

    /* ─── Уведомления ─── */

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');

    /* ─── Справочники ─── */

    Route::prefix('catalogs')->name('catalogs.')->group(function (): void {
        Route::post('/part-manufacturers/{id}/decision', [PartManufacturerController::class, 'review'])->whereUuid('id');
        Route::get('/', [CatalogController::class, 'index'])->name('index');
        Route::get('/history', [CatalogController::class, 'history'])->name('history');
        Route::post('/people', [OrganizationPersonController::class, 'store'])->name('people.store');
        Route::patch('/people/{id}', [OrganizationPersonController::class, 'update'])->whereUuid('id')->name('people.update');
        Route::delete('/people/{id}', [OrganizationPersonController::class, 'destroy'])->whereUuid('id')->name('people.destroy');
        Route::post('/vehicles', [VehicleCatalogController::class, 'store'])->name('vehicles.store');
        Route::patch('/vehicles/{id}', [VehicleCatalogController::class, 'update'])->whereUuid('id')->name('vehicles.update');
        Route::delete('/vehicles/{id}', [VehicleCatalogController::class, 'destroy'])->whereUuid('id')->name('vehicles.destroy');
        Route::post('/vehicle-approvals/{id}/decision', [VehicleCatalogController::class, 'review'])->whereUuid('id')->name('vehicle-approvals.review');
        Route::post('/time-norms/import', [CatalogController::class, 'importTimeNorms'])->name('time-norms.import');
        Route::post('/work-catalog/import/preview', [WorkCatalogImportController::class, 'preview'])->name('work-catalog.import.preview');
        Route::post('/work-catalog/import/apply', [WorkCatalogImportController::class, 'apply'])->name('work-catalog.import.apply');
        Route::get('/{kind}/import/template', [CatalogImportController::class, 'template'])->name('import.template');
        Route::post('/{kind}/import', [CatalogImportController::class, 'store'])->name('import.store');
        Route::post('/{kind}', [CatalogController::class, 'store'])->name('store');
        Route::patch('/{id}', [CatalogController::class, 'update'])->whereUuid('id')->name('update');
    });

    Route::get('/change-registry', [ChangeRegistryController::class, 'index'])->name('change-registry.index');

    Route::get('/reference-data', [CatalogController::class, 'reference'])->name('reference-data');
    Route::get('/time-norms/resolve', [TimeNormController::class, 'resolve'])->name('time-norms.resolve');

    /* ─── Справочник марок и моделей ─── */

    Route::prefix('vehicle-reference')->name('vehicle-reference.')->group(function (): void {
        Route::get('selection', [VehicleReferenceController::class, 'selection']);
        Route::post('/catalog', [VehicleReferenceController::class, 'storeCatalog']);
        Route::get('/catalog', [VehicleReferenceController::class, 'catalog'])->name('catalog');
        Route::get('/makes', [VehicleReferenceController::class, 'makes'])->name('makes');
        Route::get('/models', [VehicleReferenceController::class, 'models'])->name('models');
        Route::get('/generations', [VehicleReferenceController::class, 'generations'])->name('generations');
        Route::get('/engines', [VehicleReferenceController::class, 'engines'])->name('engines');
        Route::get('/transmissions', [VehicleReferenceController::class, 'transmissions'])->name('transmissions');
        Route::get('/drives', [VehicleReferenceController::class, 'drives'])->name('drives');
        Route::post('/models', [VehicleReferenceController::class, 'store'])->name('models.store');
    });

    /* ─── Автомобили ─── */

    Route::prefix('vehicles')->name('vehicles.')->group(function (): void {
        Route::get('/', [VehicleController::class, 'index'])->name('index');
        Route::get('/lookup', [VehicleController::class, 'lookup'])->name('lookup');

        Route::whereUuid('id')->group(function (): void {
            Route::get('/{id}', [VehicleController::class, 'show'])->name('show');
            Route::get('/{id}/statistics', [VehicleController::class, 'statistics'])->name('statistics');
            Route::get('/{id}/contacts', [VehicleController::class, 'contacts'])->name('contacts');
            Route::get('/{id}/drivers', [VehicleController::class, 'drivers'])->name('drivers');
        });
    });

    /* ─── Вложения ─── */

    Route::post('/requests/{id}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');

    Route::get('/attachments/{attachmentId}/download', [AttachmentController::class, 'download'])->whereUuid('attachmentId')->name('attachments.download');

    Route::get('/attachments/{attachmentId}/url', [AttachmentController::class, 'url'])->whereUuid('attachmentId')->name('attachments.url');

    /* ─── Диагностика ─── */

    Route::post('/requests/{id}/diagnostics', [DiagnosticController::class, 'store'])->name('diagnostics.store');

    /* ─── Запчасти ─── */

    Route::prefix('requests/{id}/parts')->name('parts.')->group(function (): void {
        Route::post('/', [PartController::class, 'store'])->name('store');

        Route::whereUuid('partId')->group(function (): void {
            Route::patch('/{partId}', [PartController::class, 'update'])->name('update');
            Route::post('/{partId}/remove', [PartController::class, 'remove'])->name('remove');
            Route::post('/{partId}/status', [PartController::class, 'status'])->name('status');
            Route::post('/{partId}/movements', [PartController::class, 'movement'])->name('movements');
        });
    });

    /* ─── Закрытие ЗР: акт передачи ТС, документы, оплаты ─── */

    Route::prefix('requests/{id}')->name('closing.')->group(function (): void {
        Route::post('/documents/generate', [ClosingDocumentController::class, 'generate'])->name('documents.generate');
        Route::post('/transfer-act/signed', [ClosingDocumentController::class, 'signTransferAct'])->name('transfer-act.signed');
        Route::get('/handover-sheet/{direction}', [ClosingDocumentController::class, 'handoverSheet'])->whereIn('direction', ['in', 'out'])->name('handover-sheet');
        Route::post('/payments/customer', [ClosingDocumentController::class, 'customerPayment'])->name('payments.customer');
        Route::post('/payments/station', [ClosingDocumentController::class, 'stationPayment'])->name('payments.station');
        Route::delete('/payments/customer', [ClosingDocumentController::class, 'deleteCustomerPayment'])->name('payments.customer.delete');
        Route::delete('/payments/station', [ClosingDocumentController::class, 'deleteStationPayment'])->name('payments.station.delete');
    });

    /* ─── ЭДО ─── */

    // Меню «Разработчик» (ЛК УК): удаление ЗР со всеми файлами и связанными записями.
    Route::prefix('developer')->name('developer.')->group(function (): void {
        Route::get('/requests', [DeveloperController::class, 'requests'])->name('requests.index');
        Route::post('/requests/delete', [DeveloperController::class, 'deleteRequests'])->name('requests.delete');
    });

    Route::get('/request-documents/{documentId}/pdf', [ClosingDocumentController::class, 'pdf'])->whereUuid('documentId')->name('request-documents.pdf');
    Route::get('/reports/requests', [ReportController::class, 'requests'])->name('reports.requests');
    Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cash-flow');

    // ─── Склад ───
    Route::prefix('warehouse')->name('warehouse.')->group(function (): void {
        Route::get('/settings', [WarehouseController::class, 'settings'])->name('settings');
        Route::put('/settings/markup', [WarehouseController::class, 'saveMarkup'])->name('settings.markup');
        Route::put('/settings/cost-method', [WarehouseController::class, 'saveCostMethod'])->name('settings.cost-method');
        Route::post('/warehouses', [WarehouseController::class, 'storeWarehouse'])->name('warehouses.store');
        Route::patch('/warehouses/{id}', [WarehouseController::class, 'updateWarehouse'])->whereUuid('id')->name('warehouses.update');
        Route::delete('/warehouses/{id}', [WarehouseController::class, 'destroyWarehouse'])->whereUuid('id')->name('warehouses.destroy');
        Route::get('/counterparties', [WarehouseController::class, 'counterparties'])->name('counterparties');
        Route::get('/balance', [WarehouseController::class, 'balance'])->name('balance');
        Route::get('/documents', [WarehouseController::class, 'index'])->name('documents.index');
        Route::post('/documents', [WarehouseController::class, 'store'])->name('documents.store');
        Route::get('/documents/{id}', [WarehouseController::class, 'show'])->whereUuid('id')->name('documents.show');
        Route::patch('/documents/{id}', [WarehouseController::class, 'update'])->whereUuid('id')->name('documents.update');
        Route::delete('/documents/{id}', [WarehouseController::class, 'destroy'])->whereUuid('id')->name('documents.destroy');
        Route::post('/documents/{id}/post', [WarehouseController::class, 'post'])->whereUuid('id')->name('documents.post');
        Route::post('/documents/{id}/unpost', [WarehouseController::class, 'unpost'])->whereUuid('id')->name('documents.unpost');
        Route::post('/documents/{id}/payments', [WarehouseController::class, 'storePayment'])->whereUuid('id')->name('documents.payments.store');
        Route::delete('/documents/{id}/payments/{paymentId}', [WarehouseController::class, 'destroyPayment'])->whereUuid('id')->whereUuid('paymentId')->name('documents.payments.destroy');
    });
    Route::get('/requests/{id}/stock', [WarehouseController::class, 'requestStock'])->name('requests.stock');
    Route::get('/warehouse/station-stock', [WarehouseController::class, 'requestStock'])->defaults('id', 'new')->name('warehouse.station-stock');

    Route::prefix('edo')->name('edo.')->group(function (): void {
        Route::post('/connect', [EdoSettingsController::class, 'connect']);
        Route::get('/documents', [EdoDocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{uuid}', [EdoDocumentController::class, 'show'])->whereUuid('uuid')->name('documents.show');
        Route::post('/documents/{uuid}/sign-and-send', [EdoDocumentController::class, 'signAndSend'])->whereUuid('uuid')->name('documents.signAndSend');
        Route::post('/documents/{uuid}/sync', [EdoDocumentController::class, 'sync'])->whereUuid('uuid')->name('documents.sync');
        Route::get('/documents/{uuid}/content', [EdoDocumentController::class, 'content'])->whereUuid('uuid')->name('documents.content');
        Route::get('/documents/{uuid}/pdf', [EdoDocumentController::class, 'pdf'])->whereUuid('uuid')->name('documents.pdf');
        Route::post('/documents/{uuid}/retry', [EdoDocumentController::class, 'retry'])->whereUuid('uuid')->name('documents.retry');
    });

    Route::post('/requests/{id}/edo/prepare', [EdoDocumentController::class, 'prepare'])->name('requests.edo.prepare');
    Route::post('/edo/documents/{uuid}/accept-title', [EdoDocumentController::class, 'acceptTitle'])->name('edo.documents.acceptTitle');
    Route::post('/requests/{id}/edo/prepare-manager-invoice', [EdoDocumentController::class, 'prepareManagerInvoice'])->name('edo.prepare-manager-invoice');
    Route::post('/requests/{id}/edo/prepare-ucd', [EdoDocumentController::class, 'prepareUcd'])->name('edo.prepare-ucd');
});
