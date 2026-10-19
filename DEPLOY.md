# AUTOREMHUB 0.38.2 — установка на сервер

Архив уже собран для сервера: в `api/vendor` лежат production-зависимости PHP
(без dev-пакетов), в `front/dist` — собранный интерфейс для схемы «один домен,
API по адресу `/api`». На сервере не нужны ни Composer, ни Node.js.
Исходники Front (`front/src`) оставлены для пересборки при необходимости.

Что внутри, кроме кода:

| Файл | Назначение |
|---|---|
| `api/.env.production.example` | пример настроек API для сервера (без паролей) |
| `deploy/nginx-autoremhub.conf` | пример сайта Nginx: Front + `/api` на одном домене |
| `deploy/php-fpm-autoremhub.ini` | лимиты загрузки файлов (вложения до 100 МБ) |
| `api/database/data/vehicle_reference_20261014.xlsx` | справочник ТС от 14.10.2026 |
| `SHA256SUMS` | контрольные суммы файлов архива |

## Требования к серверу

- PHP 8.4 (FPM и CLI) с расширениями: pgsql/pdo_pgsql, bcmath, mbstring, xml/dom,
  zip, curl, intl, fileinfo, openssl. Ubuntu/Debian:
  `apt install php8.4-fpm php8.4-cli php8.4-pgsql php8.4-bcmath php8.4-mbstring php8.4-xml php8.4-zip php8.4-curl php8.4-intl`
- PostgreSQL 15
- Nginx и HTTPS-сертификат для домена

Ниже каталог установки — `/var/www/autoremhub`, пользователь PHP-FPM — `www-data`.

## Обновление действующей установки (0.37.x / 0.38.1 → 0.38.2)

1. Сделайте резервную копию БД (`pg_dump`) и каталога `api/storage`.
2. Сохраните действующий `api/.env` — **APP_KEY менять нельзя**.
3. Распакуйте архив во временный каталог и замените код, сохранив `api/.env`
   и `api/storage` старой установки:

```bash
unzip AUTOREMHUB-0.38.2-server.zip -d /tmp/arh-new
rsync -a --delete --exclude 'api/.env' --exclude 'api/storage/' \
  /tmp/arh-new/AUTOREMHUB-0.38.2/ /var/www/autoremhub/
cd /var/www/autoremhub/api
chown -R www-data:www-data storage bootstrap/cache
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
systemctl reload php8.4-fpm
```

`migrate --force` применит одну новую миграцию
`2026_10_19_000030_extend_vehicle_model_engine_types` (типы двигателя газ,
газ/бензин, водородный). Данные она не трогает.

4. Загрузите справочник ТС от 14.10.2026 (без него новые марки Audi, BMW,
   Mercedes-Benz не появятся). Сначала проверка без записи, потом загрузка:

```bash
sudo -u www-data php artisan sto:vehicle-reference-import database/data/vehicle_reference_20261014.xlsx --dry-run
sudo -u www-data php artisan sto:vehicle-reference-import database/data/vehicle_reference_20261014.xlsx
```

**При обновлении не выполняйте** `key:generate`, `migrate:fresh`, `sto:tenant-create`
и команды тестовых кабинетов: они либо сломают вход, либо создадут лишние данные.

## Новая установка

### 1. База данных

От администратора PostgreSQL (пароль задайте свой):

```sql
CREATE ROLE sto_api LOGIN PASSWORD '<пароль>';
CREATE DATABASE sto OWNER sto_api;
\c sto
CREATE SCHEMA sto_api AUTHORIZATION sto_api;
```

### 2. Файлы и настройки

```bash
unzip AUTOREMHUB-0.38.2-server.zip -d /tmp/arh-new
mkdir -p /var/www/autoremhub
cp -a /tmp/arh-new/AUTOREMHUB-0.38.2/. /var/www/autoremhub/
cd /var/www/autoremhub/api
cp .env.production.example .env
nano .env        # домен в APP_URL и FRONTEND_ORIGINS, DB_PASSWORD
chown -R www-data:www-data storage bootstrap/cache
chown www-data:www-data .env && chmod 640 .env
```

