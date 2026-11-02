# product-research-ozon: правила

Читать корневые AGENTS.md/CLAUDE.md, services/AGENTS.md и services/python.md.
Основание границ — ADR-050. Этап 3 добавляет суточный company/source кэш категорий и существующий внутренний GET /categories; этап 4 — движок месячных снимков, этап 5 — admission, устойчивая очередь и background relay Symfony.

- MPStats-токен один серверный, company-scoped кэш и артефакты. Лимитер общий
  на подключение/endpoint, поскольку токен общий. Не получать ключи кабинетов
  Ozon и не использовать исключения ozon-fetcher.
- Вход: одна категория, именованные диапазоны месяцев. Единица сырья — месяц.
  FBS=1, RUB; будущие месяцы запрещены. Текущий месяц явно неполный и до вчера.
- POST /api/analytics/v1/oz/category/items, query дат/FBS/валюты, JSON body
  пагинации. Сортировка revenue DESC/id ASC обязательна; пустой filterModel.
- До проверки counts, ID и страниц снимок не публикуется. MCP с дублями не
  используется как raw REST. Процент выручки API может зависеть от страницы:
  сохранять как есть, не использовать готовой долей рынка в будущем анализе.
- Ручной CSV 41 колонки и API CSV 49 — разные профили. Соответствие в docs/plan;
  наличие FBS в строке не доказывает режим запроса.
- Секреты из GitHub Actions не выдаются тестам фикстур. Тесты этапа 0 полностью
  офлайн. `temp/` и `var/` не включать в коммиты и пакет ревью.
- Новые зависимости/связи не добавлять вне согласованного плана. Symfony владеет проектами и локальной проекцией; сервис — техническим кэшем и неизменяемым сырьём. HTTP заданий/снимков включается только явной collection-конфигурацией и allowlist; по умолчанию not_available. Worker исполняет один шаг, сроки и ожидания сохраняются в БД.

- Каталог: TTL 24 часа от generated_at, полная атомарная публикация, DB lease/fencing,
  signed cursor company/source/revision/expiry. Неполученный и stale каталог — 503,
  пустая успешная генерация — обычный пустой ответ. Суммы category/list не сохранять.
- Catalog enabled выключен по умолчанию; без явных source ID/generation/quota/секретов
  MPStats не вызывается. Живой egress/production включается на этапе 8; dev использует
  MockTransport/фикстуры, тестовый сервер не входит в runtime image.

- Месячный снимок: одна страница на step, курсор/проверка SKU в своей БД,
  S3 до DB-регистрации, manifest до ready. Никаких sleep или сетевого I/O
  в SQL-транзакции. Ожидание Retry-After сохраняется, error не считается кэшем.
- Технические бюджеты передаются явно, не выдаются за квоту провайдера.
  Существующие страницы/манифесты неизменяемы; чужой company/source/snapshot
  не раскрывает raw. После down сохранять backup журнала, иначе теряются связи с S3.

- Этап 6: closed cache slot scoped company/source + semantic MonthCacheKey,
  конкурент ждёт active owner без I/O, ready reuse учитывает новые budgets.
  Слот указывает на job, поэтому restart/rotation не оставляют старый snapshot.
  Partial всегда новый. Admission/catalog/snapshots — v1, enriched progress —
  read-only v2; v1 schema/examples заморожены. collected_at исходного snapshot
  отдельно от used_at текущего job. Python production остаётся этапом 8.
