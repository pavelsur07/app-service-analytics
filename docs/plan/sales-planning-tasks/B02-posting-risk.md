# B02 — доказуемая численность risk-set отправлений

## Причина и решение до реализации

ADR-049 допускает несколько отправлений order/SKU с одним delivery origin.
R связан с order/SKU и не имеет доказанной физической аллокации на отправления.
При original capacities `[3,1]` и cumulative R=1 remaining quantity3 известна,
но alive postings может быть1или2. Canonical prefix allocation не доказывает
actual count. Точный distinct remaining-posting count нельзя обещать.

Сохраняются все пригодные quantity/CDF inputs. Порог risk-set остаётся
30units+10postings; для postings достаточно доказать lowerBound>=10.
Поле называется `atRiskPostingsLowerBound`, не exact count и не число
гарантированных идентификаторов. Метка метода/консервативности входит в diagnostics.
Недостаточный bound даёт недоступность соответствующей оценки/fallback,
а не исключение трудных групп из числителей и знаменателей.

## Формула без выдуманной аллокации

Для каждой пригодной order/SKU группы на возрастной boundary a:

- D(a)=G−R_before_a, source quantity и identity стабильны, все origins группы
  одинаковы. D=0 даёт groupMinimum0.
- Упорядочить положительные original posting capacities по убыванию. Minimum
  possible alive count — наименьшее k с суммой k largest capacities >=D(a).
  Это математический bound по вместимости, не присвоение возвратов этим postings.
- Posting с capacity_i>R_before_a гарантированно сохраняет хотя бы одну штуку
  данного SKU: общего R недостаточно, чтобы опустошить его целиком.
- Для scope order взять union гарантированных posting IDs по всем его SKU.
  Order lower bound = max(size этого distinct union, max(groupMinimum по SKU)).
- Sum order lower bounds допустима только при доказанном posting ID→один order
  в company/account; shared posting имеет согласованный delivery origin.
  Identity/origin conflict — unavailable, не искусственное раздвоение posting.

SKU scope использует те же правила с одним SKU: groupMinimum можно суммировать
по разным orders только при том же доказательстве disjoint identity. Account
scope не складывает group minima разных SKU одного order. Полученный bound
может быть ниже фактического числа; ложный достаточный sample невозможен.
На age0, где R_before_0=0, guaranteed union даёт exact original sample count.
По возрасту quantities и bound не увеличиваются. Initial delivered sample
postings остаётся отдельным exact distinct count для min100units/30postings.

## Простые шаги и чек-лист

1. Зафиксировать метод в ADR-049 и выполнить2actual Codex CLI contract review
   до return risk-field implementation. Другие consumer шаги независимы.
2. В B02 return aggregate вычислить quantities/prefix как раньше, original
   sample exact, at-risk profile61entries только с указанным lower-bound именем.
3. Не вводить physical R→posting allocation, не менять исходную source history,
   min thresholds или eligibility populations ради удобного sample count.
4. Проверить meaningful cases и shared identities в existing training tests:
   `[3,1],R1→bound1`; `[1,1,1],R1→bound2`; all returned→0; R0→exactN;
   shared postings между SKU не считаются дважды; гарантированные разные IDs
   разных SKU усиливают order bound; разные orders суммируются; конфликт order
   или origin отклоняется. Account bound>=10 должен действительно доказывать10.
5. В B03/B04 quantitative q остаётся `(R_H−R_a)/(G−R_a)`, а sample readiness
   использует riskunits>=30 и posting lowerBound>=10. Эти задачи ещё не реализованы.

- [x] Неидентифицируемость exact count подтверждена двумя допустимыми аллокациями.
- [x] Root выбрал conservative proof, не ослабляющий min10.
- [x] Contract reviewed независимыми Codex CLI, triage зафиксирован.
- [ ] Consumer DTO/SQL/method diagnostics и meaningful cases реализованы.
- [ ] Runtime/EXPLAIN/final code reviews подтверждены вместе с B02-training.

Новых зависимостей, схемы, API или очередей нет; numeric rollout закрыт.
Теоретическое доказательство не выдаётся за пройденные runtime/accuracy checks.

## Приёмка контракта

Actual2Codex CLI conformity0/defects0, complete/exit0, package SHA
7809b959fdf98b23f9b9c58c627790828f967239ea04d597a2735b1fd484565d.
JSON: var/review/runs/20261028T062236.896708Z-7809b959fdf9-codex-2agz8e3t/review.json
и var/review/runs/20261028T062236.897940Z-7809b959fdf9-defects-7fhdkwbx/review.json.
Модельgpt-6.1-sol, distinct session IDs сохранены в stderr; замечаний0,
принятых/отклонённых0. Runtime/SQL ещё не приняты. Claude пропущен по waiver.
Root дополнительно сверил canonical Version20260925150000: recognized delivered
ветка имеет только D+R=source.quantity, T2/P относятся к cancelled siblings.
В этой return population stable proven delivered source quantities дают
точные исходные gross capacities; псевдоаллокация T2/P в них не переносится.