### 3. Инициализация

```bash
cd /var/www/autoremhub/api
sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan sto:tenant-create 'ООО «Авторемхаб»'
```

Последняя команда создаёт контур и организацию УК и печатает их ID (на чистой БД
организация УК получает ID 1). Начальные справочники и первый пользователь УК:

```bash
sudo -u www-data php artisan sto:reference-seed 1
sudo -u www-data php artisan catalog:seed-part-manufacturers --tenant=1
sudo -u www-data php artisan sto:vehicle-reference-import database/data/vehicle_reference_20261014.xlsx
sudo -u www-data php artisan sto:create-user <логин> 1 --last-name=<Фамилия> --first-name=<Имя> --email=<почта> --phone='+7 (999) 123 45 67'
```

`sto:create-user` спросит пароль в консоли. Если нужны тестовые
пользователи UK, Zakaz, STO, выполните здесь же
`sudo -u www-data php artisan sto:test-users-create --tenant=1` (подробнее ниже). Остальные организации и пользователи
добавляются из кабинета УК.

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
```

### 4. PHP-FPM и Nginx

```bash
cp /var/www/autoremhub/deploy/php-fpm-autoremhub.ini /etc/php/8.4/fpm/conf.d/90-autoremhub.ini
cp /var/www/autoremhub/deploy/nginx-autoremhub.conf /etc/nginx/sites-available/autoremhub.conf
nano /etc/nginx/sites-available/autoremhub.conf   # домен и пути к сертификату
ln -s /etc/nginx/sites-available/autoremhub.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx && systemctl restart php8.4-fpm
```

Откройте `https://<ваш-домен>` и войдите пользователем УК. В подвале должна быть
надпись `ver. 0.38.2`.

## Тестовые пользователи

Команда создаёт по три пользователя на каждый личный кабинет, пароль у всех
один: `123456789123456789`.

| ЛК | Логины | Организация |
|---|---|---|
| Управляющая компания | `UK`, `UK1`, `UK2` | организация УК контура |
| Заказчик | `Zakaz`, `Zakaz1`, `Zakaz2` | ООО «Альфа» |
| СТО | `STO`, `STO1`, `STO2` | ООО «КУБ» |

```bash
cd /var/www/autoremhub/api
sudo -u www-data php artisan sto:test-users-create --tenant=1
```

`--tenant` — ID контура УК (на чистой БД это 1, команда `sto:tenant-create`
печатает его). Тестовые организации ООО «Альфа», «Бетта», «КУБ», «Овал»
создаются той же командой, если их ещё нет. Повторный запуск безопасен: уже
существующие логины не меняются. Чтобы вернуть общий пароль тестовым
логинам, добавьте `--reset-password`.

Пароль общий и опубликован в этой инструкции, поэтому в рабочей системе
отключите тестовых пользователей в кабинете УК до начала реальной работы.

## Если API на отдельном домене

`front/dist` собран под адрес API `/api`. Для другого адреса пересоберите Front
на любой машине с Node.js 24:

```bash
cd front
VITE_API_BASE_URL=https://api.<домен>/api VITE_SERVICE_TIMEZONE=Europe/Moscow npm ci && npm run build
```

и укажите адрес интерфейса в `FRONTEND_ORIGINS` в `api/.env`.

## Что важно знать

- Вложения (PDF, фото, акты) хранятся в `api/storage/app/media`. Этот каталог нужно
  включить в резервное копирование; S3 в версии 0.38.2 не подключён.
- После любой правки `api/.env` выполните `php artisan config:cache`.
- `php artisan serve` и `npm run dev` — только для разработки, не для сервера.
- Тестовые пользователи создаются только командой `sto:test-users-create` (раздел «Тестовые пользователи»); сами по себе при установке они не появляются.
