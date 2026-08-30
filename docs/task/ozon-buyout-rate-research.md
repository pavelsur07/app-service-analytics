# Исследование источников процента выкупа Ozon

**Дата среза:** 2026-08-30

**Период запроса:** 2026-06-01 — 2026-08-30

**Статус:** достаточен для ADR-019 и реализации с консервативной handover
boundary; две дополнительные проверки перед выпуском перечислены в конце.

## Источники и обращение с данными

Исследование выполнено по живым ответам одного FBO-кабинета:

- `posting-fbo-list-buyout-2026-08-30-page-001..008.json`;
- `returns-list-buyout-2026-08-30-page-001..009.json`;
- `posting-fbo-cancel-reason-list-buyout-2026-08-30.json`.

Файлы лежат локально в `api/tests/Fixtures/Marketplace/ozon/`, но **не являются
готовыми тестовыми фикстурами и не должны попадать в git**. В них нет непустого
`legal_info`, однако есть коммерческие и операционные данные продавца:
наименования/артикулы товаров, города, Ozon company ID, адреса пунктов,
логистические штрихкоды и exemplar IDs. В репозиторий должны попасть только
минимальные синтетические JSON, воспроизводящие подтверждённую структуру.

Все приведённые ниже результаты — агрегаты без order/posting/SKU, названий
товаров, адресов и штрихкодов.

## `/v2/posting/fbo/list`

### Полнота и пагинация

Получено 7 полных страниц по 1000 postings и последняя страница из 617 строк:
всего 7617 postings. Все `posting_number` уникальны. Скрипт правильно использует
`offset += count(page)` и прекращает чтение только на неполной странице.

В каждом из 7617 postings присутствуют и имеют ожидаемый scalar type:

- `order_number`;
- `posting_number`;
- `status`;
- `substatus`;
- `cancel_reason_id`;
- `in_process_at`;
- `products[].sku` и `products[].quantity`.

Следовательно, `/v2/posting/fbo/get` для этих полей не нужен. Вызов на каждый
posting был бы лишней точкой отказа и не входит в production-план.

В этом кабинете один posting содержит одну product row с quantity 1, но один
`order_number` может объединять несколько postings:

| Postings в order | Orders |
|---:|---:|
| 1 | 5881 |
| 2 | 690 |
| 3 | 64 |
| 4 | 23 |
| 5 | 8 |
| 6 | 4 |
| 8 | 1 |

Итого 6671 orders; у 790 orders несколько SKU/postings. Поэтому результат
частичного выкупа определяется на уровне `(order_number, sku)`, а не только
posting.

### Текущие статусы

| status | substatus | Строк |
|---|---|---:|
| `cancelled` | `posting_canceled` | 4071 |
| `delivered` | `posting_received` | 2927 |
| `delivering` | `posting_in_pickup_point` | 301 |
| `delivering` | `posting_on_way_to_city` | 208 |
| `awaiting_packaging` | `posting_created` | 40 |
| `delivered` | `posting_delivered` | 36 |
| `awaiting_deliver` | `posting_transferring_to_delivery` | 34 |

`substatus` уже есть в list и полезен: он различает путь к городу и нахождение
в ПВЗ, а также два delivered-варианта. Но единственный снимок показывает только
текущее состояние. Он не отвечает, успел ли отменённый posting раньше пройти
handover; для T1/T2 нужна append-only история последовательных опросов.

`cancel_reason_id` присутствует во всех строках: `0` у неотменённых и 21
ненулевое значение у отменённых. Сам ID нельзя считать полной семантикой
причины — это подтверждает returns ниже.

## `/v1/returns/list`

### Фактический контракт

Получено 4432 строки: 8 страниц по 500 и последняя из 432. `has_next=true`
на первых восьми страницах и `false` на последней. `last_id` — opaque cursor:
между четвёртой и пятой страницей его числовое значение уменьшилось, поэтому
его нельзя сравнивать по величине; разрешена только проверка «не пуст и не
повторился».

Во всех строках присутствуют:

- `id` — JSON number, уникален во всём срезе;
- `order_number` — string;
- `posting_number` — string;
- `source_id` — number;
- `product.sku` и `product.quantity` — number;
- `type` — string;
- `return_reason_name` — string;
- `visual.status.id`, `visual.status.sys_name`, `visual.change_moment`.

