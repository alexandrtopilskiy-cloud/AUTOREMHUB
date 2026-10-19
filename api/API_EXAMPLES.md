# API examples

Исполняемый набор заготовок для IDE HTTP Client: [`docs/requests.http`](docs/requests.http).

## Вход и единственная активная сессия

```http
POST /api/login
Origin: http://localhost:5173
Content-Type: application/json

{"login":"Zakaz11","password":"<password-from-user-directory>"}
```

Если учётная запись уже открыта, API возвращает HTTP 409 с кодом
`SESSION_ACTIVE`. Для завершения прежней сессии и входа на новом устройстве
повторяется тот же запрос с `"force":true`. Все прежние токены и блокировки
этого пользователя удаляются немедленно.

## Справочники ЛК УК

### Универсальный импорт справочников из Excel

Импорт доступен только ЛК УК для справочников `vehicles`, `vehicleModels`,
`drivers`, `contactPersons`, `workTypes`, `normApplicabilities`, `parts`,
`customers`, `stations`, `managers`, `users`.

```http
GET /api/catalogs/vehicles/import/template
Authorization: Bearer <token>
```

Ответ — индивидуальный XLSX-шаблон. Заполненный файл передаётся без изменения
заголовков:

```http
POST /api/catalogs/vehicles/import
Authorization: Bearer <token>
Content-Type: multipart/form-data

file=<vehicles.xlsx>
```

HTTP 201 означает атомарное добавление всех строк. HTTP 422 содержит
`result.conflicts[]` с полями `row`, `cell`, `column`, `value`, `message`; при
этом ни одна строка не добавляется. Полный перечень проверок приведён в
`docs/CATALOG_EXCEL_IMPORT.md`.

Список справочников возвращает УК; Заказчик получает только автомобили своей
организации, а СТО — пустой список:

```http
GET /api/catalogs
Authorization: Bearer <token>
Accept: application/json
```

## Контактные лица и водители организации

Обе записи создаются одним маршрутом. Заказчик не передаёт `organizationId`:
организация определяется по его учётной записи. УК передаёт UUID Заказчика.

```http
POST /api/catalogs/people
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"kind":"contact","organizationId":"4d45c45d-1693-4ac0-b0a9-b15009bfb0d1","lastName":"Иванов","firstName":"Иван","middleName":"Иванович","phone":"+7 (999) 123 45 67"}}
```

Для водителя используется `"kind":"driver"`. Изменение и логическое удаление:

```http
PATCH /api/catalogs/people/51d0ca01-602d-485a-adac-3f9117b01292
Authorization: Bearer <token>
Content-Type: application/json

{"patch":{"lastName":"Петров","firstName":"Пётр","middleName":"","phone":"+7 (999) 765 43 21"}}
```

```http
DELETE /api/catalogs/people/51d0ca01-602d-485a-adac-3f9117b01292
Authorization: Bearer <token>
```

Телефон нормализуется в БД как `+7XXXXXXXXXX`, но API принимает и возвращает
формат `+7 (ххх) ххх хх хх`. Фамилия, имя и телефон обязательны.

Изменение автомобиля УК, включая стоимость нормо/часа:

```http
PATCH /api/catalogs/vehicles/9be7e60b-28f8-4d20-a1a2-80c48615b9fd
Authorization: Bearer <token>
Content-Type: application/json

{"patch":{"laborHourRate":1590.00,"laborRateCoefficient":1.59}}
```

Заказчик использует тот же маршрут. Изменения `baselineServiceMileage`,
`contactPersonIds` и `driverPersonIds` применяются сразу после нажатия
«Сохранить» и возвращают `pendingApproval: false`. Изменения остальных полей
создают запрос УК и возвращают `pendingApproval: true`.

```http
PATCH /api/catalogs/vehicles/9be7e60b-28f8-4d20-a1a2-80c48615b9fd
Authorization: Bearer <token>
Content-Type: application/json

{"patch":{"baselineServiceMileage":42500,"contactPersonIds":["51d0ca01-602d-485a-adac-3f9117b01292"],"driverPersonIds":["8e898961-487d-4989-86bb-9f6dd51131a9"]}}
```

Марки и модели ищутся по полному совпадению введённого префикса без учёта
регистра. Модели запрашиваются только после выбора марки:

```http
GET /api/vehicle-reference/makes?search=lad&limit=50
Authorization: Bearer <token>
Accept: application/json
```

```http
GET /api/vehicle-reference/models?makeId=4d45c45d-1693-4ac0-b0a9-b15009bfb0d1&search=ves&limit=100
Authorization: Bearer <token>
Accept: application/json
```

Для применимости нормы «По авто» доступны зависимые справочники поколения,
двигателя, трансмиссии и привода:

```http
GET /api/vehicle-reference/generations?modelId=<model-uuid>&search=b9&limit=100
Authorization: Bearer <manager-token>
Accept: application/json
```

```http
GET /api/vehicle-reference/engines?modelId=<model-uuid>&generationId=<generation-uuid>&search=cze&limit=100
Authorization: Bearer <manager-token>
Accept: application/json
```

```http
GET /api/vehicle-reference/transmissions?search=акп&limit=50
Authorization: Bearer <manager-token>
Accept: application/json
```

