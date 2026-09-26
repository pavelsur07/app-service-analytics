# Остатки Ozon FBO по кластерам — разведка

Пакет 0 плана `docs/plan/ozon-stock-placement-report.md`. Фикстуры сняты
владельцем 2026-09-26 скриптом `bin/capture-ozon-stock-fixtures.sh`
(только чтение) из кабинета первого клиента, каталог
`api/tests/Fixtures/Marketplace/ozon/stocks/2026-09-26-144016/`.

## Что снято

| Метод | Ответ | Нужен |
|---|---|---|
| `/v3/product/list`, `/v3/product/info/list` | 60 карточек, 86 SKU всех схем | только для списка SKU |
| `/v1/analytics/stocks` `{skus: [...]}` | 1152 строки, 52 SKU, 25 кластеров, 182 склада | **да** |
| `/v1/cluster/list` `{cluster_type: "CLUSTER_TYPE_OZON"}` | 22 кластера | нет, см. ниже |
| `/v2/analytics/stock_on_warehouses` | 350 строк, без кластера | нет |

Каталог уместился в одну пачку 100 SKU — признаков усечения нет, ключей
продолжения в ответе нет (`keys = ["items"]`).

## `/v1/analytics/stocks`

**Зерно — SKU × склад.** Пара `(sku, warehouse_id)` уникальна: 1152 из 1152.
У каждой строки — кластер (`cluster_id`, `cluster_name`) и макрокластер
(`macrolocal_cluster_id`).

**Остатки по видам** (все целые): `available_stock_count` (доступно к продаже),
`valid_stock_count`, `waiting_docs_stock_count`, `expiring_stock_count`,
`transit_defect_stock_count`, `stock_defect_stock_count`,
`excess_stock_count`, `other_stock_count`, `requested_stock_count`
(заявлено к поставке), `transit_stock_count` (в пути),
`return_from_customer_stock_count`, `return_to_seller_stock_count`,
`waiting_docs_to_export_stock_count`, `outbound_*`, `inbound_replenishment`,
`stock_not_being_sold`.

**Показатели Ozon:** по складу `ads`, `idc`, `days_without_sales`,
`turnover_grade`; по кластеру `ads_cluster`, `idc_cluster`,
`days_without_sales_cluster`, `turnover_grade_cluster`. Показатели кластера
одинаковы у всех складов одной пары SKU × кластер (расхождений 0 из 668
пар) — Ozon повторяет их в каждой строке.

- `ads*` — средние продажи в день, дробное JSON-число полной точности
  (`0.6666666666666666`); `null` у одной строки.
- `idc*` — дни покрытия, целое; `null`, когда продаж нет (`ads = 0`):
  7 строк по складу, 201 по кластеру.
- `turnover_grade_cluster`: `DEFICIT` 400, `WAS_DEFICIT` 420, `NO_SALES` 96,
  `POPULAR` 86, `WAS_NO_SALES` 56, `WAS_POPULAR` 39, `ACTUAL` 34,
  `WAS_SURPLUS` 8, `SURPLUS` 5, `RESTRICTED_NO_SALES` 5, `WAS_ACTUAL` 3.
- `placement_zone` — `SORT` у всех строк; `item_tags` — `MARKABLE`,
  `FBS_RETURN`.

**Пункты выдачи.** 161 из 182 «складов» — ПВЗ (`warehouse_name = "ПВЗ_<n>"`,
`warehouse_id` отрицательный). На них 88 доступных штук из 1014 (9%) — в
основном вернувшиеся от покупателя и снова доступные. Ozon относит их к
кластеру ПВЗ; продать их покупателю этого кластера можно.

**Полнота.** Нет в ответе 34 из 86 SKU: 26 — SKU других схем (не FBO), 8 —
карточки с нулевым остатком FBO по `product/info/list`. Сумма
`available_stock_count` = 1014 против 1023 штук FBO по карточкам —
расхождение на резерв и время снимка.

**Лимит.** `ratelimit-remaining: 45` после запроса (у `product/info/list` —
9). Один запрос на 100 SKU раз в сутки — несущественно для ADR-028.

## Кластеры: остатки ↔ продажи

Названия кластеров в остатках **совпадают побайтово** с
`financial_data.cluster_to/cluster_from` в продажах: 25 из 25 в обе стороны
(сверено с фикстурами `posting-fbo-list*.json`). Связь «спрос по кластеру
доставки ↔ остаток кластера» строится по названию без справочника.

`/v1/cluster/list` с типом `CLUSTER_TYPE_OZON` не содержит трёх зарубежных
кластеров (Беларусь, Алматы, Астана), которые есть и в продажах, и в
остатках. Склады, которые в нём есть, относятся к тому же `cluster_id`,
что в остатках (972 из 972). Справочник не нужен.

## `/v2/analytics/stock_on_warehouses`

Остаток по складу без кластера; названия складов в другом регистре
(`Новосибирск_РФЦ_НОВЫЙ` против `НОВОСИБИРСК_РФЦ_НОВЫЙ`), без ПВЗ.
Всё, что в нём есть, есть и в `/v1/analytics/stocks`. Не нужен.

## Чего фикстура не показывает

- `transit_stock_count` и `requested_stock_count` — нули во всех строках:
  у продавца в момент снимка не было поставок в пути. Семантика «в пути»
  подтверждается только следующим снимком с поставкой — это проверка
  пакета 4, а до неё рекомендация вычитает оба поля, как их называет
  документация.
- Пагинация при каталоге больше 100 SKU — не наблюдалась; загрузка идёт
  пачками SKU, и у каждого ответа проверяется отсутствие ключей
  продолжения.

## §9

Данных покупателей и третьих лиц нет: поля `name` — названия товаров,
кластеров и складов. Заголовки ответов без реквизитов.

## Что предлагается

ADR-034: источник — `/v1/analytics/stocks`, зерно факта — снимок дня ×
SKU × склад с кластером в строке; ПВЗ — в остатке кластера; показатели
Ozon — справочно, рядом с нашим спросом.
