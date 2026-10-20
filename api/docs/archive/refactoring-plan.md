# План рефакторинга кода

Рабочая ветка: `refactor/2026-q3`
Дата старта: 2026-09-21
Целевая дата: 2026-11-15
Связанный документ: `infrastructure-plan.md`

## Общие принципы

- Контракт API **не меняется**. Структура JSON-ответов и запросов — та же.
- Тесты проходят после каждого этапа.
- Один этап = один PR.
- Промежуточные коммиты внутри этапа.
- Никаких «заодно поправил».
- Инфраструктурные работы ведутся параллельно и описаны в `infrastructure-plan.md`. Они не блокируют рефакторинг кода, кроме явно отмеченных случаев.

## Стек и стандарты

- PHP 8.4, Laravel 13, PostgreSQL 15
- PSR-12, ESLint + Prettier
- Composition API для Vue
- Enum для всех фиксированных значений
- `JsonResource` для ответов
- `FormRequest` + `Rule` для валидации
- `*Service.php` — единый суффикс сервисов

## Этапы

### Этап 0. Тесты (фундамент)

**Цель:** зафиксировать текущий контракт и поведение до начала рефакторинга.

- [x] 0.1. Настроить `phpunit.xml`, покрытие (PCOV)
- [x] 0.2. Feature-тесты для контроллеров:
    - [x] `DiagnosticControllerTest` (10 тестов)
    - [x] `InvoiceControllerTest` (19 тестов)
    - [x] `DocumentLockControllerTest` (16 тестов)
    - [x] `AttachmentControllerTest` (24 теста)
- [x] 0.3. Feature-тесты для оставшихся контроллеров:
    - [x] `VehicleControllerTest` (25 тестов)
    - [x] `OrganizationPersonControllerTest` (22 теста)
    - [x] `AuthControllerTest` (17 тестов)
    - [x] `CatalogControllerTest` (35 тестов)
    - [x] `VehicleCatalogControllerTest` (20 тестов)
    - [x] `PartControllerTest` (18 тестов)
    - [x] `RepairRequestControllerTest` (28 тестов)
- [x] 0.4. Feature-тесты для сервисов-импортёров:
    - [x] `TimeNormImporterTest`
    - [x] `VehicleImporterTest`
    - [x] `VehicleReferenceImporterTest`
    - [x] `ChangeRegistryServiceTest`
- [x] 0.5. Проверка `integrity.test.js` перенесена на Этап 7.1, поскольку файл
    временно удалён в рамках Этапа 1.8
- [x] 0.6. CI-гейт: Classes ≥ 60%, Lines ≥ 85%

### Этап 1. Инфраструктура кода

- [x] 1.1. Создать каталоги (`Enums/`, `DTO/`, `ValueObjects/`, `Policies/`, `Rules/`, `Resources/`, `Requests/*`)
- [x] 1.2. Создать Enum-классы (12 шт.)
- [x] 1.3. Миграции с CHECK-констрейнтами
- [x] 1.4. Аудит индексов
- [x] 1.5. Обновить `casts()` в моделях
- [x] 1.6. Заменить строковые литералы на enum
- [x] 1.7. Обновить `Workflow`
- [x] 1.8. Снести `integrity.test.js` (временно, вернуть на Этапе 7)

### Этап 2. FormRequest + Rule

- [ ] 2.1. Создать `Rule`-классы
- [ ] 2.2. Создать `FormRequest` для всех эндпоинтов
- [ ] 2.3. Перенести валидацию из `ApiInput`/`DomainInput`
- [ ] 2.4. Убрать `Input::validate()` из сервисов
- [ ] 2.5. Убрать `$request->validate()` из контроллеров

### Этап 3. JsonResource + Policy

- [ ] 3.1. Создать `JsonResource` для всех сущностей
- [ ] 3.2. Переключить контроллеры
- [ ] 3.3. Удалить `ApiPresenter`
- [ ] 3.4. Создать `Policy`-классы
- [ ] 3.5. Route-model binding
- [ ] 3.6. Проверить N+1

### Этап 4. Сервисы + DTO/VO

- [ ] 4.1. Переименовать сервисы в `*Service.php`
- [ ] 4.2. Разбить `RepairRequestService`
- [ ] 4.3. Разбить `CatalogService`
- [ ] 4.4. Разбить `VehicleService`
- [ ] 4.5. Создать DTO/VO
- [ ] 4.6. `Unit`-тесты для сервисов

### Этап 5. CQRS Lite (опционально)

**Условие старта:** после Этапа 4 в `infrastructure-plan.md` (Redis + расширение) пропускная способность всё ещё ниже 100-150 RPS.

- [ ] 5.1. Определить горячие эндпоинты по метрикам
- [ ] 5.2. Создать Query-сервисы для чтения
- [ ] 5.3. Денормализованные таблицы / materialized views
- [ ] 5.4. Синхронизация через события моделей + очередь
- [ ] 5.5. Замеры

### Этап 6. Frontend

- [ ] 6.1. Composables (`useAuth`, `useTabs`, ...)
- [ ] 6.2. Разбить `App.vue`
- [ ] 6.3. Разбить `RepairEditor.vue`
- [ ] 6.4. Разбить `PartsPanel.vue`
- [ ] 6.5. Composition API везде
- [ ] 6.6. Убрать мутации props
- [ ] 6.7. `AbortController` вместо `sequence`

### Этап 7. Финализация

- [ ] 7.1. Вернуть `integrity.test.js`
- [ ] 7.2. Обновить документацию
- [ ] 7.3. Финальный end-to-end прогон
- [ ] 7.4. Проверка: контракт API не изменился
- [ ] 7.5. Релиз в `main`, тег

## Definition of Done (рефакторинг)

**Код:**
- [ ] Все enum-классы созданы и используются
- [ ] CHECK-констрейнты накатаны
- [ ] Вся валидация — в `FormRequest` + `Rule`
- [ ] Все ответы — через `JsonResource`
- [ ] `ApiPresenter`, `ApiInput` удалены
- [ ] Все сервисы с суффиксом `Service`
- [ ] Ни одного файла > 300 строк
- [ ] Frontend на Composition API
- [ ] `integrity.test.js` проходит
- [ ] Контракт API не изменился

**Тесты:**
- [ ] Feature-тесты на все контроллеры
- [ ] Unit-тесты для `Rule`, `Policy`, `Calculator`
- [ ] Coverage: Classes ≥ 75%, Lines ≥ 88%
- [ ] CI-гейт по Classes и Lines (не по Methods)

**Процесс:**
- [ ] Один этап = один PR
- [ ] Никаких «заодно поправил»
- [ ] Промежуточные коммиты внутри этапа

## Зависимости от инфраструктуры

- **Этап 0.6 (CI-гейт)** — требует настройки CI, см. `infrastructure-plan.md`, Этап 2.8.
- **Этап 5 (CQRS Lite)** — стартует только если Этапы 3-4 инфраструктурного плана не дали нужной производительности.
- **Этап 7.5 (релиз)** — требует стабильной инфраструктуры: SSL, мониторинг, Redis, расширенный сервер.

## Ограничения инструментов

- **PCOV занижает Methods для контроллеров.** Методы, вызываемые через `$container->call()`, не помечаются как «покрытые», даже если их строки исполнены. Поэтому CI-гейт — по Classes и Lines. Methods использовать информативно.
