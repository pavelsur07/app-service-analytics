# B02 — очистка истории в committed test fixtures

## План до правки

После capture-only выпуска источники пишут историю и при origin Unknown.
Два существующих `SkipDatabaseRollback` сценария сохраняют fixtures реальными
писателями, но finally удаляет только прежние таблицы. Это оставляет историю
тестовой компании после завершения сценария. Production код и схема исправления
не требуют; scope — только очистка этих двух тестовых сценариев.

1. Сверить все `SkipDatabaseRollback` tests и их фактические writer вызовы.
   ReadTest создаёт только планы и остаётся без изменения; SnapshotTest создаёт
   sales revisions и day receipts; ControllerTest создаёт day receipts.
2. Добавить соответствующие history tables в существующие scoped finally DELETE
   списки до удаления facts/company. Не менять assertions, authentication,
   transaction boundaries, исходные fixtures или общие правила очистки.
3. Запустить существующие Snapshot integration и Controller functional tests
   последовательно, с TEST_WORKERS=2, затем lint/stan/deptrac/structure и diff-check.
   Новые тесты, повторяющие cleanup implementation, не добавлять.
4. Выполнить два actual Codex CLI review scoped diff, разобрать замечания.
   Зафиксировать проверки и передать исправление в CI в следующем B02 изменении.

## Чек-лист готовности

- [x] Причина подтверждена чтением writer paths, схемы и finally обоих тестов.
- [x] Исправлены только фактически создаваемые history/receipt таблицы.
- [x] Existing behavioral assertions и scoped company cleanup сохранены.
- [x] Scoped tests/static/diff-check пройдены, результаты записаны.
- [x] Два независимых Codex CLI завершены, triage и ограничения записаны.

Это не закрывает B02 training selection и не меняет счётчик51/68.

Проверки: integration Snapshot4tests/28assertions; functional Controller1/159,
последовательно в php-cli/paratest processes2. `make lint stan deptrac
structure-check TEST_WORKERS=2` — exit0, Deptrac violations/errors0;
`git diff --check` — exit0. Лог `var/sales-planning-b02-test-cleanup-checks.log`.
Production исходники/схема не менялись. На существующую старую тестовую
историю массовая очистка не запускалась; исправление убирает новые fixtures.

Итоговый пакет `b0c42a825a0ed286fb9512399df2ca22210353caa5045d5c382f3f3667405ce5`,
conformity0/defects0, complete/exit0, SHA сверены. Замечаний для triage нет,
принято/отклонено0. CLI model gpt-6.1-sol, distinct sessions
01a18116-ef6a-7502-b5ae-3e1ba0c5a862 /01a18116-ef4b-7b10-a343-79651932c6b1.
JSON: var/review/runs/20261028T055846.185398Z-b0c42a825a0e-codex-5pyt8vtn/review.json
и var/review/runs/20261028T055846.177280Z-b0c42a825a0e-defects-2psylj20/review.json.
Claude пропущен по действующему указанию владельца только для этой фичи.
