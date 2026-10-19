# Аудит индексов — этап 1

Дата аудита: 2026-09-29.

Проверены внешние ключи и условия выборок в моделях, сервисах и контроллерах.
PostgreSQL не создаёт индекс на ссылающейся стороне внешнего ключа автоматически,
поэтому миграция этапа добавляет индексы для наиболее частых связей и фильтров.

## Добавленные индексы

| Индекс | Назначение |
|---|---|
| `repair_requests_created_by_index` | Черновики и документы автора |
| `repair_requests_tenant_stage_index` | Фильтр и очередь по этапу внутри контура |
| `request_events_request_occurred_index` | История заявки по времени |
| `request_status_periods_request_entered_index` | Периоды статусов по времени |
| `attachments_repair_request_id_index` | Файлы заявки |
| `attachments_status_period_index` | Файлы периода статуса |
| `attachments_request_event_id_index` | Файлы события |
| `diagnostic_reports_repair_request_id_index` | Заключения диагностики заявки |
| `request_works_repair_request_id_index` | Работы заявки |
| `request_parts_repair_request_id_index` | Запчасти заявки |
| `request_parts_supplier_id_index` | Запчасти по поставщику |
| `request_parts_catalog_part_id_index` | Запчасти по записи справочника |
| `part_movements_request_part_id_index` | Возвраты и обмены по позиции |
| `request_approvals_request_stage_decision_index` | Согласования заявки по этапу и решению |
| `request_chat_files_message_index` | Файлы сообщения чата |
| `work_order_items_order_kind_index` | Позиции заказ-наряда по типу |
| `vehicle_service_records_vehicle_performed_index` | История работ по ТС и дате |
| `vehicles_tenant_organization_active_index` | Активные ТС заказчика |
| `service_reasons_tenant_active_index` | Активные причины обращения |
| `service_reasons_parent_id_index` | Подвиды причин |
| `work_types_tenant_active_index` | Активные работы |
| `suppliers_tenant_active_index` | Активные поставщики |
| `stations_tenant_active_index` | Активные СТО |
| `catalog_parts_tenant_active_index` | Активные запчасти |

Индексы создаются через `CREATE INDEX IF NOT EXISTS`, поэтому миграция безопасна
для окружений, где отдельный индекс ранее был добавлен вручную.

## Не дублируются

- первичные ключи и уникальные UUID;
- уникальные VIN, логины, номера документов и ключи объектов;
- уже существующие индексы уведомлений, блокировок, пользователей,
  заявок по арендатору/заказчику и арендатору/СТО/этапу;
- индексы справочника марок и моделей и импорта применимостей.