```http
GET /api/vehicle-reference/drives?search=пол&limit=50
Authorization: Bearer <manager-token>
Accept: application/json
```

Сохранение работы с полной применимостью нормы и архивное удаление нормы
выполняются одним `PATCH`. UUID удаляемой нормы передаётся отдельно: отсутствие
строки в `timeNorms` само по себе ничего не удаляет.

```http
PATCH /api/catalogs/<work-type-uuid>
Authorization: Bearer <manager-token>
Content-Type: application/json

{"patch":{"timeNorms":[{"applicabilityId":"<vehicle-applicability-uuid>","vehicleMakeId":"<make-uuid>","vehicleModelId":"<model-uuid>","vehicleGenerationId":"<generation-uuid>","vehicleEngineId":"<engine-uuid>","vehicleTransmissionId":"<transmission-uuid>","vehicleDriveId":"<drive-uuid>","modificationCode":"A1","hours":"1.750","source":"Технологическая карта"}],"deletedTimeNormIds":["<deleted-norm-uuid>"]}}
```

ЛК УК получает постраничный внутренний справочник с фильтрами по каждому
столбцу:

```http
GET /api/vehicle-reference/catalog?page=1&perPage=100&make=LADA&bodyCode=Vesta
Authorization: Bearer <manager-token>
Accept: application/json
```

Маршрут возвращает `records`, `total`, `page` и `perPage`. Для Заказчика и СТО
он закрыт; подбор марки и модели в формах продолжает использовать маршруты
`makes` и `models`.

Если варианта нет, Заказчик или УК создаёт его во внутреннем справочнике:

```http
POST /api/vehicle-reference/models
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"make":"LADA","model":"Vesta","modelYearFrom":2015,"modelYearTo":2026,"engineIndex":"21129","horsepower":106.0,"transmission":"МКПП","driveType":"передний","engineType":"Бензин"}}
```

Создание автомобиля (УК передаёт `organizationId`, Заказчику он назначается
из его учётной записи):

```http
POST /api/catalogs/vehicles
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"organizationId":"4d45c45d-1693-4ac0-b0a9-b15009bfb0d1","make":"LADA","model":"Vesta","vehicleModelId":"6bf7b1f0-d2b4-45cf-8f35-f9fd90ce8f68","vin":"XTA00000000000001","plate":"А001АА77","year":2025,"laborHourRate":1590,"laborRateCoefficient":1,"contactPersonIds":["51d0ca01-602d-485a-adac-3f9117b01292"],"driverPersonIds":["8e898961-487d-4989-86bb-9f6dd51131a9"]}}
```

ТС принимает только UUID записей из справочников той же организации. Заказчик
получает `pendingApproval: true`; новое ТС не возвращается в его списке до
решения УК.

Удаление автомобиля (логическое; для Заказчика также требует акцепта УК):

```http
DELETE /api/catalogs/vehicles/9be7e60b-28f8-4d20-a1a2-80c48615b9fd
Authorization: Bearer <token>
```

Решение УК по изменению ТС:

```http
POST /api/catalogs/vehicle-approvals/15ea8f4a-f569-4b65-9ba3-e6f8944f129e/decision
Authorization: Bearer <token>
Content-Type: application/json

{"decision":"approved","comment":"Данные проверены"}
```

Создание записи справочника запчастей:

```http
POST /api/catalogs/parts
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"name":"Масляный фильтр","article":"OF-1","manufacturer":"Производитель","unit":"шт.","active":true}}
```

Создание организации Заказчика без учётной записи:

```http
POST /api/catalogs/customers
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"name":"ООО \"Новый заказчик\"","shortName":"Новый заказчик","inn":"7700000000","kpp":"770001001","ogrn":"1027700000000","legalAddress":"Москва","actualAddress":"Москва","postalAddress":"101000, Москва","directorName":"Иванов Иван Иванович","contact":"Петров Пётр","phone":"+79991234567","email":"office@example.ru","bankName":"Банк","bik":"044525000","settlementAccount":"40702810000000000001","correspondentAccount":"30101810000000000000","taxRegime":"ОСНО","vatRate":20}}
```

Организации УК создаются тем же маршрутом с видом справочника `managers`.
Логины и пароли в справочниках `customers`, `stations` и `managers` отсутствуют.

Создание СТО с чек-листом выполняется через
`POST /api/catalogs/stations`. Помимо реквизитов Заказчика объект принимает
`address`, `active` и поля чек-листа, например `mechanicalBays`, `lifts`,
`engineRepair`, `bodyRepair`, `tireService`,
`vehicleRestrictions` и `yearsOperating`.

Время и график работы обязательны. В `workingDays` используются номера дней
ISO-8601: понедельник — `1`, воскресенье — `7`:

```http
POST /api/catalogs/stations
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"name":"ООО \"Новая СТО\"","address":"Москва","workdayStart":"10:00","workdayEnd":"20:00","workingDays":[1,2,3,4,5],"active":true}}
```

Для созданных до этой миграции СТО автоматически устанавливаются часы
`10:00–20:00` и все семь рабочих дней. Расчётное время завершения ремонта,
попавшее до открытия, в момент закрытия, после закрытия или на выходной,
переносится в допустимое рабочее окно СТО.

