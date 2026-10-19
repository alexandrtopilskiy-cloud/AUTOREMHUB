# AUTOREMHUB — полный исходный код 0.38.2

Версия 0.38.2 — исходники 0.37.1 (коды API db65e68c, Front 571cd023 от 15.10.2026)
с исправлениями от 19.10.2026, найденными при проверке на PHP 8.4, PostgreSQL 15
и Node 24. Перечень изменений — в разделах «Изменения 0.38.2» и «Изменения 0.38.1» ниже.

## Содержимое

- `api/` — весь исходный репозиторий Laravel: модели, сервисы, контроллеры,
  маршруты, миграции PostgreSQL, поставляемые Excel-данные, тесты, документация,
  composer.json и composer.lock.
- `front/` — весь исходный репозиторий Vue 3 + Vite: компоненты, стили,
  изображения, favicon, тесты, package.json и package-lock.json.
- `API_EXAMPLES.md` — актуальные контракты API, включая изменения 0.37.1.
- `SOURCE_VERSION.json` — точные коммиты обоих репозиториев и дата упаковки.
- `SHA256SUMS` — контрольные суммы всех файлов.

- `e2e/` — сквозная браузерная проверка маршрута и прямые API-проверки прав,
  см. `e2e/README.md`.

Это обычные исходные файлы: существующий checkout Git для их открытия не нужен.
История Git, установленные vendor/node_modules, рабочие .env, пользовательские
загрузки и дамп рабочей БД в архив не входят. Зависимости устанавливаются из
lock-файлов. Приложенные данные справочников — те, которые хранятся в коде;
данные действующей платформы хранятся в вашей PostgreSQL и облачном хранилище.

## Требования

PHP 8.4, Composer 2, PostgreSQL 15, Node.js 24.x и npm.
Обязательные PHP-расширения перечислены в `api/composer.json`.

## Локальный запуск с новой БД

Создайте отдельную PostgreSQL БД и схему с владельцем — пользователем приложения.
Их имена задаются в `api/.env`: DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_SCHEMA.
Для примера из .env.example это БД `sto`, пользователь/схема `sto_api`.
Выполнять SQL нужно от администратора PostgreSQL; пароль задайте собственный:

```sql
CREATE ROLE sto_api LOGIN PASSWORD 'ЗАМЕНИТЕ_НА_СВОЙ_ПАРОЛЬ';
CREATE DATABASE sto OWNER sto_api;
```

Подключитесь к новой БД `sto` и создайте схему:

```sql
CREATE SCHEMA sto_api AUTHORIZATION sto_api;
```

Далее в каталоге распакованного проекта:

```bash
cd api
composer install
cp .env.example .env
```

Заполните .env, затем:

```bash
php artisan key:generate
php artisan migrate
php artisan sto:tenant-create 'ООО «Авторемхаб»'
```

Последняя команда создаёт контур и организацию УК. На чистой БД ID этой первой
организации — 1; на существующей БД определите её реальный ID, не подставляйте 1
автоматически. Создайте первого пользователя УК (замените ФИО/почту/телефон):

```bash
php artisan sto:create-user UK 1 --last-name=Иванов --first-name=Иван --email=admin@example.ru --phone='+7 (999) 123 45 67'
```

Команда запросит пароль в консоли. Затем запустите API для локальной разработки:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Во второй консоли:

```bash
cd front
cp .env.example .env
npm ci
```

В `front/.env` задайте:

```dotenv
VITE_API_BASE_URL=http://127.0.0.1:8000/api
VITE_SERVICE_TIMEZONE=Europe/Moscow
```

В `api/.env` для такого запуска укажите:

```dotenv
FRONTEND_ORIGINS=http://localhost:5173
```

Запустите Front:

```bash
npm run dev -- --host localhost --port 5173
```

Откройте http://localhost:5173. Войдите созданным пользователем UK и его паролем.
Организации и остальные пользователи добавляются из ЛК УК.
Справочники можно импортировать по инструкциям проекта; запуск API сам по себе
не создаёт заполненных заявок и не восстанавливает данные рабочего сервера.
Для файлов задайте MEDIA_* с реквизитами вашего облачного хранилища.

## Production или обновление действующей установки

