#!/usr/bin/env bash
# AutoRemHub: установка релиза на сервере. Вызывается из GitHub Actions
# (.github/workflows/deploy.yml) по SSH от пользователя деплоя.
#
#   remote-deploy.sh <каталог> <имя-релиза> [служба-php-fpm]
#
# Раскладка каталога:
#   releases/<релиз>/   код релиза (api, front, deploy)
#   shared/.env         настройки API (общие для всех релизов)
#   shared/storage/     api/storage: вложения, логи, кеш
#   current -> releases/<релиз>   на него смотрит Nginx
#   uploads/<релиз>.tar.gz        архив из GitHub
set -euo pipefail

BASE="$1"
RELEASE="$2"
FPM="${3:-php8.4-fpm}"
KEEP=5
ARCHIVE="$BASE/uploads/$RELEASE.tar.gz"
DIR="$BASE/releases/$RELEASE"
ARTISAN=(sudo -n -u www-data php "$DIR/api/artisan")

test -f "$ARCHIVE" || { echo "Нет архива $ARCHIVE" >&2; exit 1; }
test -f "$BASE/shared/.env" || { echo "Нет $BASE/shared/.env: сервер не подготовлен (DEPLOY.md)" >&2; exit 1; }
test -d "$BASE/shared/storage" || { echo "Нет $BASE/shared/storage: сервер не подготовлен (DEPLOY.md)" >&2; exit 1; }

echo "==> Распаковка $RELEASE"
mkdir -p "$DIR"
tar -xzf "$ARCHIVE" -C "$DIR"
rm -f "$ARCHIVE"

echo "==> Общие .env и storage"
rm -rf "$DIR/api/storage"
ln -s "$BASE/shared/storage" "$DIR/api/storage"
ln -sfn "$BASE/shared/.env" "$DIR/api/.env"
chgrp -R www-data "$DIR/api/bootstrap/cache"
chmod -R g+w "$DIR/api/bootstrap/cache"

echo "==> Кеш настроек и миграции"
"${ARTISAN[@]}" optimize:clear
"${ARTISAN[@]}" migrate --force
"${ARTISAN[@]}" config:cache
"${ARTISAN[@]}" route:cache

echo "==> Переключение current"
ln -sfn "$DIR" "$BASE/current.new"
mv -Tf "$BASE/current.new" "$BASE/current"
sudo -n systemctl reload "$FPM"

echo "==> Удаление старых релизов (оставляем $KEEP)"
ls -1dt "$BASE"/releases/*/ | tail -n +$((KEEP + 1)) | xargs -r rm -rf

echo "Готово: $(cat "$DIR/REVISION")"
