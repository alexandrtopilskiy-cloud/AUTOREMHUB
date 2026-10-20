# Этап 1. Инфраструктура кода

Этап реализован от актуальной ветки `main` без изменения ранее закрытого этапа 0.

## 1.1. Каталоги

Добавлены заготовки каталогов:

- `app/DTO`;
- `app/ValueObjects`;
- `app/Policies`;
- `app/Rules`;
- `app/Http/Resources`.

`app/Http/Requests` уже содержал FormRequest-классы и сохранён в текущей структуре.

## 1.2. Enum-классы

Созданы enum-типы, предусмотренные этапом 1:

- `RepairStage`, `TransitionAction`, `ApprovalDecision`;
- `UserRole`, `OrganizationKind`, `PersonKind`;
- `AttachmentPurpose`, `AttachmentMime`;
- `RequestPartStatus`, `PartMovementKind`;
- `InvoiceStatus`, `WorkOrderItemKind`;
- `CatalogKind`.

`PersonKind` добавлен сверх первоначального счётчика из 12 классов: в актуальной
схеме поля `organization_people.kind` и `vehicle_contacts.kind` также являются
закрытыми множествами.

## 1.3. CHECK-ограничения

Миграция `2026_09_29_120000_add_enum_constraints_and_query_indexes.php`
добавляет недостающие CHECK-ограничения для:

- статусов запчастей и счетов;
- типов движений запчастей и позиций заказ-наряда;
- назначений, MIME-типов и размера вложений;
- этапов событий, периодов статуса и согласований;
- роли автора сообщения чата;
- положительного количества в позициях заказ-наряда.

Уже существующие ограничения `users.role`, `organizations.kind`,
`repair_requests.stage`, `request_approvals.decision`, `organization_people.kind`
и `vehicle_contacts.kind` не дублируются.

## 1.4. Индексы

Результат аудита и перечень добавленных индексов описаны в
`docs/refactoring-stage-1-index-audit.md`.

## 1.5–1.7. Casts, enum-литералы и Workflow

Enum-cast добавлен в модели `User`, `Organization`, `OrganizationPerson`,
`VehicleContact`, `RepairRequest`, `RequestEvent`, `RequestStatusPeriod`,
`Attachment`, `RequestPart`, `PartMovement`, `RequestApproval`, `Invoice` и
`WorkOrderItem`, а также `RequestChatMessage`. В `Attachment` типизированы и
назначение файла, и MIME-тип; в истории заявки этап типизирован тем же
`RepairStage`, что и основная заявка, а роль автора сообщения чата использует
`UserRole`.

Сервисы, контроллеры, команды и API-presenter переведены на enum.
Внешний JSON-контракт не изменён: API по-прежнему возвращает строковые
и целочисленные значения, а не PHP-объекты enum.

`Workflow` использует `RepairStage` и `UserRole` в доменной логике.
Публичные массивы и legacy-константы этапов сохранены для обратной
совместимости с текущими тестами и PHP-интеграциями.

Скалярные значения оставлены намеренно только на границах или там, где
PHP не разрешает обращение к `EnumCase->value` в константном выражении:

- SQL-миграции хранят исторический снимок схемы;
- `Workflow::OWNERS`, `Workflow::STAGES` и `Workflow::STAGE_*` сохраняют
  legacy-контракт;
- ключи `CatalogService::MODELS` и `CatalogImportSchema::SCHEMAS` являются
  константными идентификаторами внешнего контракта;
- расширяемые типы событий, уведомлений, аудита и контекстов
  согласования не являются закрытыми множествами этапа 1.

## 1.8. `integrity.test.js`

Файл `integrity.test.js` в актуальной `main` отсутствует, поэтому дополнительное
удаление не требуется. Возврат теста остаётся задачей этапа 8.
