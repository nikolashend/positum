#!/usr/bin/env bash
# Выкатывает код из git на prod. Запускать на сервере.
# Переносит ТОЛЬКО код. Содержимое базы (страницы Elementor, товары,
# настройки JetEngine) этим скриптом не переносится — см. README.
set -euo pipefail

BASE=/data02/virt122995/domeenid/www.positum.ee
PROD="$BASE/htdocs"
BK=/data02/virt122995/backups
BRANCH="${1:-main}"
STAMP=$(date +%F-%H%M)
mkdir -p "$BK"

echo "==> бэкап prod перед выкаткой"
wp --path="$PROD" db export "$BK/prod-db-before-deploy-$STAMP.sql" --single-transaction --quick
gzip -f "$BK/prod-db-before-deploy-$STAMP.sql"
tar -czf "$BK/prod-code-before-deploy-$STAMP.tar.gz" \
  -C "$PROD" wp-content/themes/wportfolio wp-content/mu-plugins

echo "==> локальные изменения на prod (должно быть пусто):"
git -C "$PROD" status --short
echo "==> сейчас: $(git -C "$PROD" rev-parse --short HEAD)"

echo "==> выкатываем origin/$BRANCH"
git -C "$PROD" fetch origin
# --ff-only: если на prod правили файлы руками, выкатка остановится,
# а не затрёт правки молча.
git -C "$PROD" merge --ff-only "origin/$BRANCH"

echo "==> сброс кэша"
wp --path="$PROD" cache flush || true
wp --path="$PROD" w3-total-cache flush all 2>/dev/null || true

echo "==> готово: $(git -C "$PROD" rev-parse --short HEAD)"
