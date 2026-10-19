#!/usr/bin/env bash
# AutoRemHub: настройка чистого сервера Ubuntu 24.04 под автодеплой из GitHub.
#
#   sudo bash server-setup.sh          подготовка сервера (до первого деплоя)
#   sudo bash server-setup.sh init     заполнение базы и первый пользователь
#                                      (после первого успешного деплоя)
#
# Повторный запуск безопасен: уже сделанные шаги пропускаются, APP_KEY и пароль
# БД не меняются.
set -euo pipefail

DOMAIN="${DOMAIN:-autoremhub.ru}"
BASE="${BASE:-/var/www/autoremhub}"
SSH_PORT="${SSH_PORT:-22}"
CREDS=/root/autoremhub-credentials.txt

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mОшибка: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || fail "запустите через sudo: sudo bash $0 ${1:-}"

init_app() {
    local api="$BASE/current/api"
    [ -f "$api/artisan" ] || fail "нет $api: сначала выполните первый деплой из GitHub Actions"
    cd "$api"
    local artisan=(sudo -u www-data php artisan)

    say "Контур и организация УК"
    "${artisan[@]}" sto:tenant-create 'ООО «Авторемхаб»'
    read -rp "ID контура из строки выше (на чистой базе 1) [1]: " tenant
    tenant="${tenant:-1}"

    say "Справочники"
    "${artisan[@]}" sto:reference-seed "$tenant"
    "${artisan[@]}" catalog:seed-part-manufacturers --tenant="$tenant"
    "${artisan[@]}" sto:vehicle-reference-import database/data/vehicle_reference_20261014.xlsx

    say "Первый пользователь УК"
    read -rp "Логин: " login
    read -rp "Фамилия: " last
    read -rp "Имя: " first
    read -rp "Почта: " email
    read -rp "Телефон (+7 (999) 123 45 67): " phone
    "${artisan[@]}" sto:create-user "$login" "$tenant" \
        --last-name="$last" --first-name="$first" --email="$email" --phone="$phone"

    "${artisan[@]}" config:cache
    say "Готово. Откройте https://$DOMAIN и войдите логином $login."
}

if [ "${1:-}" = init ]; then
    init_app
    exit 0
fi

. /etc/os-release
[ "${VERSION_CODENAME:-}" = noble ] || fail "скрипт рассчитан на Ubuntu 24.04, а здесь ${PRETTY_NAME:-неизвестная система}"

read -rp "Почта для уведомлений Let's Encrypt о сертификате: " LE_EMAIL
[ -n "$LE_EMAIL" ] || fail "почта нужна для сертификата"

say "1/7 Пакеты: PHP 8.4, PostgreSQL 15, Nginx, certbot"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq software-properties-common curl ca-certificates gnupg unzip ufw certbot
add-apt-repository -y ppa:ondrej/php
install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt noble-pgdg main" \
    > /etc/apt/sources.list.d/pgdg.list
apt-get update -q
apt-get install -yq nginx postgresql-15 \
    php8.4-fpm php8.4-cli php8.4-pgsql php8.4-bcmath php8.4-mbstring \
    php8.4-xml php8.4-zip php8.4-curl php8.4-intl
cat > /etc/php/8.4/fpm/conf.d/90-autoremhub.ini <<'INI'
upload_max_filesize = 105M
post_max_size = 110M
memory_limit = 512M
max_execution_time = 300
INI
systemctl restart php8.4-fpm

