import { ChevronLeft, ChevronRight, CircleX, Plug } from 'lucide-react'
import { useEffect } from 'react'
import { useParams, useSearchParams } from 'react-router'
import type { components } from '../../../api/schema'
import {
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { useConnections } from '../../../shared/model/useConnections'
import {
  canGoForward,
  cellTitle,
  currentMonth,
  dayNumber,
  monthLabel,
  shiftMonth,
  statusView,
  type CoverageStatus,
} from '../lib/coverage'
import { useDataCoverage } from '../model/useDataCoverage'

type CoverageRow = components['schemas']['DataCoverageRowResponse']

const LEGEND: CoverageStatus[] = ['loaded', 'missing', 'failed', 'pending']

/**
 * Полнота данных: какие эндпоинты Ozon за какие дни месяца загружены.
 *
 * Тепловая карта: строка — эндпоинт, колонка — день, статус — цвет
 * и форма квадрата без текста (текст в каждой ячейке сделал бы карту
 * нечитаемой); подробности — в подсказке. Сверху «Итого» — худший статус
 * дня. Кабинет и месяц — в адресе, чтобы ссылкой можно было поделиться.
 */
export function DataCoveragePage() {
  const { companyId = '' } = useParams<{ companyId: string }>()
  const [params, setParams] = useSearchParams()
  const connections = useConnections(companyId)

  const current = currentMonth()
  const month = /^\d{4}-\d{2}$/.test(params.get('month') ?? '')
    ? (params.get('month') ?? current)
    : current
  const list = connections.data?.connections ?? []
  const accountId =
    list.find((connection) => connection.id === params.get('account'))?.id ??
    list[0]?.id ??
    null
  const coverage = useDataCoverage(companyId, accountId, month)

  // Выбранные по умолчанию кабинет и месяц — сразу в адрес: ссылка должна
  // однозначно называть, чей и за какой месяц отчёт на экране, и не
  // «уезжать» на следующий месяц, когда её откроют позже.
  useEffect(() => {
    const accountStale = accountId !== null && params.get('account') !== accountId
    const monthStale = params.get('month') !== month
    if (accountStale || monthStale) {
      const merged = new URLSearchParams(params)
      if (accountId !== null) merged.set('account', accountId)
      merged.set('month', month)
      setParams(merged, { replace: true })
    }
  }, [accountId, month, params, setParams])

  const update = (next: { account?: string; month?: string }) => {
    const merged = new URLSearchParams(params)
    if (next.account !== undefined) merged.set('account', next.account)
    if (next.month !== undefined) merged.set('month', next.month)
    setParams(merged, { replace: true })
  }

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-semibold">Полнота данных</h1>
      <p className="text-sm text-text-muted">
        Какие данные Ozon за какие дни загружены. Пустой квадрат — день,
        за который данных нет, крестик — загрузка упала.
      </p>

      <div className="flex flex-wrap items-center gap-3">
        {/* Нативный select: Select в packages/ui нет намеренно
            (docs/patterns.md, «Чего в UI Kit нет и почему»). */}
        <label className="flex items-center gap-1.5 text-sm text-text-muted">
          Кабинет
          <select
            className="h-8 cursor-pointer rounded-md border border-border-default bg-surface-raised px-2 text-sm font-medium text-text-secondary focus:border-accent-default focus:outline-2 focus:outline-border-focus"
            onChange={(event) => {
              update({ account: event.target.value })
            }}
            value={accountId ?? ''}
          >
            {list.map((connection) => (
              <option key={connection.id} value={connection.id}>
                Ozon · {connection.externalShopId}
              </option>
            ))}
          </select>
        </label>

        <div className="ml-auto flex items-center gap-1">
          <Button
            aria-label="Предыдущий месяц"
            onClick={() => {
              update({ month: shiftMonth(month, -1) })
            }}
            size="compact"
            type="button"
            variant="ghost"
          >
            <ChevronLeft size={16} />
          </Button>
          <span className="min-w-36 text-center text-sm font-medium">
            {monthLabel(month)}
          </span>
          <Button
            aria-label="Следующий месяц"
            disabled={!canGoForward(month, current)}
            onClick={() => {
              update({ month: shiftMonth(month, 1) })
            }}
            size="compact"
            type="button"
            variant="ghost"
          >
            <ChevronRight size={16} />
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap gap-4 text-xs text-text-muted">
        {LEGEND.map((status) => (
          <span className="flex items-center gap-1.5" key={status}>
            <Cell status={status} title={statusView(status).label} />
            {statusView(status).label}
          </span>
        ))}
      </div>

      {connections.isError ? (
        <Card tone="negative">
          <StatusPanel
            action={
              <Button
                onClick={() => {
                  void connections.refetch()
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Повторить
              </Button>
            }
            description="Не удалось получить список кабинетов."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Ошибка загрузки"
            tone="negative"
          />
        </Card>
      ) : connections.isSuccess && list.length === 0 ? (
        <Card>
          <StatusPanel
            description="Подключите кабинет Ozon — и здесь появится карта загрузки."
            icon={<Plug aria-hidden="true" size={20} />}
            role="status"
            title="Нет подключений"
          />
        </Card>
      ) : coverage.isError ? (
        <Card tone="negative">
          <StatusPanel
            action={
              <Button
                onClick={() => {
                  void coverage.refetch()
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Повторить
              </Button>
            }
            description="Не удалось получить отчёт."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Ошибка загрузки отчёта"
            tone="negative"
          />
        </Card>
      ) : coverage.data === undefined ? (
        <Card>
          <div className="h-32 animate-pulse rounded bg-border-subtle" />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full border-separate border-spacing-1 text-xs">
              <thead>
                <tr>
                  <th className="text-left font-medium text-text-muted">
                    Эндпоинт
                  </th>
                  {coverage.data.days.map((day) => (
                    <th className="w-4 font-normal text-text-faint" key={day}>
                      {dayNumber(day)}
                    </th>
                  ))}
                  <th className="pl-2 text-right font-medium text-text-muted">
                    Покрыто
                  </th>
                </tr>
              </thead>
              <tbody>
                <CoverageLine
                  days={coverage.data.days}
                  row={coverage.data.total}
                  strong
                />
                {coverage.data.rows.map((row) => (
                  <CoverageLine days={coverage.data.days} key={row.key} row={row} />
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}

function CoverageLine({
  row,
  days,
  strong = false,
}: {
  row: CoverageRow
  days: string[]
  strong?: boolean
}) {
  return (
    <tr>
      <td className="whitespace-nowrap pr-3">
        <span className={strong ? 'font-semibold' : 'font-medium'}>
          {row.section}
        </span>
        {row.endpoint === '' ? null : (
          <span className="ml-2 font-mono text-text-faint">{row.endpoint}</span>
        )}
      </td>
      {days.map((day, index) => {
        const status = row.statuses[index] ?? 'pending'

        return (
          <td key={day}>
            <Cell
              status={status}
              title={cellTitle(day, row, status, row.lastReceivedAt[index] ?? null)}
            />
          </td>
        )
      })}
      <td className="whitespace-nowrap pl-2 text-right tabular-nums text-text-secondary">
        {row.covered}/{row.due}
      </td>
    </tr>
  )
}

function Cell({ status, title }: { status: CoverageStatus; title: string }) {
  const view = statusView(status)

  return (
    <span
      aria-label={title}
      className={`flex h-4 w-4 items-center justify-center rounded-sm text-xs leading-none ${view.cell}`}
      role="img"
      title={title}
    >
      {view.mark}
    </span>
  )
}