Пользователи создаются отдельно и связываются с организацией через
`organizationId`. Вид личного кабинета определяется видом выбранной
организации:

```http
POST /api/catalogs/users
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"lastName":"Иванов","firstName":"Иван","middleName":"Иванович","email":"ivanov@example.ru","login":"CUSTOMER3","password":"длинный-тестовый-пароль","phone":"+7 (999) 123 45 67","organizationId":"4d45c45d-1693-4ac0-b0a9-b15009bfb0d1","registeredAt":"2026-09-27","accessRevokedAt":null,"active":true,"accessRights":["requests.view","requests.create","catalogs.vehicles","catalogs.people"]}}
```

В ответе пароль всегда заменён маской `••••••••••••`. Редактирование,
перенос в другую организацию и отзыв доступа выполняются через общий маршрут:

```http
PATCH /api/catalogs/0e5982af-451d-440c-928f-4ca6f7426d45
Authorization: Bearer <token>
Content-Type: application/json

{"patch":{"accessRevokedAt":"2026-10-01","active":false}}
```

Любая непустая `accessRevokedAt` блокирует вход и отзывает действующие токены.
Чтобы заменить пароль, передайте поле `password` в этом же PATCH. Справочник
`users` возвращается и редактируется только пользователями ЛК УК.

Журнал записи или всего справочника:

```http
GET /api/catalogs/history?kind=vehicles&recordId=9be7e60b-28f8-4d20-a1a2-80c48615b9fd
Authorization: Bearer <token>
Accept: application/json
```

Импорт норм времени принимает `multipart/form-data` с единственным файлом XLSX. Обязательные колонки: `Работа`, `Норма, ч`; необязательные: `Модель автомобиля`, `Источник`.

```http
POST /api/catalogs/time-norms/import
Authorization: Bearer <token>
Content-Type: multipart/form-data; boundary=...

file=<time-norms.xlsx>
```

Пример ответа:

```json
{"result":{"created":12,"updated":3,"workTypesCreated":2,"total":15,"dryRun":false}}
```

Пустая ячейка нормы импортируется как `NULL` и затем редактируется в ЛК УК.
Строки без модели получают применимость `Общая`, а прежние текстовые значения
модели сохраняются как `Специальная` для обратной совместимости.

Создание работы вместе с нормами времени выполняется атомарно. `group` выбирается
из закрытого перечня, возвращаемого в `GET /api/reference-data` как
`workGroups`. Там же возвращается `normApplicabilities` с базовыми значениями
`Общая`, `Специальная`, `По авто`:

```http
POST /api/catalogs/workTypes
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"name":"Замена масла","group":"Техническое обслуживание","isMaintenance":true,"active":true,"timeNorms":[{"applicabilityId":"<uuid Общая>","vehicleMakeId":"","vehicleModelId":"","hours":0.8,"source":"Технологическая карта"}]}}
```

Для применимости `По авто` требуется UUID марки и/или модели из внутреннего
справочника автомобилей. Совпадающая с автомобилем норма заявки имеет приоритет
над общей:

```json
{"applicabilityId":"<uuid По авто>","vehicleMakeId":"<uuid марки>","vehicleModelId":"<uuid модели>","hours":0.65,"source":"Норма производителя"}
```

Дополнительная применимость создаётся отдельно и после этого выбирается в строке
нормы:

```http
POST /api/catalogs/normApplicabilities
Authorization: Bearer <token>
Content-Type: application/json

{"record":{"name":"Спецтехника","active":true}}
```

Общий реестр действий доступен только УК и не редактируется:

```http
GET /api/change-registry
Authorization: Bearer <token>
Accept: application/json
```

## Сохранение заявки с водителем

Контакты и водители выбранного ТС загружаются отдельно:

```http
GET /api/vehicles/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/contacts
Authorization: Bearer <token>
```

```http
GET /api/vehicles/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/drivers
Authorization: Bearer <token>
```

```http
PATCH /api/requests/RP-000001
Authorization: Bearer <token>
Content-Type: application/json

{"revision":0,"patch":{"vehicleId":"9be7e60b-28f8-4d20-a1a2-80c48615b9fd","mileage":"42500","contactId":"51d0ca01-602d-485a-adac-3f9117b01292","driverId":"54e72214-cdee-4a28-bb14-47cf4881304a","reasonId":"3aa60e67-7686-4254-aa76-59133219c28b","slot":"2026-10-20T11:00"}}
```

Все поля даты и времени принимают 24-часовой формат `YYYY-MM-DDTHH:mm`, минуты
`00` или `30`. Новое значение должно быть не раньше ближайшего получасового
интервала после текущего времени плюс 30 минут.

## Удаление черновиков

Удаляется только черновик этапа `0`, созданный текущей учётной записью и ни
разу не переданный по маршруту. Массовая операция атомарна: если хотя бы один
UUID недоступен для удаления, не удаляется ни один документ.

```http
POST /api/requests/drafts/delete
Authorization: Bearer <token>
Content-Type: application/json

{"ids":["9be7e60b-28f8-4d20-a1a2-80c48615b9fd","15ea8f4a-f569-4b65-9ba3-e6f8944f129e"]}
```

Для одного черновика также доступен `DELETE /api/requests/{uuid}`.

## Решение СТО по заявке

