# AUTOREMHUB — полный исходный код 0.37.1

Архив подготовлен 17.10.2026 из версии кода, выданной 15.10.2026.
Код не изменялся, номер версии остаётся **0.37.1**.

## Содержимое

- `api/` — весь исходный репозиторий Laravel: модели, сервисы, контроллеры,
  маршруты, миграции PostgreSQL, поставляемые Excel-данные, тесты, документация,
  composer.json и composer.lock.
- `front/` — весь исходный репозиторий Vue 3 + Vite: компоненты, стили,
  изображения, favicon, тесты, package.json и package-lock.json.
- `API_EXAMPLES.md` — актуальные контракты API, включая изменения 0.37.1.
- `SOURCE_VERSION.json` — точные коммиты обоих репозиториев и дата упаковки.
- `SHA256SUMS` — контрольные суммы всех файлов.

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

При подготовке версии 15.10.2026 прошли 129 тестов API / 393 утверждения,
88 тестов Front и production-сборка. API проверялся через pgsql на PGlite;
проверка именно на нативном PostgreSQL 15 тогда не выполнялась.
Полный suite не объявлялся успешным: три старых теста VehicleCatalogControllerTest
получали 422 вместо 201 также на исходной 0.36.1. Упаковка 17.10.2026 не меняет
код и не является повторным прогоном этих тестов. Проверена полнота файлов
архива и их точное соответствие коммитам.

Начните с этого файла. Остальные документы `api/docs` и `front/docs` содержат
подробности отдельных подсистем и историю изменений; они не являются списком
команд, которые нужно последовательно выполнить все подряд.
