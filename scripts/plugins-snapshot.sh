#!/usr/bin/env bash
# Записывает версии ядра, темы и плагинов prod в scripts/plugins.txt.
# Этот файл — эталон: dev должен совпадать с prod по версиям.
set -euo pipefail
PROD=/data02/virt122995/domeenid/www.positum.ee/htdocs
OUT="$(cd "$(dirname "$0")" && pwd)/plugins.txt"
{
  echo "# Снимок версий prod. Обновлять: scripts/plugins-snapshot.sh"
  echo "# core $(wp --path="$PROD" core version)"
  wp --path="$PROD" plugin list --fields=name,status,version --format=csv
} > "$OUT"
echo "записано в $OUT"