На этапе `2` СТО передаёт одно из решений: `confirmed`, `rescheduled` или
`declined`. Комментарий необязателен.

Подтверждённое время автоматически копируется из желаемого. Расчётное время
завершения формирует API: дата и время визита + 30 минут + сумма
`normHours × qty` по работам.

Подтвердить заявленное время:

```http
POST /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/station-decision
Authorization: Bearer <token>
Content-Type: application/json

{"revision":4,"decision":"confirmed","comment":"Время подтверждено, пост зарезервирован"}
```

СТО может предварительно сохранить изменённые работы. Ручная норма фиксируется
в строке заявки и не изменяет справочник работ: для этого `timeNormId` передаётся
пустым, а значение записывается в `normHours`.

```http
PATCH /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd
Authorization: Bearer <token>
Content-Type: application/json

{"revision":4,"patch":{"works":[{"name":"Диагностика ходовой части","type":"Работа","qty":1,"workTypeId":"51d0ca01-602d-485a-adac-3f9117b01292","timeNormId":"","normHours":1.5,"actualHours":null}]}}
```

Если СТО изменила работы или запчасти, решение переводит заявку на этап `1`
для согласования УК. УК без изменений направляет её Заказчику; корректировка УК
возвращает заявку на этап `2` с перечнем изменений. Цикл повторяется до
совпадения версии сметы у УК и СТО.
При возврате из УК API передаёт `reviewContext: "manager_changes"`:
неизменённая версия может быть принята СТО, а новая редакция снова уходит в УК.

Предложить другое время:

```http
POST /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/station-decision
Authorization: Bearer <token>
Content-Type: application/json

{"revision":5,"decision":"rescheduled","stationSlot":"2026-10-20T14:00","comment":"Предлагаем принять автомобиль в 14:00"}
```

Заказчик принимает финальную заявку либо предлагает своё время. Принятие
переводит заявку в статус `4` «Ожидание ТС на СТО», иное время возвращает её в УК:

```http
POST /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/customer-slot-decision
Authorization: Bearer <token>
Content-Type: application/json

{"revision":6,"decision":"accepted","comment":"Согласовано"}
```

```http
POST /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/customer-slot-decision
Authorization: Bearer <token>
Content-Type: application/json

{"revision":6,"decision":"rescheduled","slot":"2026-10-21T10:30","comment":"Просим принять утром"}
```

Отказаться от выполнения и вернуть заявку в УК:

```http
POST /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/station-decision
Authorization: Bearer <token>
Content-Type: application/json

{"revision":4,"decision":"declined","comment":"Нет свободного подъёмника"}
```

## Приём автомобиля

На этапе `4` СТО создаёт отдельный неизменяемый акт приёма. Электронный акт:

```http
POST /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/acceptance-act
Authorization: Bearer <token>
Content-Type: application/json

{"revision":8,"mode":"electronic","mileage":42520,"fuelLevel":"half","condition":"Комплектность проверена","defectTypes":["scratch","chip"],"diagramMarks":[{"zone":"left","type":"scratch","comment":"Передняя дверь"}]}
```

Для заполненного вручную акта сначала загружается PDF или изображение с
`purpose=acceptance_act`, затем передаётся его UUID:

```http
POST /api/requests/9be7e60b-28f8-4d20-a1a2-80c48615b9fd/acceptance-act
Authorization: Bearer <token>
Content-Type: application/json

{"revision":9,"mode":"uploaded","mileage":42520,"attachmentId":"15ea8f4a-f569-4b65-9ba3-e6f8944f129e"}
```

После сохранения акта заявка получает статус `5` «В работе», а Заказчику и УК
создаются уведомления `work_started`. Повторное изменение акта запрещено.

Актуальные операционные статусы: `0` Черновик, `1` Маршрутизация УК, `2`
Согласование СТО, `3` Согласование Заказчика, `4` Ожидание ТС на СТО, `5` В работе,
`6` Готово. Далее сохраняются этапы выдачи, закрывающих документов и оплаты.

## Уведомления

После оформления акта приёма и начала работ пользователи Заказчика и УК получают уведомление
`work_started`.

```http
GET /api/notifications
Authorization: Bearer <token>
Accept: application/json
```

```http
POST /api/notifications/15ea8f4a-f569-4b65-9ba3-e6f8944f129e/read
Authorization: Bearer <token>
Content-Type: application/json

{}
```

Для любой роли этот запрос удаляет прочитанное уведомление. Последующий список
содержит только непрочитанные записи. Клиент открывает связанную заявку или
запись согласования ТС по полям `targetType` и `targetId` уведомления.

## Блокировка совместного открытия

Перед редактированием заявки или отдельной записи справочника клиент получает
аренду блокировки. Заявки в кабинетах без права текущего редактирования
открываются без блокировки только для просмотра. Список справочника не
блокируется: параллельно можно менять разные записи. Клиент использует
`resourceType` `request` или `catalog_record`.

```http
POST /api/document-locks
Authorization: Bearer <token>
Content-Type: application/json

{"resourceType":"request","resourceKey":"RP-000001","label":"Заявка RP-000001"}
```

Продление и освобождение выполняются по `lock.id`:

