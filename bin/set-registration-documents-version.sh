#!/bin/sh

set -eu

if [ "$#" -ne 2 ]; then
    printf 'Использование: %s <dotenv-file> <YYYY-MM-DD>\n' "$0" >&2
    exit 2
fi

dotenv=$1
version=$2

if [ ! -f "$dotenv" ]; then
    printf 'Файл production-окружения не найден: %s\n' "$dotenv" >&2
    exit 1
fi

normalized=$(date -d "$version" '+%F' 2>/dev/null) || {
    printf 'Версия документов должна быть существующей датой YYYY-MM-DD.\n' >&2
    exit 1
}
if [ "$normalized" != "$version" ]; then
    printf 'Версия документов должна быть существующей датой YYYY-MM-DD.\n' >&2
    exit 1
fi

temporary=$(mktemp "${dotenv}.tmp.XXXXXX")
cleanup() {
    rm -f -- "$temporary"
}
trap cleanup EXIT HUP INT TERM

awk -v version="$version" '
    BEGIN {
        key = "REGISTRATION_DOCUMENTS_VERSION="
        written = 0
    }
    index($0, key) == 1 {
        if (written == 0) {
            print key version
            written = 1
        }
        next
    }
    { print }
    END {
        if (written == 0) {
            print key version
        }
    }
' "$dotenv" > "$temporary"

chmod "$(stat -c '%a' "$dotenv")" "$temporary"
mv -f -- "$temporary" "$dotenv"
trap - EXIT HUP INT TERM

printf 'REGISTRATION_DOCUMENTS_VERSION обновлена.\n'