say "2/7 База данных sto"
touch "$CREDS" && chmod 600 "$CREDS"
DB_PASSWORD="$(grep -oP '^DB_PASSWORD=\K.*' "$CREDS" || true)"
if [ -z "$DB_PASSWORD" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    echo "DB_PASSWORD=$DB_PASSWORD" >> "$CREDS"
fi
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='sto_api'" | grep -q 1; then
    sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE sto_api LOGIN PASSWORD '$DB_PASSWORD'"
fi
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='sto'" | grep -q 1; then
    sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE DATABASE sto OWNER sto_api"
fi
sudo -u postgres psql -v ON_ERROR_STOP=1 -d sto -c "CREATE SCHEMA IF NOT EXISTS sto_api AUTHORIZATION sto_api"

say "3/7 Пользователь deploy и каталоги"
id deploy >/dev/null 2>&1 || adduser --disabled-password --gecos '' deploy
usermod -aG www-data deploy
install -d -o deploy -g www-data "$BASE" "$BASE/releases" "$BASE/shared" "$BASE/uploads"
cat > /etc/sudoers.d/autoremhub-deploy <<'SUDO'
deploy ALL=(www-data) NOPASSWD: /usr/bin/php
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
SUDO
chmod 440 /etc/sudoers.d/autoremhub-deploy
visudo -cq

say "4/7 SSH-ключ для GitHub"
KEY=/root/autoremhub_deploy
[ -f "$KEY" ] || ssh-keygen -q -t ed25519 -N '' -C github-deploy -f "$KEY"
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
touch /home/deploy/.ssh/authorized_keys
grep -qF "$(cat "$KEY.pub")" /home/deploy/.ssh/authorized_keys || cat "$KEY.pub" >> /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys

say "5/7 Настройки приложения (.env) и папка файлов"
install -d -o www-data -g www-data \
    "$BASE/shared/storage" "$BASE/shared/storage/app" "$BASE/shared/storage/app/media" \
    "$BASE/shared/storage/app/private" "$BASE/shared/storage/framework" \
    "$BASE/shared/storage/framework/cache" "$BASE/shared/storage/framework/cache/data" \
    "$BASE/shared/storage/framework/sessions" "$BASE/shared/storage/framework/views" \
    "$BASE/shared/storage/logs"
if [ ! -f "$BASE/shared/.env" ]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    cat > "$BASE/shared/.env" <<ENV
APP_NAME="Маркетплейс СТО API"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN
FRONTEND_ORIGINS=https://$DOMAIN
SERVICE_TIMEZONE=Europe/Moscow
VISIT_TIME_START=08:00
VISIT_TIME_END=22:00

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sto
DB_USERNAME=sto_api
DB_PASSWORD=$DB_PASSWORD
DB_SCHEMA=sto_api
DB_SSLMODE=prefer

SESSION_COOKIE=sto_session
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
CACHE_STORE=database
BCRYPT_ROUNDS=12
LOG_LEVEL=warning
DOCUMENT_LOCK_TTL_SECONDS=120
ENV
fi
chown www-data:www-data "$BASE/shared/.env" && chmod 640 "$BASE/shared/.env"

say "6/7 Firewall и HTTPS-сертификат для $DOMAIN"
ufw allow "$SSH_PORT/tcp" >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable
if [ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    certbot certonly --standalone -n -d "$DOMAIN" --agree-tos -m "$LE_EMAIL" --no-eff-email \
        --pre-hook "systemctl stop nginx" --post-hook "systemctl start nginx" \
        || fail "сертификат не выпущен: проверьте, что $DOMAIN указывает на этот сервер"
fi

say "7/7 Nginx"
cat > /etc/nginx/sites-available/autoremhub.conf <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name $DOMAIN;

    ssl_certificate     /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;

    client_max_body_size 110m;

    root $BASE/current/front/dist;
    index index.html;

    location /assets/ {
        try_files \$uri =404;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files \$uri \$uri/ /index.html;
        add_header Cache-Control "no-cache";
    }

    location ^~ /api/ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $BASE/current/api/public/index.php;
        fastcgi_param DOCUMENT_ROOT   $BASE/current/api/public;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 300;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/autoremhub.conf /etc/nginx/sites-enabled/autoremhub.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

IP="$(curl -fsS4 https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')"
say "Сервер готов. Добавьте в GitHub (Settings → Environments → production):"
cat <<EOF

Secret DEPLOY_HOST:
$IP

Secret DEPLOY_USER:
deploy

Secret DEPLOY_PORT (только если порт SSH не 22):
$SSH_PORT

Secret DEPLOY_KNOWN_HOSTS (все строки):
$(for f in /etc/ssh/ssh_host_*_key.pub; do
    if [ "$SSH_PORT" = 22 ]; then h="$IP"; else h="[$IP]:$SSH_PORT"; fi
    echo "$h $(cut -d' ' -f1,2 "$f")"
done)

Secret DEPLOY_SSH_KEY (все строки, включая BEGIN и END):
$(cat "$KEY")

Variable DEPLOY_ENABLED:
true

Пароль базы данных сохранён в $CREDS.
После первого успешного деплоя выполните: sudo bash $0 init
EOF
