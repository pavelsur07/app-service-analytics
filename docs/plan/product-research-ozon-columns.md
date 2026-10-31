# MPStats Ozon — сопоставление CSV и API

Дата: 31.10.2026. Версия карты: 0.3 — API CSV и ручной экспорт интерфейса.

**Расхождение с мини-ТЗ:** API CSV содержит 49 колонок A–AW, исходное ТЗ
ожидало 41 A–AO. Ручной CSV получен для другой категории и периода: он содержит 41 колонку,
их полное сопоставление приведено ниже. API-экспорт — отдельная схема. Все 49 полей найдены в REST JSON; проверены на всех 50 702 строках.
Исходные значения не изменялись; преобразования ниже относятся только к сверке.

| № | CSV | Заголовок API CSV | REST JSON field | Результат проверки |
|---|---|---|---|---|
| 1 | A | SKU | `id` | совпало после разбора чисел |
| 2 | B | Name | `name` | совпало после разбора чисел |
| 3 | C | Category | `category` | совпало после разбора чисел |
| 4 | D | Count of categories | `categories_count` | совпало после разбора чисел |
| 5 | E | Brand | `brand` | совпало после разбора чисел |
| 6 | F | Seller | `seller` | совпало после разбора чисел |
| 7 | G | Stock FBO, pcs | `balance` | совпало после разбора чисел |
| 8 | H | Stock FBS, pcs | `balance_fbs` | совпало после разбора чисел |
| 9 | I | Comments | `comments` | совпало после разбора чисел |
| 10 | J | Price, ₽ | `final_price` | совпало после разбора чисел |
| 11 | K | Max Price, ₽ | `final_price_max` | совпало после разбора чисел |
| 12 | L | Min Price, ₽ | `final_price_min` | совпало после разбора чисел |
| 13 | M | Avg Price, ₽ | `final_price_average` | совпало после разбора чисел |
| 14 | N | Median Price, ₽ | `final_price_median` | совпало после разбора чисел |
| 15 | O | Sales, pcs | `sales` | совпало после разбора чисел |
| 16 | P | Sales per comment, pcs | `sales_per_comments` | совпало после разбора чисел |
| 17 | Q | Revenue, ₽ | `revenue` | совпало после разбора чисел |
| 18 | R | Potential Revenue, ₽ | `revenue_potential` | совпало после разбора чисел |
| 19 | S | Avg revenue, ₽ | `revenue_average` | совпало после разбора чисел |
| 20 | T | Lost Revenue, ₽ | `lost_profit` | совпало после разбора чисел |
| 21 | U | Lost Revenue % | `lost_profit_percent` | совпало после разбора чисел |
| 22 | V | % from revenue | `percent_from_revenue` | есть расхождение значений |
| 23 | W | Model ID | `model_id` | совпало после разбора чисел |
| 24 | X | Model revenue, ₽ | `model_revenue` | совпало после разбора чисел |
| 25 | Y | % of model revenue | `model_revenue_percent` | совпало после разбора чисел |
| 26 | Z | URL | `url` | совпало после разбора чисел |
| 27 | AA | Photo | `thumb` | совпало после разбора чисел |
| 28 | AB | Days in website | `days_in_site` | совпало после разбора чисел |
| 29 | AC | Was in stock, days | `days_in_stock` | совпало после разбора чисел |
| 30 | AD | Days with sales | `days_with_sales` | совпало после разбора чисел |
| 31 | AE | Average if in stock | `average_if_in_stock` | совпало после разбора чисел |
| 32 | AF | Rating | `rating` | совпало после разбора чисел |
| 33 | AG | Base price | `start_price` | совпало после разбора чисел |
| 34 | AH | Price with Ozon Card, ₽ | `ozon_card_price` | совпало после разбора чисел |
| 35 | AI | FBS | `is_fbs` | совпало после разбора чисел |
| 36 | AJ | Niche ID | `niche_id` | совпало после разбора чисел |
| 37 | AK | Position in category | `category_position` | совпало после разбора чисел |
| 38 | AL | Sales per day average | `sales_per_day_average` | совпало после разбора чисел |
| 39 | AM | Detection date | `sku_first_date` | YYYY-MM-DD → DD.MM.YYYY |
| 40 | AN | Photo count | `picscount` | совпало после разбора чисел |
| 41 | AO | Video availability | `hasvideo` | совпало после разбора чисел |
| 42 | AP | Niche Name | `niche` | совпало после разбора чисел |
| 43 | AQ | Turnover | `turnover_once` | совпало после разбора чисел |
| 44 | AR | Turnover, days | `turnover_days` | null → -/пусто |
| 45 | AS | Frozen Stock, pcs | `frozen_stocks` | совпало после разбора чисел |
| 46 | AT | Frozen Stock Value, ₽ | `frozen_stocks_cost` | совпало после разбора чисел |
| 47 | AU | Frozen Stock, % | `frozen_stocks_percent` | совпало после разбора чисел |
| 48 | AV | Count of warehouses | `warehouses_count` | совпало после разбора чисел |
| 49 | AW | Count of characters in name | `name_length` | совпало после разбора чисел |

`percent_from_revenue` найден, но значения JSON и CSV расходятся: например,
`0.24` в JSON против `0` в CSV. На двух страницах JSON-показатель совпал с долей
в сумме выручки страницы (1 000 проверок), а не доказанной долей месяца.
Не вводить округление для скрытия расхождения. Даты имеют разный формат, null оборачиваемости
представлен `-` в CSV; эти различия проверены явно.

