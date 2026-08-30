#!/usr/bin/env bash
# Подтягивает свежий коммит из GitHub на dev и сбрасывает кэш.
# Запускать на сервере (или через ssh) после git push с локальной машины.
set -euo pipefail

DEV=/data02/virt122995/domeenid/www.positum.ee/dev
BRANCH="${1:-main}"

echo "==> локальные изменения на dev (если есть — сначала закоммитьте):"
git -C "$DEV" status --short

echo "==> сейчас: $(git -C "$DEV" rev-parse --short HEAD)"
git -C "$DEV" fetch origin
# --ff-only, чтобы правки, сделанные прямо на dev, не были затёрты молча.
git -C "$DEV" merge --ff-only "origin/$BRANCH"

wp --path="$DEV" cache flush || true

echo "==> готово: $(git -C "$DEV" rev-parse --short HEAD)"
echo "==> проверяйте https://dev.positum.ee/"
