# Схема БД

## Основные (multi-tenant)
- `tenants` — контуры
- `organizations` — организации (УК, Заказчик, СТО)
- `users` — пользователи всех кабинетов
- `personal_access_tokens` — токены Sanctum
- `sessions` — сессии

## Заявки
- `repair_requests` — заявки
- `request_events` — история
- `request_works`, `request_parts` — позиции смет
- `request_approvals` — согласования
- `request_notifications` — уведомления
- `request_status_periods` — периоды статусов
- `vehicle_acceptance_acts` — акты приёма
- `work_orders`, `work_order_items`, `work_order_versions` — заказ-наряды
- `invoices` — счета

## Чат
- `request_chats`, `request_chat_messages`, `request_chat_files`, `request_chat_reads`

## Справочники
- `vehicle_makes`, `vehicle_models`, `vehicle_generations`, `vehicle_engine_variants` — каталог ТС
- `vehicle_drive_types`, `vehicle_transmission_types` — приводы и трансмиссии
- `work_types`, `time_norms`, `time_norm_applicabilities` — работы и нормы
- `work_catalog_imports` — ревизии импорта справочника
- `part_manufacturers`, `catalog_parts`, `suppliers` — запчасти
- `part_movements` — движения ЗЧ
- `service_reasons` — причины обращения
- `maintenance_regulations`, `maintenance_regulation_items` — регламенты ТО
- `catalog_change_events`, `catalog_records` — журнал изменений справочников
- `vehicle_change_requests` — заявки на изменение ТС

## Файлы и инфраструктура
- `attachments` — метаданные вложений
- `cache`, `cache_locks` — кэш
- `jobs`, `job_batches`, `failed_jobs` — очереди
- `document_locks` — блокировки редактирования
- `migrations` — миграции
- `pg_stat_statements`, `pg_stat_statements_info` — метрики PostgreSQL

## Рекомендации (0.36.1)
- `recommendations`, `recommendation_items`

## ЭДО
- `edo_documents`, `edo_provider_state`

## Поставщики и снимки ЗЧ (0.37.1)

Миграция `2026_10_15_000029_expand_suppliers_and_part_snapshots`:

| Таблица / модель | Поля и назначение |
| --- | --- |
| suppliers / Supplier | legal_form; requisites JSONB — реквизиты по форме; contacts JSONB — массив контактных лиц; created_by_organization_id → organizations |
| catalog_parts / CatalogPart | created_by_organization_id → organizations; created_by_organization_name — неизменяемое название организации автора; name до 500 символов |
| request_parts / RequestPart | manufacturer_snapshot; purchase_price — снимок закупочной цены; name до 500 символов |

Поставщики и ЗЧ общие для УК/СТО **внутри tenant**. Каталог ЗЧ обе роли могут
редактировать, снимки существующих позиций заявки при этом не обновляются.
Авторство старого каталога восстанавливается только при наличии события create.
Первичное заполнение новых полей исторических строк ЗЧ берётся из текущего
каталога; неизвестную первоначальную закупочную цену восстановить нельзя.
Согласованные цены реализации и суммы миграция не изменяет.
Новых таблиц рекомендаций не требуется: интерфейс объединяет документы по ТС,
а связь с исходным документом и ревизии остаются в recommendations/items.