4432 `id` дают 4432 уникальных строк, но `(order_number, sku)` уникален только
для 4429: три пары встречаются повторно как разные return events. Поэтому
естественный `source_row_id` факта — строковое представление `returns[].id`.
Пара `(order_number, sku)` — только ключ связи с продажей, не уникальность
return fact.

### Типы и связь с продажами

В ответе **нет** типа `PartialReturn`:

| type | Строк |
|---|---:|
| `Cancellation` | 4156 |
| `ClientReturn` | 276 |

Все строки имеют `schema = Fbo` и quantity 1.

С периодом FBO совпали 4150 returns по `(order_number, sku)`. Столько же
совпало по `posting_number`, но это не одни и те же связи: в трёх строках
`order_number + sku` и `order_id` указывают на исходную продажу, а
`posting_number` отличается. Следовательно:

- канонический join — `(company_id, marketplace_account_id, order_number,
  marketplace_sku)`;
- `returns[].posting_number` сохраняется для трассировки, но не называется
  «дочерним» без такого признака в API и не участвует в основном join;
- `source_id` также не совпал ни с исходным, ни с return posting number в этих
  трёх случаях.

282 returns не сопоставились с июньско-августовским FBO-срезом. Это совместимо
с change-based returns window: visual status мог измениться в периоде у
заказа, созданного до начала FBO-выгрузки, но срез не доказывает, что причина
всех 282 строк именно такая. Handler не считает отсутствие связи ошибкой:
строка остаётся в raw/fact, может связаться после более глубокого posting
backfill и до этого видна как диагностически unlinked.

### Семантика исходов

Для 245 сопоставленных `ClientReturn` исходный FBO status — `delivered`.
Это подтверждает категорию R: состоявшийся выкуп с последующим клиентским
возвратом. R не уменьшает процент выкупа, но считается разрешившимся исходом.

`Cancellation` содержит фактическую buyer-причину. Наблюдаемые группы:

| Семантика `return_reason_name` | Рекомендуемый stage | Строк |
|---|---|---:|
| отказ при вручении: не подошёл/не тот/качество/комплектация | `HANDOVER_REFUSAL` | 3409 |
| покупатель не забрал заказ | `PICKUP_EXPIRED` | 201 |
| не удалось доставить | `DELIVERY_FAILED` | 37 |
| отменил/нашёл дешевле/не устроил или перенесён срок | `CANCELLED`, stage берётся из status history | 506 |
| старое название «изменил решение/не подошёл» | `HANDOVER_REFUSAL` | 2 |
| старое название «нашлось дешевле» | `CANCELLED`, stage из history | 1 |

Числа в таблице относятся ко всем 4432 returns и потому могут включать заказы
вне FBO-периода.

Операционное определение P, согласующееся с фактической формой Ozon:

1. return имеет `type = Cancellation` и stage `HANDOVER_REFUSAL`;
2. у того же `order_number` есть sibling SKU/posting с итогом D;
3. тогда только отказавшаяся SKU получает P;
4. если delivered sibling нет после разрешения всего order, это T2.

В срезе найдено 297 таких SKU-кандидатов P и 2933 отказа при вручении без
delivered sibling. Кроме того, 278 multi-SKU orders имеют одновременно
`cancelled` и `delivered`. Это сильное подтверждение модели частичного выкупа,
хотя перед ADR требуется ручная сверка одного кандидата с кабинетом Ozon.

### Почему `cancel_reason_id → T1/T2` недостаточно

`/v1/posting/fbo/cancel-reason/list` вернул только IDs 352, 401, 402 и 666 —
seller-причины, доступные для ручной отмены. Ни один из них не встретился среди
21 фактического ненулевого `cancel_reason_id` в FBO-срезе.

Более того, ID 506 сопоставился с 215 строками «нашёл дешевле» и одной строкой
«отказался при вручении». Значит, один ID нельзя использовать как единственный
источник stage. В модели нужны:

- status history как основной факт «handover уже был/не был»;
- конфигурация по `(return_type, return_reason_name)` для явно стадийных причин;
- `cancel_reason_id` как trace/diagnostic, но не как самостоятельная истина;
- outcome `NULL` и диагностическая выборка для неизвестной причины.

## Матурация и срок ПВЗ

