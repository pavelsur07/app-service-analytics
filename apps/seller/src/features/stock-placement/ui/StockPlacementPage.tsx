import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, CircleX, Warehouse } from 'lucide-react'
import { useNavigate, useParams, useSearchParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { STATUS_PRESENTATION } from '../lib/stockPlacementFormat'
import {
  daysOptions,
  LEAD_PRESETS,
  parseStockPlacementView,
  STOCK_PLACEMENT_STATUSES,
  stockPlacementSearchWithCursor,
  stockPlacementSearchWithView,
  type StockPlacementView,
  TARGET_PRESETS,
} from '../lib/stockPlacementParams'
import {
  type StockPlacementReportResponse,
  useStockPlacement,
} from '../model/useStockPlacement'
import { StockPlacementTable } from './StockPlacementTable'

const PAGE_SIZE = 50
const QUANTITY = new Intl.NumberFormat('ru-RU')
const SELECT =
  'h-8 cursor-pointer rounded-md border border-border-default bg-surface-raised px-2 text-sm text-text-secondary focus:border-accent-default focus:outline-2 focus:outline-border-focus'

interface CursorStack {
  key: string
  cursors: (string | null)[]
}

export function StockPlacementPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [search, setSearch] = useSearchParams()
  const view = parseStockPlacementView(search)
  const rawCursor = search.get('cursor')
  const cursor = rawCursor === '' ? null : rawCursor
  const viewKey = `${companyId ?? ''}:${view.targetDays}:${view.leadDays}:${view.status ?? ''}`
  const [stack, setStack] = useState<CursorStack>({
    key: viewKey,
    cursors: [cursor],
  })
  const cursors =
    stack.key === viewKey && stack.cursors.at(-1) === cursor
      ? stack.cursors
      : [cursor]

  const query = useStockPlacement(
    companyId ?? '',
    { ...view, limit: PAGE_SIZE, cursor },
    { enabled: companyId !== undefined },
  )

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [query.error, navigate])

  if (companyId === undefined) {
    return (
      <Card tone="negative">
        <StatusPanel
          description="Откройте экран из списка компаний."
          icon={<CircleX aria-hidden="true" size={20} />}
          role="alert"
          title="Компания не выбрана"
          tone="negative"
        />
      </Card>
    )
  }

  const writeView = (next: StockPlacementView): void => {
    setSearch(stockPlacementSearchWithView(search, next), { replace: true })
    setStack({ key: '', cursors: [null] })
  }
  const writeCursor = (value: string | null): void => {
    setSearch(stockPlacementSearchWithCursor(search, value), { replace: true })
  }

  const nextCursor =
    query.status === 'success' ? (query.data.nextCursor ?? null) : null
  // Курсор привязан к дню расчёта: страница по ссылке прошлых суток
  // получает 422 и предлагает начать с первой.
  const staleCursor =
    query.error instanceof ApiError &&
    query.error.status === 422 &&
    cursor !== null

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold">Остатки</h1>
          <p className="max-w-3xl text-sm text-text-muted">
            Остаток Ozon FBO по кластерам против спроса в них. Спрос — ваши
            продажи с доставкой в кластер за последние полные дни без
            отменённых; остаток — последний полный снимок, включая пункты
            выдачи. «Довезти» — сколько штук нужно в кластере, чтобы хватило на
            срок поставки и целевое покрытие.
          </p>
        </div>
        {/* Нативный select, а не примитив: Select в packages/ui нет
            намеренно (docs/patterns.md, «Чего в UI Kit нет и почему»). */}
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex flex-col gap-1 text-xs font-medium text-text-muted">
            Целевое покрытие
            <select
              className={SELECT}
              onChange={(event) => {
                writeView({ ...view, targetDays: Number(event.target.value) })
              }}
              value={view.targetDays}
            >
              {daysOptions(TARGET_PRESETS, view.targetDays).map((days) => (
                <option key={days} value={days}>
                  {days} дней
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-text-muted">
            Срок поставки
            <select
              className={SELECT}
              onChange={(event) => {
                writeView({ ...view, leadDays: Number(event.target.value) })
              }}
              value={view.leadDays}
            >
              {daysOptions(LEAD_PRESETS, view.leadDays).map((days) => (
                <option key={days} value={days}>
                  {days} дней
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-text-muted">
            Статус
            <select
              className={SELECT}
              onChange={(event) => {
                writeView({
                  ...view,
                  status:
                    STOCK_PLACEMENT_STATUSES.find(
                      (status) => status === event.target.value,
                    ) ?? null,
                })
              }}
              value={view.status ?? ''}
            >
              <option value="">Все</option>
              {STOCK_PLACEMENT_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {STATUS_PRESENTATION[status].label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </header>

      {query.status === 'pending' ? (
        <Card>
          <StatusPanel
            icon={<Warehouse aria-hidden="true" size={20} />}
            title="Считаем остатки…"
          />
        </Card>
      ) : null}

      {query.status === 'error' ? (
        <Card tone="negative">
          <StatusPanel
            action={
              staleCursor ? (
                <Button
                  onClick={() => {
                    setStack({ key: '', cursors: [null] })
                    writeCursor(null)
                  }}
                  size="compact"
                  type="button"
                  variant="secondary"
                >
                  С первой страницы
                </Button>
              ) : (
                <Button
                  onClick={() => {
                    void query.refetch()
                  }}
                  size="compact"
                  type="button"
                  variant="secondary"
                >
                  Повторить
                </Button>
              )
            }
            description={
              staleCursor
                ? 'Ссылка на страницу устарела: курсор живёт до следующих суток.'
                : query.error instanceof Error
                  ? query.error.message
                  : 'Попробуйте обновить страницу.'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось посчитать остатки"
            tone="negative"
          />
        </Card>
      ) : null}

      {query.status === 'success' && query.data.snapshotDate === null ? (
        <Card>
          <StatusPanel
            description="Остатки Ozon снимаются раз в сутки ночью и в первые часы после подключения кабинета. Если кабинет подключён давно, проверьте раздел «Подключения»."
            icon={<Warehouse aria-hidden="true" size={20} />}
            title="Свежего снимка остатков нет"
          />
        </Card>
      ) : null}

      {query.status === 'success' && query.data.snapshotDate !== null ? (
        <>
          <Notices report={query.data} />
          <Summary report={query.data} />

          <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
            <div className="flex flex-col gap-0.5 border-b border-border-default px-4 py-3">
              <span className="font-semibold">Что довезти</span>
              <span className="text-xs text-text-muted">
                Товар × кластер доставки. Сверху — где покупатели дольше всего
                ждали товар из чужого кластера, затем — больше всего довезти.
                Рекомендация считается от {query.data.definitions.minSales}{' '}
                продаж за {query.data.definitions.demandWindowDays} дней.
              </span>
            </div>
            {query.data.items.length === 0 && cursors.length <= 1 ? (
              <p className="px-4 py-6 text-sm text-text-muted">
                {view.status === null
                  ? 'Нет ни остатков, ни продаж по кластерам.'
                  : 'Позиций с таким статусом нет.'}
              </p>
            ) : (
              <StockPlacementTable
                demandWindowDays={query.data.definitions.demandWindowDays}
                items={query.data.items}
              />
            )}
            <div className="flex items-center justify-end gap-2 border-t border-border-default px-4 py-2">
              <Button
                disabled={cursors.length <= 1}
                onClick={() => {
                  const previous = cursors.slice(0, -1)
                  setStack({ key: viewKey, cursors: previous })
                  writeCursor(previous.at(-1) ?? null)
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                <ChevronLeft aria-hidden="true" size={16} />
                Назад
              </Button>
              <Button
                disabled={nextCursor === null}
                onClick={() => {
                  if (nextCursor !== null) {
                    setStack({
                      key: viewKey,
                      cursors: [...cursors, nextCursor],
                    })
                    writeCursor(nextCursor)
                  }
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Дальше
                <ChevronRight aria-hidden="true" size={16} />
              </Button>
            </div>
          </div>
        </>
      ) : null}
    </section>
  )
}

// Неполнота данных называется словами, а не прячется в цифрах: сломанный
// кабинет или отсутствие истории снимков выглядят как рабочий экран.
function Notices({ report }: { report: StockPlacementReportResponse }) {
  const notices: string[] = []
  if (report.staleAccounts > 0) {
    notices.push(
      `У ${QUANTITY.format(report.staleAccounts)} подключ. нет свежего полного снимка — остаток ${QUANTITY.format(report.unknownPositions)} поз. неизвестен, рекомендации по ним нет. Проверьте раздел «Подключения».`,
    )
  } else if (report.unknownPositions > 0) {
    notices.push(
      `Остаток ${QUANTITY.format(report.unknownPositions)} поз. неизвестен: товара не было в последнем снимке — например, карточка появилась позже. В итоги «Дефицит» и «Довезти» они не входят; остаток появится со следующим снимком.`,
    )
  }
  if (!report.correctionApplied) {
    notices.push(
      `Поправка на дни без остатка включится, когда накопится ${QUANTITY.format(report.definitions.demandWindowDays)} дней снимков (сейчас ${QUANTITY.format(report.completeSnapshotDays)}). До тех пор спрос там, где товар кончался, занижен.`,
    )
  }
  if (notices.length === 0) {
    return null
  }

  return (
    <Card tone="warning">
      <ul className="flex list-disc flex-col gap-1 pl-5 text-sm">
        {notices.map((notice) => (
          <li key={notice}>{notice}</li>
        ))}
      </ul>
    </Card>
  )
}

function Summary({ report }: { report: StockPlacementReportResponse }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Tile
        hint={`кончится раньше, чем доедет поставка (${report.definitions.leadDays} дн)`}
        label="Дефицит"
        value={`${QUANTITY.format(report.deficitPositions)} поз.`}
      />
      <Tile
        hint={`${QUANTITY.format(report.recommendedPositions)} поз. на покрытие ${report.definitions.targetDays} дн`}
        label="Довезти"
        value={`${QUANTITY.format(report.recommendedUnits)} шт`}
      />
      <Tile
        hint={`запаса больше чем на ${report.definitions.surplusFactor * report.definitions.targetDays} дн`}
        label="Излишек"
        value={`${QUANTITY.format(report.surplusPositions)} поз.`}
      />
      <Tile
        hint={`снимок от ${formatDate(report.snapshotDate)}`}
        label="История снимков"
        value={`${QUANTITY.format(report.completeSnapshotDays)} из ${QUANTITY.format(report.definitions.demandWindowDays)} дн`}
      />
    </div>
  )
}

function formatDate(date: string | null): string {
  if (date === null) {
    return '—'
  }
  const [year, month, day] = date.split('-')

  return `${day ?? ''}.${month ?? ''}.${year ?? ''}`
}

function Tile({
  label,
  value,
  hint,
}: {
  label: string
  value: string
  hint: string
}) {
  return (
    <Card>
      <div className="flex flex-col gap-1">
        <span className="text-xs font-medium text-text-muted">{label}</span>
        <span className="text-lg font-semibold tabular-nums">{value}</span>
        <span className="text-xs text-text-muted">{hint}</span>
      </div>
    </Card>
  )
}