Для нового production-сервера настройте HTTPS, PHP-FPM/Nginx, PostgreSQL и
хранилище. API Nginx должен обслуживать из **api/public**, Front — из
**front/dist**. Для одного домена направьте `/api` в Laravel и используйте
`VITE_API_BASE_URL=/api`. В API укажите production APP_URL, FRONTEND_ORIGINS,
APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true.

Основные команды после настройки окружения:

```bash
cd api
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

```bash
cd front
npm ci
npm run build
```

На существующей установке сначала сохраните резервную копию БД и файлов.
Сохраните её .env и APP_KEY; **не выполняйте key:generate, migrate:fresh,
создание тестового контура или очистку данных** при обновлении.
`artisan serve` и `npm run dev` служат только для разработки.

## Изменения 0.38.2

- Новая команда `php artisan sto:test-users-create --tenant=1`: по три тестовых
  пользователя на каждый ЛК с общим паролем `123456789123456789`.
  ЛК УК — `UK`, `UK1`, `UK2` (организация УК контура); ЛК Заказчика — `Zakaz`,
  `Zakaz1`, `Zakaz2` (ООО «Альфа»); ЛК СТО — `STO`, `STO1`, `STO2` (ООО «КУБ»).
  Тестовые организации создаются той же командой (`sto:test-cabinets-create`).
  Повторный запуск не меняет существующих пользователей; `--reset-password`
  возвращает общий пароль тестовым логинам. Не запускайте её в рабочей системе
  без необходимости и удалите тестовые учётные записи до начала работы.

## Изменения 0.38.1

- Миграция `2026_10_19_000030_extend_vehicle_model_engine_types`: CHECK
  `vehicle_models_engine` допускает расширенные типы двигателя (газ, газ/бензин,
  водородный), которые уже принимали API и импорт. Без неё импорт справочника ТС
  с Audi A4 «Газ/бензин» откатывался целиком (SQLSTATE 23514).
- Добавлен актуальный справочник `api/database/data/vehicle_reference_20261014.xlsx`
  (18 503 строки, 13 марок; 73 точных повтора пропускаются). Команда по умолчанию
  по-прежнему берёт файл от 05.10; новый указывается путём.
- Дата оплаты: выбор даты/времени больше не требует «сейчас + 30 минут»; для оплаты
  разрешено прошлое местное время и запрещено будущее (сервер и раньше отклонял
  будущую дату — Заказчик не мог подтвердить оплату через интерфейс).
- Черновик, хоть раз переданный по маршруту и возвращённый в черновик, больше не
  удаляется (`DRAFT_DELETE_FORBIDDEN`, `canDeleteDraft=false`).
- `front/package-lock.json`: несуществующая в npm версия magic-string 0.36.11
  заменена на 0.30.21 (требование @vue/compiler-sfc 3.5.42); `npm ci` снова работает.
- Устаревшие тесты API приведены к правилам 0.37.1 (см. отчёт).

## Проверки

Frontend:

```bash
cd front
npm test
npm run build
```

API — в отдельной тестовой копии с отдельной тестовой БД/схемой PostgreSQL,
по `api/.env.testing.example` (не направляйте тесты в production-БД):

```bash
cd api
composer install
php artisan config:clear
php artisan migrate --env=testing --force
php vendor/bin/phpunit --filter 'SupplierAndRecommendationsTest|WorkCatalogDomainTest|CatalogServiceTest|CatalogServiceRecordsTest|(?<!Vehicle)CatalogControllerTest|PartControllerTest|PartManufacturerServiceTest|RecommendationAndEstimateTest|WorkflowPermissionsServiceTest|StationDecisionServiceTest'
composer lint
```

Прогон 19.10.2026 на нативном PostgreSQL 15.19, PHP 8.4.11, Node 24.22:
API — 749 тестов / 8693 утверждения, 1 пропущен (нет исходного файла
`../imports/vehicles-test.xlsx`, механизм покрыт VehicleImporterTest);
`composer lint` без ошибок; Front — 89 тестов и production-сборка.
Сквозной маршрут в Chromium и 27 API-проверок — `e2e/`.

Начните с этого файла. Остальные документы `api/docs` и `front/docs` содержат
подробности отдельных подсистем и историю изменений; они не являются списком
команд, которые нужно последовательно выполнить все подряд.