Официальные страницы Ozon не задают одну глобальную константу для всех заказов:
срок показывается у конкретного заказа/пункта и может продлеваться. Например,
официальная страница одного ПВЗ указывает 14 дней и одновременно подтверждает
возможность частичного выкупа ([Ozon, ПВЗ 450-516](https://www.ozon.ru/geo/moskva/450516/));
инструкция Ozon покупателю говорит смотреть срок у выбранного пункта и заказа
([Ozon Клуб, получение заказа](https://www.ozon.ru/club/article/kak-poluchit-zakaz-v-punkte-vydachi-zakazov-ozon-47417/)).

Поэтому 14 дней нельзя вшивать как универсальный fallback. До накопления 30
полных интервалов `handover_at → terminal_at` p95 отсутствует, а когорта
остаётся `preliminary`. После накопления используется измеренный account p95.

## Решения для ADR-019

Уже подтверждено:

1. `substatus` и `order_number` берутся из `/v2/posting/fbo/list`; production
   `fbo/get` не нужен.
2. Status history ключуется raw document, а не timestamp; `observed_at` нужен
   для порядка.
3. Return fact ключуется `returns[].id`; upsert отслеживает mutable поля.
4. Join return → sale выполняется по `(company, account, order_number, sku)`.
5. Return fact хранит `type`, `return_reason_name`, observed posting/source IDs,
   quantity, visual status и `visual.change_moment`.
6. `ClientReturn` после D → R.
7. `Cancellation + HANDOVER_REFUSAL + delivered sibling` → P; без delivered
   sibling после разрешения order → T2.
8. Явные pickup expiration/delivery failure → T2. Остальные Cancellation
   получают T1/T2 из истории handover; без истории остаются unknown.
9. `cancel_reason_id` не является ключом классификационного словаря.
10. `last_id` returns — opaque, потенциально немонотонный cursor.

## Открытые проверки перед выпуском

По указанию владельца 2026-08-30 эти проверки не блокируют разработку.
ADR-019 принимает безопасную границу `status = delivering`, а
`awaiting_deliver` и неоднозначные случаи оставляет unresolved. Проверки ниже
остаются обязательным выпускным контролем и могут только расширить доказанную
семантику либо остановить выпуск при противоречии.

1. **Handover boundary.** Снять второй FBO-срез позже и подтвердить порядок
   `awaiting_packaging → awaiting_deliver → delivering → terminal`, особенно
   означает ли `posting_transferring_to_delivery` уже состоявшийся handover или
   только подготовку. До этого ADR может выбрать безопасную границу
   `status = delivering`, но не более раннюю.
2. **P в кабинете.** Вручную открыть один из 297 кандидатов и подтвердить, что
   Ozon показывает delivered sibling и отказ от конкретной SKU при вручении.
   Идентификатор кандидата намеренно не записан в документ. Найти один локально
   можно командой ниже; её вывод не переносить в git/чат:

   ```bash
   jq -s '
     [.[] | .result[]?] as $f
     | [.[] | .returns[]?] as $r
     | (reduce $r[] as $ret ({};
         .[$ret.order_number + "|" + ($ret.product.sku | tostring)] = $ret
       )) as $returns
     | first(
         $f | group_by(.order_number)[]
         | select(any(.[]; .status == "delivered"))
         | . as $order
         | $order[] as $posting
         | $posting.products[] as $product
         | ($returns[$posting.order_number + "|" + ($product.sku | tostring)] // null) as $ret
         | select(
             $posting.status == "cancelled"
             and $ret.type == "Cancellation"
             and ($ret.return_reason_name | startswith("Покупатель отказался при вручении"))
           )
         | {
             order_number: $posting.order_number,
             rejected_posting_number: $posting.posting_number,
             rejected_sku: $product.sku,
             delivered_sibling_posting_number: (
               $order | map(select(.status == "delivered"))[0].posting_number
             )
           }
       )
   ' api/tests/Fixtures/Marketplace/ozon/posting-fbo-list-buyout-2026-08-30-page-*.json \
     api/tests/Fixtures/Marketplace/ozon/returns-list-buyout-2026-08-30-page-*.json
   ```

Новые виды API-ответов для этих проверок не нужны. Нужны второй снимок того же
`/v2/posting/fbo/list` и одна ручная сверка в интерфейсе Ozon.
