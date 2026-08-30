#!/usr/bin/env bash
# Обновляет dev свежей копией prod: загрузки + база + повторная изоляция.
# Код (тема, mu-plugins) НЕ трогается — он приходит из git.
# Направление всегда одно: prod -> dev. Обратно базу не льём никогда.
set -euo pipefail

BASE=/data02/virt122995/domeenid/www.positum.ee
PROD="$BASE/htdocs"
DEV="$BASE/dev"
BK=/data02/virt122995/backups
PROD_URL='https://positum.ee'
DEV_URL='https://dev.positum.ee'
PROD_URL_ESC='https:\/\/positum.ee'
DEV_URL_ESC='https:\/\/dev.positum.ee'
STAMP=$(date +%F-%H%M)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
mkdir -p "$BK"

echo "==> 1/5 бэкап текущей dev-базы"
wp --path="$DEV" db export "$BK/dev-before-sync-$STAMP.sql" --single-transaction --quick
gzip -f "$BK/dev-before-sync-$STAMP.sql"

echo "==> 2/5 дамп prod"
wp --path="$PROD" db export "$TMP/prod.sql" --single-transaction --quick --default-character-set=utf8mb4

echo "==> 3/5 загрузки prod -> dev"
rsync -a --delete --exclude 'wc-logs/' --exclude 'et_temp/' \
  "$PROD/wp-content/uploads/" "$DEV/wp-content/uploads/"

# Elementor keeps generated CSS with absolute URLs, and search-replace only
# touches the database. Fonts referenced from the production domain are then
# blocked by CORS on dev and the site falls back to system faces.
echo "==> 3.5/5 адреса в сгенерированном CSS Elementor"
find "$DEV/wp-content/uploads/elementor" -name '*.css' -type f   -exec sed -i "s#$PROD_URL#$DEV_URL#g" {} +

echo "==> 4/5 импорт базы и смена адресов"
wp --path="$DEV" db clean --yes
wp --path="$DEV" db import "$TMP/prod.sql"
# Обычные URL:
wp --path="$DEV" search-replace "$PROD_URL" "$DEV_URL" \
  --all-tables --precise --skip-columns=guid --report-changed-only
# JSON-экранированные — так Elementor и JetEngine хранят ссылки в postmeta:
wp --path="$DEV" search-replace "$PROD_URL_ESC" "$DEV_URL_ESC" \
  --all-tables --precise --skip-columns=guid --report-changed-only

echo "==> 5/5 изоляция dev"
PREFIX=$(wp --path="$DEV" db prefix)
wp --path="$DEV" plugin deactivate w3-total-cache all-in-one-wp-migration || true
rm -f "$DEV/wp-content/advanced-cache.php" "$DEV/wp-content/object-cache.php"
rm -rf "$DEV/wp-content/cache"
wp --path="$DEV" option update blog_public 0
wp --path="$DEV" option update blogname "[DEV] $(wp --path="$PROD" option get blogname)"
# Гасим очередь: там могут висеть письма клиентам и вебхуки, унаследованные от prod.
wp --path="$DEV" db query \
  "UPDATE ${PREFIX}actionscheduler_actions SET status='canceled' WHERE status IN ('pending','in-progress');"
wp --path="$DEV" transient delete --all
wp --path="$DEV" cache flush || true

echo "==> готово. Проверьте https://dev.positum.ee/"