Источники и SHA-256: [отчёт REST-проверки](product-research-ozon-rest-check.md).
Сырьё и исполняемый результат сверки находятся в игнорируемом `var/`.
Полный помесячный JSON сверён с API CSV: 48 колонок совпали, доля выручки
расходится на 4 497 строках. Эта карта не заменяет проверку ручного CSV.

## Ручной CSV интерфейса: 41 колонка A–AO

Обновление: [новый ZIP ручного экспорта](product-research-ozon-manual-csv-check.md)
с преимущественно английскими заголовками сохранил тот же порядок 41 поля.
Sales/Revenue совпали с API FBS=1 по всем 62 148 SKU. Приведённые ниже
расхождения FBS=0 относятся к предыдущему файлу, не к новой выгрузке.

Файл категории «Защита от насекомых», период по имени файла 01–28.02.2025.
Сравнен со свежими API-экспортами той же категории/периода в обоих режимах FBS.
Пользователь подтвердил FBO+FBS в интерфейсе; фактически продажи и выручка
каждого SKU ручного файла совпали с API FBS=0, а с FBS=1 расходятся.
Это расхождение интерфейса/экспорта/API не разрешено изменением методологии.
Таблица ниже показывает результаты диагностической сверки с FBS=0.

| CSV | Ручной заголовок | REST JSON field | Заголовок API CSV | Результат на 62 148 SKU |
|---|---|---|---|---|
| A | Артикул | `id` | SKU | совпало (FBS=0) |
| B | Наименование | `name` | Name | различается: 128 строк |
| C | Категория | `category` | Category | различается: 2403 строк |
| D | Бренд | `brand` | Brand | совпало (FBS=0) |
| E | Niche | `niche` | Niche Name | совпало (FBS=0) |
| F | Продавец | `seller` | Seller | различается: 1 строк |
| G | Balance | `balance` | Stock FBO, pcs | различается: 83 строк |
| H | Balance FBS | `balance_fbs` | Stock FBS, pcs | различается: 12471 строк |
| I | Warehouses count | `warehouses_count` | Count of warehouses | совпало (FBS=0) |
| J | Comments | `comments` | Comments | совпало (FBS=0) |
| K | Final price | `final_price` | Price, ₽ | совпало (FBS=0) |
| L | Max price | `final_price_max` | Max Price, ₽ | совпало (FBS=0) |
| M | Min price | `final_price_min` | Min Price, ₽ | совпало (FBS=0) |
| N | Средняя цена | `final_price_average` | Avg Price, ₽ | совпало (FBS=0) |
| O | Median price | `final_price_median` | Median Price, ₽ | совпало (FBS=0) |
| P | Цена с Ozon картой | `ozon_card_price` | Price with Ozon Card, ₽ | совпало (FBS=0) |
| Q | Продажи | `sales` | Sales, pcs | совпало (FBS=0) |
| R | Выручка | `revenue` | Revenue, ₽ | совпало (FBS=0) |
| S | Revenue potential | `revenue_potential` | Potential Revenue, ₽ | совпало (FBS=0) |
| T | Revenue average | `revenue_average` | Avg revenue, ₽ | совпало (FBS=0) |
| U | Lost profit | `lost_profit` | Lost Revenue, ₽ | совпало (FBS=0) |
| V | Lost profit percent | `lost_profit_percent` | Lost Revenue % | совпало (FBS=0) |
| W | URL | `url` | URL | совпало (FBS=0) |
| X | Thumb | `thumb` | Photo | различается: 62148 строк |
| Y | Pics Count | `picscount` | Photo count | совпало (FBS=0) |
| Z | Has Video | `hasvideo` | Video availability | совпало (FBS=0) |
| AA | First Date | `sku_first_date` | Detection date | совпало (FBS=0) |
| AB | Days in website | `days_in_site` | Days in website | совпало (FBS=0) |
| AC | Дней в наличии | `days_in_stock` | Was in stock, days | совпало (FBS=0) |
| AD | Дней с продажами | `days_with_sales` | Days with sales | совпало (FBS=0) |
| AE | Average if in stock | `average_if_in_stock` | Average if in stock | совпало (FBS=0) |
| AF | Рейтинг | `rating` | Rating | совпало (FBS=0) |
| AG | FBS | `is_fbs` | FBS | совпало (FBS=0) |
| AH | Базовая цена | `start_price` | Base price | совпало (FBS=0) |
| AI | Category Position | `category_position` | Position in category | различается: 2403 строк |
| AJ | Categories Last Count | `categories_count` | Count of categories | различается: 20043 строк |
| AK | Sales Per Day Average | `sales_per_day_average` | Sales per day average | совпало (FBS=0) |
| AL | Turnover | `turnover_days` | Turnover, days | различается: 4893 строк |
| AM | Frozen stocks | `frozen_stocks` | Frozen Stock, pcs | различается: 197 строк |
| AN | Frozen stocks cost | `frozen_stocks_cost` | Frozen Stock Value, ₽ | различается: 197 строк |
| AO | Frozen stocks percent | `frozen_stocks_percent` | Frozen Stock, % | различается: 197 строк |

Все 41 поля найдены в API. Ручной формат содержит 41 колонку, API CSV — 49;
порядок и язык заголовков различаются. `Niche` ручного CSV — путь ниши,
не niche_id. `Turnover` ручного CSV — turnover_days, не turnover_once.
Ручная First Date использует ISO, API CSV — DD.MM.YYYY. Фото имеют разные
пути размеров, но совпадающие имена ресурсов у всех SKU.

[Полный отчёт сравнения](product-research-ozon-manual-csv-check.md).
