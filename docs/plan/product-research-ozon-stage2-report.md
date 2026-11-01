# product-research-ozon — отчёт этапа 2

Статус: реализация выполнена; полная приёмка, независимое ревью и доставка
ещё проверяются. Основание: [план и G01–G12](product-research-ozon-stage2.md).

## Реализовано

ProductResearchOzon добавлен как модуль Symfony со слоями ADR-050. Реализованы
preview/create/get/list/add/copy: авторство из seller-сессии, company scope,
FBS=true/RUB, именованные сезоны, до 24 диапазонов/месяцев, часы PSR-20 и
календарь Europe/Moscow. Дубликаты месяцев объединяются, текущий месяц явный
и до вчера. PreviewCalendarDate защищает от устаревшего предпросмотра.

Сохранение шапки, периодов и аудита атомарно через ORM. Периоды защищены
уникальным ограничением и составным FK (company_id, project_id), поэтому
шапка имеет составной PK (company_id, id); оба UUID заданы приложением,
id — UUIDv7. Обычные человеческие таблицы, факты не менялись. Это реализация
согласованной tenant-связи, новых межмодульных связей не вводится.

Список — bounded DBAL/keyset с limit+1; деталь — один SELECT с периодами:
шапка и строки видны в одном MVCC-снимке, без ORM-гидрации/N+1. Mutation-ответ
использует уже изменённый агрегат и рассчитанный в том же сценарии preview;
сущность напрямую не сериализуется, ответ собирается через DTO.

Реальные изменения/копирование блокируют шапку, проверяют expectedVersion.
ORM version участвует в записи; period_count/flag меняют шапку даже при
неизменившихся контролируемых часах. В Private mapped-полях шапки PHP readonly
не используется: Doctrine refresh под блокировкой создаёт новые UUID/DateTime
объекты. Инкапсуляция сохраняется приватными полями и явными методами.

CategoryLookup различает подтверждённую категорию, unknown_category и
catalog_not_ready. В production до этапа 3 работает только unavailable adapter;
фикстуры/Fake подключены исключительно в test. Feature flag
PRODUCT_RESEARCH_OZON_ENABLED имеет server default false: авторизованный seller
получает 404. Без сессии и без членства сначала работает действующая auth-граница.
Никаких обращений к Python/MPStats, фоновых заданий, UI или фиктивных готовых данных.

## Уточнения реализации

- Предпросмотр без категории возвращает suggestedName=null; если категория
  выбрана, она проверяется в company-scoped справочнике и предлагается название.
- Add допускает явную смену includeCurrentMonth вместе с периодами. Полный no-op
  при одинаковых периодах и флаге не меняет version/аудит. Copy читает источник
  под FOR SHARE, изменения берут FOR UPDATE; копия не повышает версию источника.
- Целые JSON-числа 1.0/3.0 принимаются как integer согласно OpenAPI, строки,
  bool и дробные значения отвергаются. Денежных вычислений нет.
- Down миграции разрешён на пустых новых таблицах; при наличии черновиков
  abort, поскольку эти человеческие данные не восстанавливаются из MPStats.
  Откат с данными — сохранение текущей БД/восстановление согласованной резервной
  копии, а не удаление проектов. Production flag на этом этапе не включается.
- Первый fresh rebuild выявил ложную готовность initdb: db-wait проверял
  временный Unix-сокет до TCP-start. Проверка переведена на TCP 127.0.0.1;
  повторный полный rebuild выполняется в изолированном Compose project.

## Локальные результаты

- Red/Green календаря: без классов тесты падали; затем 15 unit/35 assertions
  подтвердили периоды, пересечения, високосность, shortcuts и Moscow-boundary.
- `phpunit tests/Unit/ProductResearchOzon tests/Integration/ProductResearchOzon
  tests/Functional/ProductResearchOzon`: **57 тестов / 576 assertions**, успешно.
  Есть реальные процессы/соединения с подтверждённым pg_blocking_pids ожиданием,
  оба порядка copy/add, rollback после flush, valid extension token, CSRF,
  blocked/чужие компании, flag=false, body/cursor limits и ошибки категорий.
  Лог: var/product-research-ozon-stage2/final-green.log.
- `make lint stan deptrac structure-check api-types-check`: успешно.
  PHPStan baseline не расширен. OpenAPI/TS изменены только генераторами.
- `make db-schema-validate`: dev/test mapping и схемы совпадают.
- Полный isolated `db-rebuild-check`: **OK** — 801 unit / 9115 assertions,
  883 integration / 23231 assertions, 409 functional / 3952 assertions; затем
  гейты Go и 27 тестов Python. Проект research-stage2-rebuild, порты reset, собственные PG/S3/Go
  volumes; текущие dev-тома не удаляются. Лог: var/product-research-ozon-stage2/rebuild-review.log.

После замечаний ревью добавлены индекс автора, отдельный Builder периода,
QueryBuilder-контракт и before/after аудит. Реальный MonthResponse проверяется
против OpenAPI, файлы схемы доступны тестам через read-only volume.
Повторный полный rebuild обновлённой миграции завершён успешно.
После второго ревью запрещён NUL в текстах; три HTTP-регрессии проверяют
отказ до записи. Замечание о base32/base58 cursor отклонено по установленному
коду Symfony и фактическому HTTP 422. Ожидается финальное повторное ревью.

## Обязательное ревью и доставка

Claude CLI недоступен (нет исполняемого файла); применяются две новые
сессии Codex CLI 5A/5B по полному пакету с моделью из артефактов CLI. Результат,
разбор, уникальные новые замечания и реквизиты будут записаны здесь.
До complete ревью и зелёного обязательного CI merge не допускается.
Production-миграция — отдельная явная операция по существующему разрешению,
после гейта выкладки; затем проверка версии/здоровья и закрытого flag.

Python-сервис/загрузка и seller UI остаются вне включения. Чужие B17/B18,
securety_chek, temp и удаление спецификации онбординга не включаются в задачу.

Дополнительно M01: на изолированной app_test синтетический черновик блокирует
`down`; после cleanup проверены empty down/up и schema validation. Результат:
var/product-research-ozon-stage2/migration-check.txt. Свежий rebuild действительно
пересоздал только тома research-stage2-rebuild; исходный сбой TCP не засчитан.

Разбор первого пакета и полные заключения: [ревью этапа](product-research-ozon-stage2-review.md).
