# AUTOREMHUB — развёртывание и восстановление

Единый справочник по развёртыванию платформы, резервному копированию
и аварийному восстановлению. Проверено по фактической инфраструктуре
на 16.10.2026.

## 1. Инфраструктура

| Роль | Хост | Провайдер | Что делает |
| --- | --- | --- | --- |
| **prod (vm1)** | avtoharlem.fvds.ru (153.76.167.176) | firstVDS | Прод-платформа, БД, приём заявок |
| **standby (vm2)** | autoremhub (81.161.118.132) | dc-complete | Резервные дампы, staging-БД, отдача фронта |
| **dev (локально)** | рабочая станция разработчика | — | Разработка, локальная копия БД, автономный дамп |

**Стек на vm1** (нативный, без docker):
- Ubuntu 24.04 LTS
- Nginx 1.27+
- PHP 8.4-FPM
- PostgreSQL 16
- Laravel 13 / Vue 3

**Стек на vm2** (нативный):
- Ubuntu 24.04 LTS
- Nginx (только отдача `dist/`)
- PHP 8.4-FPM (staging-инстанс API)
- PostgreSQL 16 (staging-БД `msto`)

**Ключевое:**
- vm1 и vm2 — **разные провайдеры** (firstVDS / dc-complete).
- vm2 **pull**-ит данные с vm1, а не наоборот.
- Локальная машина **pull**-ит с vm2 (через SSH).

## 2. Резервное копирование

### 2.1. Три уровня защиты

| Уровень | Где | Что | Retention |
| --- | --- | --- | --- |
| **1. Прод** | vm1 `/srv/backups/db/` | Cron 02:00, `pg_dump -Fc` | 14 дней |
| **2. Резерв** | vm2 `/srv/backups/db/` | Cron 03:00, `pg_dump` по TCP с vm1 | 14 дней |
| **3. Локально** | `~/autoremhub-backups/` | Pull с vm2 по SSH | 30 дней |

Плюс на vm2 (через `backup-configs-pull.sh`, cron 03:30):
- `/srv/backups/configs/nginx/` — конфиги Nginx с vm1;
- `/srv/backups/configs/postgresql/` — `postgresql.conf`, `pg_hba.conf`;
- `/srv/backups/configs/msto_api.env` — `.env` API;
- `/srv/backups/files/media/` — пользовательские вложения.

Плюс обновление фронта на vm2 (cron 04:30):
- `rsync -az --delete vm1:/srv/msto/dist/ /srv/msto/dist/`

### 2.2. Что НЕ дублируется

| Что | Где живёт | Почему не в бэкапах |
| --- | --- | --- |
| **Код API/Frontend** | GitLab (`vomehad/msto_api`, `vomehad/msto`) | Версионируется в git |
| **Composer/npm lock** | В git | Версионируется |
| **Бинарные зависимости (vendor/, node_modules/)** | Восстанавливаются из lock-файлов | Не нужно дублировать |

**Важно:** убедиться, что на vm1 нет незапушенных коммитов.
Проверка описана в разделе 6.

### 2.3. Восстановление staging на vm2

Cron 04:00 (`refresh-staging.sh`):
1. Находит последний дамп в `/srv/backups/db/`.
2. `pg_terminate_backend` на активные подключения к `msto`.
3. `dropdb msto` + `createdb msto` (staging-БД).
4. `pg_restore` из дампа с `--no-owner --role=msto`.
5. `GRANT` прав на `public` schema.
6. Логирует количество пользователей после восстановления.

**Требование:** на vm2 в `/etc/sudoers.d/alexa-postgres` должны быть NOPASSWD-права для `alexa` на команды `psql`, `pg_restore`, `dropdb`, `createdb` от имени `postgres`. Файл должен иметь права `0440`.

## 3. Развёртывание с нуля

### 3.1. Быстрый путь (восстановление из дампа)

Рекомендуется, если есть свежий дамп и нужно поднять сервер в течение часа.

```bash
# 1. Установить ОС, PHP 8.4, PostgreSQL 16, Nginx, Node 24
#    (детали — в AUTOREMHUB_DEPLOYMENT_2026-10-14.md)

# 2. Клонировать код
git clone --branch main git@gitlab.com:vomehad/msto_api.git /srv/msto_api
git clone --branch main git@gitlab.com:vomehad/msto.git     /srv/msto

# 3. composer install, npm ci, npm run build
cd /srv/msto_api && composer install --no-dev --prefer-dist --optimize-autoloader
cd /srv/msto && npm ci && npm run build

# 4. Создать роль и БД PostgreSQL
sudo -u postgres createuser --pwprompt msto
sudo -u postgres createdb --owner=msto --encoding=UTF8 msto

# 5. Восстановить из дампа
sudo -u postgres pg_restore --exit-on-error --no-owner --no-privileges \
  --role=msto -d msto /path/to/msto_YYYY-MM-DD_HH-MM-SS.dump

# 6. Настроить .env (см. .env.example + AUTOREMHUB_DEPLOYMENT_2026-10-14.md)
cp .env.example .env && nano .env
# Важно: скопировать APP_KEY из старого .env (из /srv/backups/configs/msto_api.env)
#        либо, если новая инсталляция — php artisan key:generate --force

# 7. Применить миграции (если дамп старше кода)
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan up

# 8. Nginx + PHP-FPM (см. конфиги из /srv/backups/configs/)
sudo cp /srv/backups/configs/nginx/* /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/msto /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# 9. Certbot для HTTPS
sudo certbot --nginx -d autoremhub.ru --redirect
```
