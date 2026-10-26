# Этап 6 — контрольный отчёт

25.10.2026. Этапы 1–4 поставлены. Этап 5 реализован и прошёл локальные
гейты и независимое ревью; окончательные CI и поставка ещё ожидаются.
Исходный пользовательский `docs/plan/securety_chek.md` не изменён и не
включён в коммиты. Планы этапов содержат задачи, ожидаемый результат,
последовательность TDD и критерии готовности.

## Подтверждённые поставки

| Этап | PR | Merge commit | CI и deploy | Версия |
|---|---|---|---|---|
| 1 | [#261](https://github.com/pavelsur07/app-service-analytics/pull/261) | `3db64e2cc6c38f2e57736d9e1c192d299aaf0cd5` | [success](https://github.com/pavelsur07/app-service-analytics/actions/runs/40222167323), включая image/deploy | `614-3db64e2` |
| 2 | [#262](https://github.com/pavelsur07/app-service-analytics/pull/262) | `eab394174ffe5dc4425b597c003b1961f860b4c8` | [success](https://github.com/pavelsur07/app-service-analytics/actions/runs/40223430654), включая image/deploy | `616-eab3941` |
| 3 | [#263](https://github.com/pavelsur07/app-service-analytics/pull/263) | `02305d81cdf8e7c14606132cf66abe9346d4a327` | [success](https://github.com/pavelsur07/app-service-analytics/actions/runs/40224576183), включая image/deploy | `619-02305d8` |
| 4 | [#264](https://github.com/pavelsur07/app-service-analytics/pull/264) | `a6bfa6a60df5c996a80615ce71a900a13d4f2d26` | [success](https://github.com/pavelsur07/app-service-analytics/actions/runs/40225372785), включая image/deploy | `620-a6bfa6a` |

Безопасный публичный smoke 25.10.2026: seller ping вернул
`620-a6bfa6a`; `/api/extension/me` без Bearer — 401; POST login с чужим
Origin и text/plain — 403; GET logout — 405. Реальные аккаунты, пароли,
факторы и резервные коды для smoke не использовались.

## Доказательства устранения

- Отзыв расширений после reset: HTTP RED 200→GREEN 401, токены всех
  компаний пользователя, сохранность чужих токенов, rollback, сериализация
  с конкурентным выпуском. [Результаты этапа 1](security-remediation-stage-1-result.md).
- CSRF: реальная HTML text/plain форма с соседнего origin, encoded пути,
  разобранный multipart, login/logout и штатные клиентские запросы.
  [Результаты этапа 2](security-remediation-stage-2-result.md).
- Storage: реальный Chromium, content script не читает connection,
  worker/popup читают; ограничение сохраняется при рестарте и без worker.
  Отрицательная мутация разрешённого доступа заставляет тест падать.
  [Результаты этапа 3](security-remediation-stage-3-result.md).
- Зависимости: Zod 4.6.7, Composer и шесть npm-деревьев с dev packages,
  независимая отрицательная проверка отказа каждого audit scope. Go
  vulncheck пройден локально на этапе 1 и обязательным services CI.
  [Результаты этапа 4](security-remediation-stage-4-result.md).
- MFA: partial/legacy-сессии закрыты, enrollment/verify/recovery/regenerate,
  replay/expiry/rate limits/reset, rollback и два реальных процесса.
  Секреты шифруются и исключены из телеметрии. База с нуля и down/up
  локально проверены. [Результаты этапа 5](security-remediation-stage-5-result.md).

## Ревью и готовность

Claude CLI недоступен; владелец явно разрешил независимый Codex в отдельной
сессии. Для high-risk этапов 1/2/3/5 проведены три независимых роли,
для этапа 4 — общее ревью. Итоговые ответы complete; принятые замечания
устранены и изменившиеся части проверены повторно.

| Этап | Уникально принято 5A | Дополнительно 5B | Дополнительно 5C | Отклонено |
|---|---:|---:|---:|---|
| 1 | 0 | 0 | 0 | 0; первый incomplete дополнен контекстом |
| 2 | 1 | 1 | 1 | 0 |
| 3 | 0 | 0 | 0 | 1 о сбросе storage policy; опровергнуто исходниками и браузером |
| 4 | — | — | 0 | 0 |
| 5 | 1 | 0 | 0 | 0 |

Идентификаторы пакетов и сводки выводов сохранены в результатах этапов;
они не зависят от срока хранения локального var/review.

## Незавершённое условие этапа 5

PR [#265](https://github.com/pavelsur07/app-service-analytics/pull/265),
head `d839e90b29a240db3c3cfe51fa979a61e00152ed`.
В текущем окружении отсутствует предусмотренный правилами SSH-алиас
`conwix-prod`: конфигурация оставляет hostname буквально `conwix-prod`,
подключение не разрешается. Пользователю направлен запрос настроить штатный
доступ вне репозитория. До его появления merge отложен: после штатного
отказа migration gate должна быть возможность отдельно применить
`Version20261025192000`, повторить deploy и проверить версию/схему.
Нельзя считать миграцию, MFA в production и всю программу завершёнными.

## Остаточные действия и ограничения

- Владельцам необходимо лично подключить authenticator при следующем
  входе после поставки MFA и сохранить одноразовые коды. До подтверждения
  второй фактор закрывает привилегии; агент не подключает личное устройство.
- Extension 0.3.1 выпущен CI-артефактом, минимум Chrome 140. Пользователи
  обновляют установленное расширение; это не публикация в Chrome Web Store
  и не доказательство автоматического обновления всех браузеров.
- Уже украденный до обновления токен нельзя защитить задним числом.
  Сбросы пароля, выполненные до поставки отзыва, не выполняются повторно.
- Отсутствие advisory не доказывает отсутствия неизвестных уязвимостей.
