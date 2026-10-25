# Этап 3. Секрет расширения доступен только trusted-контекстам

## Задача и результат

Запретить чтение `chrome.storage.local` content scripts, сохранив подключение
после перезапуска браузера и обновления расширения. Worker/popup пользуются
storage только после успешного `setAccessLevel(TRUSTED_CONTEXTS)`.

## Решение и файлы

Общая граница `browserStorage()` в `src/shared/connection.ts` возвращает
Storage-обёртку с единственным Promise инициализации на контекст. Каждый
get/set/clear ожидает этот Promise. Все существующие обработчики worker
регистрируются синхронно; отдельная асинхронная регистрация не вводится.
Инициализацию запускать при создании browserStorage, доступ к storage
выполнять через await-границу, включая popup. Отклонённый Promise не
превращается в разрешение операции и не создаёт unhandled rejection.

Типы Chrome дополняются в `src/shared/chrome.d.ts`, тесты —
`src/shared/connection.test.ts` и новый файл тестирования browserStorage.
Нативная браузерная проверка — `tests/browser/storage.mjs`, вызывается
существующей целью `make test-e2e`, чтобы её выполнял обязательный CI.
Манифест: зафиксировать минимальную версию Chrome с поддержкой API,
если она ещё не задана. Обновление не расширяет permissions/host permissions.

## TDD

- [ ] RED: операция get не достигает native storage до успешной
  инициализации; исходный browserStorage пропускает её немедленно.
- [ ] RED: при отказе setAccessLevel get/set/clear отклоняются и не
  обращаются к native storage.
- [ ] GREEN: обёртка с общей готовностью; добавить тип `setAccessLevel`.
- [ ] Проверить ранние сообщения, повторные обращения, popup и запись/
  очистку подключения через ту же границу. Не возвращать connection/token
  в ответы runtime; не создавать универсальный storage-proxy.
- [ ] Typecheck/lint/knip/test/build extension.
- [ ] Настоящий Chromium: загрузка dev-расширения, запись тестового секрета
  из trusted worker, запрет чтения в его content script, доступ popup/worker;
  повтор после перезапуска. Использовать локальную страницу с origin из
  текущего manifest, без внешнего маркетплейса и настоящих токенов.
- [ ] Три независимых Codex-ревью по разрешению владельца, зелёный CI,
  выпуск новой версии и проверка доступности артефакта расширения.

## Чек-лист готовности

- [ ] RED→GREEN подтверждён на реальной границе browserStorage.
- [ ] Ни одна операция не проходит до/при отказе ограничения.
- [ ] Worker/popup работают, подключение не стирается при обновлении.
- [ ] Content script не читает секрет в настоящем Chromium.
- [ ] Сборка, проверки и независимые ревью завершены.
- [ ] Выпуск доступен; старые установленные версии явно остаются риском
  до обновления, ранее украденный секрет требует отдельного отзыва.

## Уточнение по версии Chromium и разбору ревью

Минимальная версия — Chrome 140: до неё setAccessLevel обслуживает только
storage.session, хотя сам метод появился в Chrome 102. Это подтверждено
официальным исходным кодом Chromium 139 и 140, а не наличием метода в typings.

Замечание 5C о сбросе уровня доступа при рестарте проверено отдельно:
на втором запуске экспериментальной копии расширения background worker
удалён из манифеста, поэтому setAccessLevel повторно не вызывается;
content script всё равно не читает сохранённое подключение. Настройка
сохраняется в ExtensionPrefs (storage_local_access_level).
Источники: [Chromium storage_utils.cc](https://raw.githubusercontent.com/chromium/chromium/main/extensions/browser/api/storage/storage_utils.cc),
[Chromium 139 storage_api.cc](https://raw.githubusercontent.com/chromium/chromium/139.0.7258.154/extensions/browser/api/storage/storage_api.cc),
[Chromium 140 storage_api.cc](https://raw.githubusercontent.com/chromium/chromium/140.0.7339.127/extensions/browser/api/storage/storage_api.cc).

При первом обновлении со старой уязвимой версии остаётся интервал до первой
успешной установки ограничения: ранее доступный секрет нельзя защитить
задним числом. По всем последующим штатным запускам ограничение сохраняется.
