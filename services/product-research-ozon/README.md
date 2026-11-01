# product-research-ozon

Этап 0: зафиксированные данные, hash-lock проверенного Python-стека и
офлайн-приёмка. Runtime-приложение, контейнеры сервиса и миграции не созданы.
ADR-050 проходит финальное независимое ревью.

План: [product-research-ozon](../../docs/plan/product-research-ozon.md).
Проверка API: [отчёт REST](../../docs/plan/product-research-ozon-rest-check.md).

## Фикстуры

Полный январь 2026 для категории «Хиджабы и тюрбаны женские»:
[описание набора](tests/fixtures/mpstats/ozon/category-items/2026-01/README.md).

Секрет `MPSTATS_API_TOKEN` хранится в GitHub Actions Secrets, но для чтения
фикстур не нужен. Автоматические тесты не должны обращаться к MPStats.

Владелец 31.10.2026 разрешил публикацию проверенных рыночных фикстур MPStats
этого этапа в публичном GitHub. Секреты, данные покупателей и закрытые данные
других компаний этим разрешением не охватываются; см. CLAUDE.md §9.


## Проверка подготовки

`make product-research-ozon-stage0-check` — hash-lock, pip check, imports,
audit, затем Ruff, mypy strict и тесты эталонов в контейнере без сети.
Образ CPython 3.14.10 закреплён digest; поддержана Linux x86_64.
Все 71 зависимости проверяются с hashes. Выходные материалы — в var.

Дополнительный набор: `tests/fixtures/mpstats/ozon/category-items/insect-protection/2025-02/`: оба режима API, новый ручной ZIP, прежний
диагностический CSV. Он подтверждает FBS по всем SKU без повторного API.

`stage0/contract-examples.json` — общие искусственные примеры для проектирования
PHP/Python-контрактов: пагинация, пустой ответ, отсутствие поля, неизвестные поля,
401, даты и состояния. Это не реальные ответы MPStats и не опубликованная схема;
исполняемые тесты runtime-контрактов добавляются на соответствующих этапах.