```http
POST /api/document-locks/15ea8f4a-f569-4b65-9ba3-e6f8944f129e/heartbeat
Authorization: Bearer <token>
Content-Type: application/json

{}
```

```http
DELETE /api/document-locks/15ea8f4a-f569-4b65-9ba3-e6f8944f129e
Authorization: Bearer <token>
```

Если ресурс занят другим пользователем, API возвращает HTTP 423 с кодом
`DOCUMENT_LOCKED` и полями `lockedBy`, `lockedLogin`, `expiresAt`.

## Чат заявки

Одна заявка имеет не более одного чата. Создание идемпотентно; доступ проверяется
по организации Заказчика, назначенной СТО или УК текущего контура. Доступ к
чату не зависит от владельца этапа и блокировки редактирования документа.

```http
POST /api/requests/RP-000128/chat
Authorization: Bearer <token>
Content-Type: application/json

{}
```

```http
GET /api/chats/summary
Authorization: Bearer <token>
```

Ответ `summary`: `{ "chats": [{ "requestId": "<uuid>",
"requestNumber": "RP-000128", "unreadCount": 2 }], "unreadTotal": 2 }`.
Счётчик персональный: свои сообщения в него не входят.

```http
GET /api/requests/RP-000128/chat
Authorization: Bearer <token>
```

Возвращает `created`, до 50 сообщений в хронологическом порядке и `hasMore`.
Для более ранних сообщений укажите `?before=<sequence>`, для новых —
`?after=<sequence>`. `sequence` — числовой курсор из ответа; `id` — UUID
сообщения. Каждое сообщение содержит `sender: { id, name, role, roleMark }`,
где `name` — ФИО конкретного пользователя, `roleMark` — `З`, `С` или `У`.
ФИО и роль фиксируются на момент отправки.

```http
POST /api/requests/RP-000128/chat/messages
Authorization: Bearer <token>
Content-Type: multipart/form-data

text=<необязательный текст>&files[]=<файл>
```

Текст до 5000 символов или 1–10 файлов до 30 МБ каждый. Принимаются JPEG,
PNG, WebP, HEIC, MP4, WebM, MOV, PDF, TXT, CSV, RTF, DOC/DOCX, XLS/XLSX,
PPT/PPTX и ODT/ODS. Файлы хранятся приватно. Для скачивания:

```http
GET /api/chat-files/<uuid>/download
Authorization: Bearer <token>
```

После фактического открытия переписки клиент отмечает последний показанный
`sequence` как прочитанный:

```http
POST /api/requests/RP-000128/chat/read
Authorization: Bearer <token>
Content-Type: application/json

{"lastMessageId": 123}
```

Просмотр списка сообщений сам по себе счётчик не снимает. Отправленные
сообщения и файлы не имеют маршрутов изменения и удаления. Заявка с созданным
чатом не удаляется, в том числе как черновик.
## Импорт справочника работ и подбор норм времени

Полный формат XLSX и перечень проверок описаны в
[`docs/WORK_CATALOG_IMPORT.md`](docs/WORK_CATALOG_IMPORT.md).

## Проверить новый справочник без изменения БД

```bash
curl -X POST "$API_URL/api/catalogs/work-catalog/import/preview" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -F "file=@autoremhub-work-catalog.xlsx"
```

## Атомарно применить проверенный справочник

```bash
curl -X POST "$API_URL/api/catalogs/work-catalog/import/apply" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -F "file=@autoremhub-work-catalog.xlsx" \
  -F "checksum=<SHA-256 из preview>"
```

## Подобрать норму и рассчитать стоимость

```bash
curl "$API_URL/api/time-norms/resolve?workTypeId=<UUID>&vehicleId=<UUID>&quantity=2" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

Пример результата:

```json
{
  "result": {
    "status": "selected",
    "scope": "generation_modification",
    "norm": {
      "id": "<UUID>",
      "normId": "EN-00001-N002",
      "hours": 1.25,
      "source": "Технологическая карта"
    },
    "conflicts": [],
    "laborHourRate": 2400,
    "unitPrice": "3000.00",
    "amount": "6000.00"
  }
}
```

## Сохранить работу с ручной нормой только в заявке

Цена, ставка и сумма из тела запроса не принимаются как источник истины: API
повторно подбирает норму, берёт `vehicles.labor_hour_rate` и сохраняет снимки.

```bash
curl -X PATCH "$API_URL/api/requests/RP-000128" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "revision": 7,
    "patch": {
      "works": [{
        "name": "Передний правый наружный ШРУС - с/у",
        "workTypeId": "<UUID работы>",
        "timeNormId": "<UUID подобранной нормы>",
        "qty": 1,
        "normHours": 1.75,
        "normIsManual": true,
        "normOverrideReason": "Закисшее шлицевое соединение"
      }]
    }
  }'
