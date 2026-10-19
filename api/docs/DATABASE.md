# Схема данных

Актуальная карта таблиц, моделей, связей и правил хранения находится в корневом файле [`../../DATABASE_SCHEMA.md`](../../DATABASE_SCHEMA.md).

DDL реализован миграциями в `database/migrations`. PostgreSQL-комментарии на таблицах и новых полях создаются соответствующими миграциями, включая `2026_09_27_000014_add_station_working_schedule.php`.

Последняя миграция добавляет:

- `vehicles.labor_rate_coefficient` — поправочный коэффициент нормо-часа;
- `catalog_parts` / `App\Models\CatalogPart` — справочник запчастей УК;
- `catalog_change_events` / `App\Models\CatalogChangeEvent` — журнал создания, ручного изменения и импорта записей справочников.
- `request_notifications` / `App\Models\RequestNotification` — персональные уведомления пользователей по заявкам.
- `organization_people` / `App\Models\OrganizationPerson` — справочники контактных лиц и водителей организации;
- `document_locks` / `App\Models\DocumentLock` — временные блокировки совместного открытия документов и справочников.
- `vehicle_change_requests` / `App\Models\VehicleChangeRequest` — изменения ТС Заказчика, ожидающие акцепта УК;
- `vehicles.labor_hour_rate` — стоимость нормо/часа в рублях, доступная для изменения только УК;
- `vehicle_contacts.kind` — разделение связанных лиц на контактных лиц и водителей;
- `stations.profile` — структурированный чек-лист СТО;
- `stations.workday_start`, `stations.workday_end` — локальные границы рабочего дня СТО;
- `stations.working_days` — рабочие дни СТО по ISO-8601 (`1` — понедельник, `7` — воскресенье);
- `time_norms.hours = NULL` — импортированная норма, ожидающая ручного заполнения УК.
- `vehicle_makes` / `App\Models\VehicleMake` — внутренний справочник марок с
  признаком актуальности;
- `vehicle_models` / `App\Models\VehicleModel` — технические варианты моделей,
  поколения/кузова, двигатели и исходные обозначения характеристик;
- `vehicles.make`, `vehicles.model` — отдельные текстовые значения марки и модели;
- `vehicles.vehicle_model_id` — ссылка на выбранный вариант справочника.


## Рекомендации (0.36.1)

- `recommendations` — модель `App\Models\Recommendation`: отдельный документ REC, tenant, автомобиль/VIN, организация Заказчика, исходная заявка, автор, ревизия и дата.
- `recommendation_items` — модель `App\Models\RecommendationItem`: вид позиции, полный неизменяемый JSON-снимок данных БД и отображения, автор с индексом роли, дата, целевая заявка и момент переноса.
- `source_request_id` и `target_request_id` — внешние ключи на `repair_requests`. При удалении собственного черновика ссылка обнуляется; снимок и отметка переноса сохраняются.
- `vehicle_id`, `customer_organization_id`, `created_by`, `actor_id` — FK на действующие таблицы проекта. Все операции изменения проходят проверку прав, ревизии и транзакцию.

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
