# ЭДО.ру (ЭДО.Поток) — интеграция

Статус: **в процессе**. Получен ЛК, ожидаем API-креды.

## Контекст

- **Оператор:** ЭДО.ру / ЭДО.Поток
- **API:** v5.2 от 03.03.2026
- **Документация:** https://edo.ru/integration/api-edopotok
- **PDF:** https://static.ofd.ru/ofdru/api/edo/5_2.pdf
- **Приказ ФНС (форматы):** № ЕД-7-26/970@ от 19.12.2023
  (в ред. от 15.11.2024, от 26.03.2025)
- **Поддержка:** edo@edo.ru
- **Demo-ЛКК:** https://lk-demo.edo.ru/ — `demo-ul@ofd.ru` / `Demotest1`
- **Prod URL API:** `https://lk.edo.ru/api/edo/v1`
- **Dev URL API (уточнить):** `https://dev-dev.edo.ru/api/edo/v1`

## Что реализовано (закоммичено)

### Скелет провайдера
- `app/Services/Edo/Providers/EdoRu/EdoRuClient.php` — HTTP-клиент:
    - `authGlobal()` — POST `/clients/auth-global`
    - `get()` / `post()` — с `Authorization: Token {token}`
    - `getRaw()` — для бинарных ответов (download-doc, show-doc-pdf)
    - retry на 401: сброс кэша + переавторизация
    - конвертация `windows-1251 ↔ UTF-8` (для prod-API)
    - кэш токена: `edo:edoru:token:{clientId}`, TTL 23ч
- `app/Services/Edo/Providers/EdoRu/EdoRuMapper.php` — маппинг:
    - `toOrganization` (из `search-contractors`)
    - `toDocument` (из `documents/document`)
    - `toSyncResult` (из `documents/find`)
    - `DOC_TYPE_MAP` (сверить с `/dictionary/get-document-type`)
- `app/Services/Edo/Providers/EdoRu/EdoRuProvider.php` — реализовано:
    - `findOrganization()` → `GET /contractors/search-contractors`
    - `getDocument()` → `GET /documents/document`
    - `getDocumentContent()` → `GET /documents/download-doc`
    - `downloadPdf()` → `GET /documents/show-doc-pdf`
    - `pullEvents()` → `GET /documents/find` (курсор по docId)
    - `signDocument()` → `POST /documents/send`
    - `createStructuredDocument()` → генерация XML УПД 5.03 через `Upd503Builder`
    - Заглушки: `getSelfOrganization`, `acceptDocument`, `rejectDocument`,
      `createUnstructuredDocument`, `createUcdDocument`, `acceptTitle`

### XML-билдер УПД 5.03
- `app/Services/Edo/Xml/Upd503Builder.php` — генератор XML УПД 5.03 (КНД 1115131)
    - Функции СЧФ / СЧФДОП / ДОП
    - XSD: `resources/edo/xsd/ON_NSCHFDOPPR_1_997_01_05_03_05.xsd`
    - Результат — XML в windows-1251
- `app/Services/Edo/Xml/XsdValidator.php` — валидация через libxml
    - Принимает windows-1251, конвертирует в UTF-8 для `DOMDocument`
    - Schematron игнорируется (только XSD-структура)

### DTO
- `EdoSignerDto` — подписант (ФИО + должность + authorityScope)
- `EdoStructuredRequest` — `+signer`, `+senderEdiCode`
- `EdoPartyInfo` — `+ediCode`
- `EdoUcdStructuredRequest` — заглушка

### Сервис
- `EdoService::prepareStructuredDocument` — резолв продавца и подписанта,
  сборка payload, вызов провайдера, сохранение Attachment
- `EdoService::resolveSignerForSeller` — из `Organization::director_name`
- `EdoService::prepareInvoiceForManagerToCustomer` — УПД УК → Заказчик

### Контейнер и команды
- `app/Providers/Edo/EdoRuServiceProvider.php` — DI для `EdoRuClient`,
  `EdoRuMapper`, `EdoRuProvider`, `Upd503Builder`, `XsdValidator`
- `app/Console/Commands/Edo/EdoRuSmokeCommand.php` — smoke-проверка API
- `app/Console/Commands/Edo/RegisterEdoOrganizationCommand.php` — `edo:register-organization`

### Тесты
- `tests/Unit/Edo/Xml/Upd503BuilderTest.php` — 4 теста (СЧФ, СЧФДОП, ДОП, ошибки)
- `tests/Feature/Edo/EdoRu/EdoRuClientAuthTest.php` — 4 теста
- `tests/Feature/Edo/EdoRu/EdoRuProviderTest.php` — 8 тестов

Полный прогон: **826 tests, 8953 assertions — OK**.

## Что нужно для запуска на реальном API

### 🔴 Блокеры (ждём от менеджера ЭДО.ру)

1. **`clientId` + `password` для API.**
   Дока: «Для получения пароля обратитесь в edo@edo.ru».
   **Пароль от ЛК не подходит** — нужен отдельный API-пароль.
   Скорее всего `clientId` = `edo_client_id` из ЛК, но **подтвердить**.

2. **URL контура.**
   Наш ЛК — `lk-demo.edo.ru` или другой?
   Использовать `https://lk.edo.ru/api/edo/v1` или `https://dev-dev.edo.ru/api/edo/v1`?

3. **ИдУчЭДО (edo_edi_code) нашей организации.**
   Формат `2PS-<ИНН><...>`. Без него XML УПД не собрать:
   `EdoRuProvider::shortEdiId('')` вернёт `'UNKNOWN'`,
   `@ИдФайл` будет невалидным, ЭДО.ру отклонит.
   Где взять: `whoami` (после auth) или ЛК.

4. **ИдУчЭДО тестового контрагента.**
   Есть ли в demo-контуре готовый контрагент для обмена? Если нет — завести.

5. **XSD УКД 5.01** (`ON_UKDSCHF_*`, КНД 1115132).
   Уже есть XSD титула покупателя УКД (`ON_NSCHFDOPPOK_*`), но это не сам УКД.
   Без XSD `Ucd501Builder` не напишем.

6. **Примеры XML УПД 5.03 / УКД 5.01**, которые гарантированно принимаются.
   Для сверки с нашим билдером.

7. **Flow `acceptDocument` / `rejectDocument` / `acceptTitle`.**
   Подтвердить:
    - `POST /documents/provisional/tech` → `.../tech/sign` — правильный путь?
    - `fingerprint` сертификата — кому принадлежит, где брать?
    - `acceptTitle` — `POST /documents/provisional/buyer/title`.

8. **`getSelfOrganization`.** В v5.2 нет прямого метода «получить себя».
   Уточнить: возможно, через `auth-with-ds` (`edoOrgId` в ответе) или
   через `GET /contractors/invitations`.

### 🟢 Готово к запуску (после получения кредов)

1. Заполнить `.env`:
   ```dotenv
   EDO_EDORU_BASE_URL=https://lk.edo.ru/api/edo/v1
   EDO_EDORU_AUTH_MODE=auth-global
   EDO_EDORU_CLIENT_ID=2PS-<...>
   EDO_EDORU_PASSWORD=<...>
   