```

Если `normHours` отличается от справочника, причина обязательна. Ручное
значение не изменяет запись `time_norms`.


## Каталог ТС и тарифы ver. 0.32.1

См. [описание API, замены каталога и развёртывания](docs/vehicle-catalog-replacement.md).

## Уникальные названия моделей для нормы времени (0.33.4)

`GET /api/vehicle-reference/models?makeId=UUID&search=Cam&uniqueNames=1&limit=100`

Параметр `uniqueNames=1` группирует активные варианты одной марки по нормализованному названию модели до применения limit. Возвращается одна существующая запись на название; её UUID остаётся совместимым с прежними полями нормы. По умолчанию API продолжает возвращать технические варианты. Алгоритм подбора нормы и структура применимости не изменены. Группировка списка не расширяет применимость существующего UUID на другие технические варианты.


## 0.34.1 — применимость к семейству модели

Выбор поколений и двигателей по UUID модели включает все технические варианты с одинаковой маркой и нормализованным названием модели. Поколения объединяются по названию и периоду выпуска; двигатели — по индексу, типу и мощности. При указании поколения двигатели ограничиваются выбранным поколением (и общими записями без поколения). Сохранение и импорт норм проверяют принадлежность к этому семейству. Подбор нормы сопоставляет модель, поколение и двигатель по той же идентичности; приоритеты и обнаружение конфликтов сохраняются. UUID и внешние ключи сохраняются, миграция не нужна.


## 0.34.2 — единый поиск по фрагментам

Все поля подбора и фильтры справочников используют регистронезависимый поиск внутри значения. Запрос разделяется по пробелам; каждый фрагмент должен присутствовать в записи, порядок не важен. В API vehicle-reference это применяется к маркам, моделям, поколениям (включая годы), двигателям (индекс, тип, мощность), трансмиссиям и приводам, а также к фильтрам внутреннего каталога. Несколько фрагментов могут совпадать с разными поисковыми признаками одной записи. Пустой запрос сохраняет прежнюю выдачу и лимиты. Символы % и _ являются буквальными, SQL-шаблоны не поддерживаются. Зависимости и ограничения доступа не изменены.

Примеры параметра search: `am ry` → Camry; `2017 v5` → XV50 с годом 2017; `fe ar` → 2AR-FE. Для GET /api/vehicles поддержаны фрагменты VIN, госномера и названия; нормализация латинских/кириллических букв госномера сохранена. Точные lookup-запросы по VIN и UUID остаются точными. Свободные текстовые поля не превращаются в поиск.


## 0.35.1 — производители, запчасти и решения СТО

GET /api/catalogs: УК/СТО получают записи kind=partManufacturers. GET /api/reference: partManufacturers содержит только активные согласованные записи.

POST /api/catalogs/partManufacturers: `{ "record": { "name": "Название", "active": true } }`. PATCH /api/catalogs/{uuid}: `{ "patch": { "name": "Новое название", "active": true } }`. УК применяет изменение сразу; СТО создаёт предложение, прежняя согласованная запись остаётся действующей.

POST /api/catalogs/part-manufacturers/{uuid}/decision: `{ "decision": "approved", "revision": 1 }` либо rejected. Только УК того же tenant, защита от устаревшего решения (409). Предложение находится в proposedName/proposedActive, новое добавление имеет status=pending и не выбирается в запчастях до акцепта. Уведомление ведёт в запись производителя, решения и предложения записываются в общий журнал.

POST /api/catalogs/parts требует name, manufacturerId (UUID согласованного производителя), article, unit, supplierId (UUID действующего поставщика), purchasePrice, salePrice (JSON-числа, 0 и выше, до 2 десятичных знаков). Доступ УК и СТО; С версии 0.37.1 изменение общей каталожной запчасти доступно УК и СТО через PATCH /api/catalogs/{uuid}; удаление не добавлено. Manufacturer — сохранённое название выбранного производителя; цены закупа и продажи хранятся отдельно. Импорт Excel использует названия производителя и поставщика и проверяет их существование/однозначность.

POST/PATCH /api/requests/{uuid}/parts[/partUuid]: price является ценой реализации конкретной позиции, не меняет salePrice в catalog_parts. Для новой позиции сначала требуется запись справочника. Старые позиции без каталожной связи остаются редактируемыми на разрешённых этапах.

POST /api/requests/{uuid}/station-decision: confirmed принимает необязательный comment без промежуточного диалога; declined требует непустой comment; rescheduled принимает stationSlot по прежним правилам. Изменённая смета направляется в УК с statusLabel=«Согласование работ / з/ч»; отказ — «Маршрутизация УК (отказ СТО)». Числовые stage и owner не изменены.

В документе declinedStationIds хранит UUID отказавшихся СТО. При следующем направлении в одно из них POST /api/requests/{uuid}/next без confirmDeclinedStation=true возвращает 409 STATION_PREVIOUSLY_DECLINED и не переводит заявку. Для продолжения: `{ "revision": 5, "comment": "", "attachmentIds": [], "confirmDeclinedStation": true }`. Новое СТО подтверждения не требует.


## Версия 0.36.1: общее сохранение запчастей

`PATCH /api/requests/{uuid}` принимает `patch.parts` вместе с остальными полями.
Это **полный список** строк: отсутствующая в списке прежняя редактируемая строка удаляется.
Пустая незаполненная новая строка пропускается. Сохранение заявки и всех позиций выполняется
в одной транзакции и увеличивает ревизию заявки один раз.

```json
{
  "revision": 4,
  "patch": {
    "routeComment": "Смета скорректирована",
    "parts": [{
      "id": "",
      "catalogPartId": "<UUID записи справочника ЗЧ>",
      "name": "Фильтр масляный",
      "article": "OF-1",
      "unit": "шт.",
      "supplierId": "<UUID поставщика>",
      "qty": 1,
      "price": 350
    }]
  }
}
```

Для существующей строки передайте её `id`. Цена — цена реализации **в заявке**:
она не изменяет `salePrice` справочника. В этот же `patch` можно добавить `works`
по ранее описанному контракту. Служебные поля `_clientId`, `manufacturer`,
`approvedVersion`, `status` отправлять в `patch.parts` нельзя. Запчасть с заказом,
возвратом или иным движением не удаляется и не редактируется этой операцией.
Права определяются `permissions.editableFields` и `canEditParts`.

## Рекомендации по VIN

```http
GET /api/recommendations?vehicleId=<UUID автомобиля>
```

Ответ: `recommendations[]` с `id`, `systemId`, `number` (`REC-000001`), `revision`,
`vin`, `createdAt`, `items[]`. Позиция содержит `id`, `kind` (`work`/`part`),
неизменяемый `position` со всеми отображаемыми параметрами исходной позиции,
`actor` (ФИО и индекс У/С/З), `createdAt`, `movedAt`, `targetRequestId`.
Полный снимок полей БД хранится в `recommendation_items.snapshot` для восстановления.
Права просмотра ограничены tenant, организацией Заказчика и доступными СТО автомобилями.

```http
POST /api/requests/<UUID заявки>/recommendations
Content-Type: application/json

