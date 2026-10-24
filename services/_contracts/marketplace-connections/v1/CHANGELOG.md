# marketplace-connections — CHANGELOG

## v1 (2026-10-24) — проект, форма Conwix

Перенесён из ВашФинДир (`app-service-finance`, коммит `a9eb74206`) с
адаптацией к Conwix (ADR-043 п. 4). Отличия от источника:

- `connection_ref` заменён на `marketplace_account_id` — UUID подключения
  Conwix; `company_id` — UUID компании. Оба проверяются по форме UUID.
- **Пагинация.** Ответ — страница, а не полный снимок: keyset по
  `marketplace_account_id`, `limit` по умолчанию 50, максимум 200, больше —
  422 (CLAUDE.md §5); обязательное поле `next_cursor`. В источнике список
  отдавался одним ответом.
- Путь — `/api/internal/marketplace-connections` (у источника —
  `/internal/marketplace-connections`).
- `provider` — только `ozon`: в выдаче Conwix других площадок нет.
- Подключение — одна запись `MarketplaceAccount` с обоими типами ключей.
  У источника кабинет собирался из двух подключений компании.
- Пропавшее из выдачи подключение сразу теряет ключи; кэш без подтверждения
  живёт ограниченно (`CONNECTIONS_MAX_STALE`) — правила потребителя,
  в источнике кэш жил без срока.
