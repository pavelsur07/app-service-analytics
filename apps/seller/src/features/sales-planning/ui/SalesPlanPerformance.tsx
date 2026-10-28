import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { recoverPerformance } from '../lib/performanceRecovery'
import { Button, Card, Input } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { ApiError } from '../../../api/ApiError'
import { GROUPINGS } from '../lib/salesPlan'
import { performanceRangeProblem } from '../lib/performanceWindow'
import { useSalesPlanPerformance } from '../model/useSalesPlanPerformance'
import { PerformanceGrid } from './PerformanceGrid'
import {
  readPerformanceSearch,
  writePerformanceSearch,
} from '../lib/performanceSearch'
import {
  performanceCursorStack,
  performancePageScope,
} from '../lib/performancePaging'

type Plan = components['schemas']['SalesPlanResponse']

export function SalesPlanPerformance({
  companyId,
  plan,
  onReread,
}: {
  companyId: string
  plan: Plan
  onReread: () => Promise<unknown>
}) {
  const [search, setSearch] = useSearchParams()
  const context = { companyId, plan }
  const view = readPerformanceSearch(search, context)
  const { applied, mode, cursor } = view
  const canonical = writePerformanceSearch(search, view, context).toString()
  const rawSearch = search.toString()
  useEffect(() => {
    if (canonical !== rawSearch)
      setSearch(new URLSearchParams(canonical), { replace: true })
  }, [canonical, rawSearch, setSearch])
  const draftScope = JSON.stringify([companyId, plan.id, applied])
  const [draftState, setDraftState] = useState({
    scope: draftScope,
    value: applied,
  })
  const draft = draftState.scope === draftScope ? draftState.value : applied
  const setDraft = (value: typeof applied) => {
    setDraftState({ scope: draftScope, value })
  }
  const updateURL = (next: typeof view) => {
    setSearch(writePerformanceSearch(search, next, context))
  }
  const scope = performancePageScope(companyId, plan.id, plan.version, applied)
  const [pages, setPages] = useState<{
    scope: string
    stack: (string | null)[]
  }>({ scope, stack: [cursor] })
  const stack = performanceCursorStack(pages, scope, cursor)
  const problem = performanceRangeProblem(
    draft.from,
    draft.to,
    draft.groupBy,
    plan.dateFrom,
    plan.dateTo,
  )
  const query = useSalesPlanPerformance(
    companyId,
    plan.id,
    { ...applied, cursor, limit: 20 },
    plan.version,
    true,
  )
  return (
    <Card>
      <h2 className="text-lg font-semibold">
        Сравнение с исходной целью сезона
      </h2>
      <p className="mt-2 text-sm text-text-muted">
        По умолчанию — до вчера по Москве, не больше 31 видимого дня. Накопление
        всегда начинается с начала сезона.
      </p>
      <form
        className="mt-4 flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (problem === null) {
            updateURL({ ...view, applied: draft, cursor: null })
            setPages({
              scope: performancePageScope(
                companyId,
                plan.id,
                plan.version,
                draft,
              ),
              stack: [null],
            })
          }
        }}
      >
        <div className="flex flex-wrap gap-3">
          <Input
            label="Показать с"
            type="date"
            min={plan.dateFrom}
            max={plan.dateTo}
            value={draft.from}
            onChange={(event) => {
              setDraft({ ...draft, from: event.target.value })
            }}
          />
          <Input
            label="Показать по"
            type="date"
            min={plan.dateFrom}
            max={plan.dateTo}
            value={draft.to}
            onChange={(event) => {
              setDraft({ ...draft, to: event.target.value })
            }}
          />
        </div>
        <div
          className="flex flex-wrap gap-2"
          role="group"
          aria-label="Группировка сравнения"
        >
          {GROUPINGS.map((option) => (
            <Button
              key={option.value}
              type="button"
              size="compact"
              variant={draft.groupBy === option.value ? 'primary' : 'secondary'}
              aria-pressed={draft.groupBy === option.value}
              onClick={() => {
                setDraft({ ...draft, groupBy: option.value })
              }}
            >
              {option.label}
            </Button>
          ))}
        </div>
        {problem !== null && (
          <p className="text-sm text-negative-text" role="alert">
            {problem}
          </p>
        )}
        <div className="flex gap-2">
          <Button type="submit" size="compact" disabled={problem !== null}>
            Показать
          </Button>
          <Button
            type="button"
            size="compact"
            variant="secondary"
            onClick={() => {
              setDraft({
                from: plan.dateFrom,
                to: plan.dateTo,
                groupBy: 'period',
              })
            }}
          >
            Выбрать весь сезон
          </Button>
        </div>
      </form>
      <div
        className="my-4 flex flex-wrap gap-2"
        role="group"
        aria-label="Период или накопление"
      >
        <Button
          size="compact"
          variant={mode === 'period' ? 'primary' : 'secondary'}
          aria-pressed={mode === 'period'}
          onClick={() => {
            updateURL({ ...view, mode: 'period' })
          }}
        >
          За интервал
        </Button>
        <Button
          size="compact"
          variant={mode === 'cumulative' ? 'primary' : 'secondary'}
          aria-pressed={mode === 'cumulative'}
          onClick={() => {
            updateURL({ ...view, mode: 'cumulative' })
          }}
        >
          Накопительно
        </Button>
      </div>
      {query.isPending && <p role="status">Загружаем сравнение…</p>}
      {query.isError && (
        <div role="alert" className="text-sm text-negative-text">
          <p>
            {query.error instanceof ApiError &&
            query.error.code === 'sales_plan_no_approved_baseline'
              ? 'Сначала утвердите исходную цель. Черновик не является базой сравнения.'
              : query.error instanceof ApiError &&
                  query.error.code === 'sales_plan_metric_required'
                ? 'У старой цели не выбран смысл. Создайте новую версию и выберите метрику, сохранив исходные числа.'
                : 'Не удалось получить сравнение. Проверьте доступ и перечитайте план.'}
          </p>
          <Button
            size="compact"
            variant="secondary"
            onClick={() => {
              void recoverPerformance(onReread, () => query.refetch())
            }}
          >
            Перечитать план
          </Button>
        </div>
      )}
      {query.isSuccess && (
        <div className="flex flex-col gap-3">
          <div>
            <Button
              size="compact"
              variant="secondary"
              disabled={query.isFetching}
              onClick={() => {
                void query.refetch()
              }}
            >
              Обновить факт
            </Button>
          </div>
          <p className="text-sm text-text-secondary">
            Окно: {query.data.from} — {query.data.to}. Накопление с{' '}
            {query.data.accumulationFrom}. Цель:{' '}
            {query.data.metric === 'retained_sales'
              ? 'продажи за вычетом состоявшихся возвратов'
              : 'заказы без отмен до передачи'}
            .
          </p>
          <p className="text-sm text-text-muted">
            Покрытие с начала сезона:{' '}
            {query.data.ordersCoverage === 'complete'
              ? 'полное'
              : query.data.ordersCoverage === 'missing'
                ? 'нет загрузки'
                : 'частичное'}
            . Обновлено{' '}
            <time dateTime={query.data.calculatedAt}>
              {new Intl.DateTimeFormat('ru-RU', {
                timeZone: 'Europe/Moscow',
                dateStyle: 'short',
                timeStyle: 'short',
              }).format(new Date(query.data.calculatedAt))}
            </time>{' '}
            МСК. Возвраты могут уточнять наблюдаемые продажи.
          </p>
          <PerformanceGrid data={query.data} mode={mode} />
          <div className="flex justify-end gap-2">
            <Button
              size="compact"
              variant="secondary"
              disabled={cursor === null}
              onClick={() => {
                const previous =
                  stack.length > 1 ? (stack[stack.length - 2] ?? null) : null
                setPages({
                  scope,
                  stack: stack.length > 1 ? stack.slice(0, -1) : [null],
                })
                updateURL({ ...view, cursor: previous })
              }}
            >
              {stack.length > 1 ? 'Предыдущие SKU' : 'К первым SKU'}
            </Button>
            <Button
              size="compact"
              variant="secondary"
              disabled={query.data.nextCursor == null}
              onClick={() => {
                if (query.data.nextCursor != null) {
                  setPages({ scope, stack: [...stack, query.data.nextCursor] })
                  updateURL({ ...view, cursor: query.data.nextCursor })
                }
              }}
            >
              Следующие SKU
            </Button>
          </div>
        </div>
      )}
    </Card>
  )
}