{"revision":4,"workIds":["<UUID строки работы>"],"partIds":["<UUID строки ЗЧ>"]}
```

Создаёт отдельный документ и удаляет выбранные позиции из заявки. Возвращает
`{request, recommendation}`. Разрешение `canDeferRecommendations`: УК при маршрутизации,
СТО при согласовании, Заказчик при финальном согласовании. История фиксируется в заявке.

```http
POST /api/recommendations/<UUID рекомендации>/restore
Content-Type: application/json

{"revision":0,"itemIds":["<UUID позиции рекомендации>"],"requestId":"<UUID целевой заявки>","requestRevision":5}
```

Возвращает `{request, recommendation}`. Разрешение целевой заявки:
`canRestoreRecommendations`; нужны совпадающие автомобиль **и VIN**. Норма, ставка,
наименование, количество, цена, сумма и причина ручной нормы восстанавливаются
из снимка, а не из текущих значений справочника. Запчасть снова проходит согласование.
При добавлении в финальную заявку Заказчика документ возвращается на согласование УК.

Для **новой** заявки передайте `requestId: null`, `requestRevision: null`.
Черновик создаётся для того же автомобиля и в кабинете текущего пользователя.
ТС в таком черновике закреплён; Заказчик заполняет обязательные поля новой заявки.
УК/СТО сохраняют зафиксированный пробег исходной заявки либо более поздний пробег
истории автомобиля; вручную менять автомобиль и пробег им нельзя.
Выбранная позиция переносится только один раз. Ревизии и блокировка документа
`recommendation` защищают от одновременного переноса. История документа остаётся после переноса.

## Ручное добавление записи внутреннего справочника ТС

```http
POST /api/vehicle-reference/catalog
Content-Type: application/json

{"record":{"make":"Toyota","model":"Camry","modelYearFrom":2018,"modelYearTo":2024,"bodyCode":"XV70","engineType":"Бензин","engineIndex":"A25A-FKS","horsepower":200,"engineVolumeLiters":2.5,"transmission":"АКПП","driveType":"передний"}}
```

Только УК. Успех: HTTP 201, `{model}`. Дублирование полного набора параметров:
HTTP 409, `VEHICLE_VARIANT_DUPLICATE`, включая архивную запись. Старый endpoint
`POST /vehicle-reference/models` сохраняет прежнее поведение для существующих форм.

## Решение УК по изменениям СТО

При `stage: 1`, `reviewContext: "station_changes"`:
- `POST /requests/{uuid}/next` — согласование, переход Заказчику для финального решения;
- `POST /requests/{uuid}/return` — возврат в СТО, `comment` обязателен. Пустой комментарий
  даёт HTTP 422 и не меняет этап. Комментарий и перечень изменений сохраняются в истории
  и уведомлении СТО; история доступна участникам заявки.

`permissions.requiresAction` указывает, требует ли заявка действия в текущем кабинете.
Фронтенд показывает красный статус для действия, серый для просмотра, зелёный при `stage: 11`.

## Версия 0.37.1 — поставщики, общие запчасти и рекомендации

### Поставщики

`GET /api/reference` возвращает `supplierLegalForms`: `{id, name, individual}`.
`individual: true` означает реквизиты ИП (ИНН 12, ОГРНИП 15 цифр, ФИО,
без КПП); иначе нужны ИНН 10, ОГРН 13 цифр, КПП и руководитель.
`other` дополнительно требует `legalFormName`. Налоговый режим задаётся отдельно.

```http
POST /api/catalogs/suppliers
Content-Type: application/json

