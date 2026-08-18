#!/bin/sh
# Проверяет, что api/src/ содержит только Shared, Identity, Ingestion,
# PriceMonitoring и Kernel.php — ничего сверх спроектированных модулей
# (см. docs/structure.md).
# Нужна машинная проверка, а не «на глаз»: рецепты Symfony Flex заводят
# src/Entity, src/Repository, src/Controller при установке любого пакета,
# и это легко пропустить в диффе.
# POSIX sh, не bash — на php-cli (alpine) bash не установлен.
set -eu

cd "$(dirname "$0")/.."

unexpected=""
for entry in src/*; do
    name="$(basename "$entry")"
    case "$name" in
        Shared|Identity|Ingestion|PriceMonitoring|Kernel.php) ;;
        *) unexpected="$unexpected $name" ;;
    esac
done

if [ -n "$unexpected" ]; then
    echo "api/src/ содержит лишнее (не Shared/Identity/Ingestion/PriceMonitoring/Kernel.php):" >&2
    for name in $unexpected; do
        echo "  - $name" >&2
    done
    echo "Часто это рецепт Symfony Flex (src/Entity, src/Repository, src/Controller) — удалить." >&2
    exit 1
fi

echo "OK: api/src/ содержит только Shared, Identity, Ingestion, PriceMonitoring, Kernel.php"
