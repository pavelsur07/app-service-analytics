#!/bin/sh
# Собирает пакет для внешнего ревью в var/review/package.md.
# Структура пакета — docs/review-package-template.md; этот скрипт
# автоматизирует её сборку из текущего состояния репозитория.
# Вызывается через `make review-prepare TASK="..."`, не напрямую.
# sh + GNU coreutils (xargs -r) — весь стек здесь Linux (хост и
# контейнеры), переносимость на BSD/macOS не нужна.
set -eu

cd "$(dirname "$0")/.."

: "${TASK:?TASK не задан. Использование: make review-prepare TASK=\"одним абзацем, что и зачем\"}"

review_dir="var/review"
mkdir -p "$review_dir"
out="$review_dir/package.md"

# git diff HEAD не видит untracked-файлы, а на момент ревью задача обычно
# ещё не закоммичена (см. CLAUDE.md, «Цикл работы»: ревью — шаг 6,
# коммит — отдельная, более поздняя команда). Временный индекс даёт диф
# со всеми новыми файлами, не трогая реальный индекс репозитория.
# var/ в .gitignore, поэтому предыдущий package.md сюда не попадёт.
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT INT TERM
tmp_index="$tmp_dir/index"
diff="$(GIT_INDEX_FILE="$tmp_index" sh -c 'git add -A && git diff --cached HEAD')"

# Заголовок исходной секции в вывод не идёт — иначе он дублирует
# собственный заголовок "## 3. Обязательные правила" ниже.
rules="$(awk '
    /^## Обязательные правила/ { p = 1; next }
    p && /^## / { exit }
    p
' CLAUDE.md)"

# ADR: по умолчанию все — программно определить «затронутые ревью область»
# нельзя без понимания смысла изменения, а ADR короткие (в сумме ~800
# строк), полный набор не раздувает пакет критично. Сузить точечно:
# make review-prepare TASK="..." ADR="0006 0007".
if [ -n "${ADR:-}" ]; then
    adrs=""
    for n in $ADR; do
        match="$(find docs/adr -maxdepth 1 -name "$n-*.md")"
        if [ -z "$match" ]; then
            echo "review-prepare: ADR '$n' не найден в docs/adr/" >&2
            exit 1
        fi
        adrs="$adrs
$(cat "$match")"
    done
else
    adrs="$(find docs/adr -maxdepth 1 -name '*.md' \
        ! -name '0000-template.md' ! -name 'README.md' | sort | xargs -r cat)"
fi

{
    echo "# Пакет для внешнего ревью"
    echo
    echo "## 1. Задача"
    echo
    printf '%s\n' "$TASK"
    echo
    echo "## 2. Диф"
    echo
    # Ограда ~~~, не ``` — диф часто трогает docs/*.md, а те сами содержат
    # ```-ограды (примеры конфигов, кода); ``` внутри диффа закрыл бы блок
    # раньше времени. ~~~ в содержимом diff/markdown этого репозитория не
    # встречается. Ceiling: если когда-нибудь встретится и ~~~ — считать
    # длину самой длинной последовательности ~ в $diff и брать на один больше.
    echo '~~~diff'
    printf '%s\n' "$diff"
    echo '~~~'
    echo
    echo "## 3. Обязательные правила"
    echo
    printf '%s\n' "$rules"
    echo
    echo "## 4. Релевантные ADR"
    echo
    printf '%s\n' "$adrs"
    echo
    echo "## 5. Инструкция ревьюеру"
    echo
    # Общая часть роли не задаёт: инструкция роли живёт в разделах 5A
    # и 5B шаблона и добавляется целями review-codex / review-kimi.
    # Правила и ADR прилагаются обоим — второму не для проверки
    # соответствия, а чтобы он не предлагал уже отвергнутое
    # (CLAUDE.md, «Роли инструментов разведены»).
    echo "Не предлагай общепринятые альтернативы (API Platform, findAll(),"
    echo "Redux для серверного состояния, Symfony Forms, глобальные"
    echo "фикстуры — здесь отвергнуты сознательно, см. разделы 3-4)."
    echo
    echo "Если не согласен с самим правилом — скажи об этом отдельным пунктом"
    echo "«пробел в правилах», а не оформляй как дефект кода."
    echo
    echo "Ты не запускаешь тесты и не собираешь проект — только читаешь."
} > "$out"

echo "Пакет собран: $out"