{"record":{"legalForm":"ooo","name":"ООО Поставщик","shortName":"Поставщик","taxId":"7707083893","kpp":"773601001","ogrn":"1027700132195","registrationDate":"2020-01-15","directorName":"Иванов Иван Иванович","legalAddress":"Москва","actualAddress":"Москва","postalAddress":"Москва","bankName":"Банк","bik":"044525225","settlementAccount":"40702810900000002851","correspondentAccount":"30101810400000000225","taxRegime":"ОСНО","vatRate":"Без НДС","contacts":[{"lastName":"Иванов","firstName":"Иван","middleName":"Иванович","phone":"+7 (999) 123 45 67","email":"supply@example.ru","position":"Менеджер"}],"active":true}}
```

Для ИП передайте `legalForm: "ip"`, `lastName`, `firstName`, необязательное
`middleName`, ИНН/ОГРНИП; `kpp` и `directorName` оставьте пустыми.
Обязательны три адреса, дата регистрации, банковские реквизиты, налоговый режим,
сведения о НДС и минимум одно контактное лицо (фамилия, имя, российский телефон).
E-mail, должность, отчество, краткое название, ОКПО, ОКТМО, ОКВЭД и казначейский
счёт необязательны. Номера передаются **строками**, чтобы сохранить ведущие нули.
Проверяется формат и длина; сверка с ЕГРЮЛ/ЕГРИП и контрольных сумм не выполняется.
ОГРН/ОГРНИП: [ФНС — ОГРН](https://www.nalog.gov.ru/rn38/news/activities_fts/15288246/),
[ФНС — ОГРНИП](https://www.nalog.gov.ru/rn38/news/activities_fts/15180419/).

Ответ 201: `{record}`. Создание доступно УК и СТО. Все действующие поставщики
контура доступны обеим ролям для выбора. Редактирование через
`PATCH /api/catalogs/{uuid}` с `{patch}`: УК — любой, СТО — созданный своей
организацией. Требования к реквизитам проверяются и при редактировании старой
неполной записи. Существующие записи миграция не удаляет.

### Запчасти и видимость

Общий каталог ЗЧ доступен для просмотра и изменения УК/СТО. Поля ответа
`createdByOrganizationId`, `createdByOrganization` заполняются сервером при
создании и не принимаются в `record`/`patch`. Это организация, а не сотрудник.
Для старых записей автор восстанавливается из события создания, если оно есть;
иначе отображается «Не зафиксировано».

Позиция заявки хранит отдельные `manufacturer_snapshot`, `purchase_price`;
изменение каталога не переписывает сохранённые позиции. Для уже существующих
строк миграция первоначально копирует доступные значения из каталога — это
не восстановление неизвестной исторической закупочной цены.
Заказчику API не возвращает поставщика и закупочную стоимость в заявке,
рекомендациях и вложенных отображаемых снимках истории/согласований.

`request.permissions.canViewRepairEstimate` управляет видимостью блока 04:
Заказчик видит его после первого поступления на согласование, в том числе
после последующего возврата документа в другой кабинет. Это правило интерфейса,
не новое право редактирования и не сокрытие всей сметы из JSON.

### Подпись применимости нормы

Ответ подбора нормы (`norm.applicabilityLabel`) и сохранённая строка работ
(`normApplicabilityLabel`) содержат понятную подпись: «Применимость общая»
или «Применимость к Toyota · Camry · AXV70 · A25A · АКПП».
Показываются ограничения выбранной нормы, а не все параметры автомобиля.
`normApplicabilityLabel` — только поле ответа, не передавайте его в patch.
Алгоритм подбора нормы и формула стоимости не изменены. Ручная норма помечается
«Ручная норма для этой заявки»; отсутствующая — «Применимость не определена».

### Единые рекомендации автомобиля

`GET /api/recommendations?vehicleId=<UUID>` сохраняет документы в API для истории,
добавляет в каждый `make`, `model`, `plate`, `vehicleId`. Интерфейс объединяет
их позиции в две таблицы — Работы и ЗЧ — без выбора исходного REC-документа.
Дата, автор, источник и состояние переноса каждой позиции сохраняются.

```http
POST /api/recommendations/restore
Content-Type: application/json

{"documents":[{"id":"<UUID REC-1>","revision":0,"itemIds":["<UUID позиции>"]},{"id":"<UUID REC-2>","revision":2,"itemIds":["<UUID другой позиции>"]}],"requestId":"<UUID заявки>","requestRevision":5}
```

Одна транзакция и одно изменение ревизии целевой заявки. Ответ:
`{request, recommendations}`. Для одного нового черновика передайте оба поля
целевой заявки как `null`. Не более 200 документов и 200 позиций суммарно.
Все позиции должны относиться к одному ТС/VIN. Неверная ревизия, блокировка,
недоступная или уже перенесённая позиция отменяет операцию целиком.
Права и маршрут прежние; запрет обхода согласований сохраняется.
Для записи в request_works/request_parts используется белый список полей БД:
отображаемые `manufacturer`, `supplierName`, `_clientId` и другие служебные
данные из старого снимка не становятся SQL-колонками.
Старый `/api/recommendations/{uuid}/restore` остаётся совместимым.

### Ручной внутренний справочник ТС

Контракт `POST /api/vehicle-reference/catalog` прежний. Форма теперь показывает
все поля в одном окне. Подсказка не ограничивает ввод: можно выбрать Toyota,
Camry и ввести новый кузов AXV70A. Полное совпадение всех характеристик по-прежнему
возвращает 409 `VEHICLE_VARIANT_DUPLICATE`.
