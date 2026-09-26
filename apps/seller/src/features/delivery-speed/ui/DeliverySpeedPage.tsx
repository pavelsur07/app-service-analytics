import { type ReactNode, useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, CircleX, Truck } from 'lucide-react'
import { useNavigate, useParams, useSearchParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { formatDuration } from '../lib/deliverySpeedFormat'
import {
  DELIVERY_SPEED_WINDOWS,
  deliverySpeedSearchWithCursor,
  deliverySpeedSearchWithDays,
  deliverySpeedSearchWithView,
  type DeliverySpeedView,
  parseDeliverySpeedDays,
  parseDeliverySpeedView,
} from '../lib/deliverySpeedParams'
import {
  type DeliverySpeedReportResponse,
  useDeliverySpeed,
} from '../model/useDeliverySpeed'
import {
  DeliverySpeedBuyoutTable,
  DeliverySpeedClusterTable,
  DeliverySpeedRouteTable,
  DeliverySpeedSkuTable,
} from './DeliverySpeedTables'

// Двадцать пар «товар × кластер» — список «что довезти первым» читается
// целиком, а хвост с малыми потерями уходит на следующие страницы.
const PAGE_SIZE = 20
const QUANTITY = new Intl.NumberFormat('ru-RU')

const TABS: { view: DeliverySpeedView; label: string }[] = [
  { view: 'clusters', label: 'Где раскладка стоит дороже всего' },
  { view: 'items', label: 'Что довезти первым' },
  { view: 'buyout', label: 'Выкуп по скорости доставки' },
  { view: 'routes', label: 'Маршруты' },
]

interface CursorStack {
  key: string
  cursors: (string | null)[]
}

export function DeliverySpeedPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [search, setSearch] = useSearchParams()
  const days = parseDeliverySpeedDays(search.get('days'))
  const view = parseDeliverySpeedView(search.get('view'))
  const rawCursor = search.get('cursor')
  const cursor = rawCursor === '' ? null : rawCursor
  const viewKey = `${companyId ?? ''}:${days}`
  const [stack, setStack] = useState<CursorStack>({
    key: viewKey,
    cursors: [cursor],
  })
  const cursors =
    stack.key === viewKey && stack.cursors.at(-1) === cursor
      ? stack.cursors
      : [cursor]

  const query = useDeliverySpeed(
    companyId ?? '',
    { days, limit: PAGE_SIZE, cursor },
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

  const writeCursor = (value: string | null): void => {
    setSearch(deliverySpeedSearchWithCursor(search, value), { replace: true })
  }

  const nextCursor =
    query.status === 'success' ? (query.data.nextCursor ?? null) : null
  // Сервер отвечает 422 на курсор прошлых суток: страница, открытая
  // по старой ссылке, предлагает начать с первой, а не просто ошибку.
  const staleCursor =
    query.error instanceof ApiError &&
    query.error.status === 422 &&
    cursor !== null
  const hasReport =
    query.status === 'success' && query.data.summary.postings > 0
  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold">Доставка</h1>
          <p className="max-w-3xl text-sm text-text-muted">
            Время от заказа до прибытия в пункт выдачи или вручения курьером.
            Ozon не отдаёт точный момент, поэтому он оценивается серединой между
            опросами. Считаются заказы не моложе двух недель — свежие ещё
            доезжают — и только те, что синхронизация видела с первого дня.
          </p>
        </div>
        <div className="flex items-center gap-1" aria-label="Период отчёта">
          {DELIVERY_SPEED_WINDOWS.map((window) => (
            <Button
              aria-pressed={window === days}
              key={window}
              onClick={() => {
                setSearch(deliverySpeedSearchWithDays(search, window), {
                  replace: true,
                })
                setStack({ key: '', cursors: [null] })
              }}
              size="compact"
              type="button"
              variant={window === days ? 'primary' : 'secondary'}
            >
              {window} дней
            </Button>
          ))}
        </div>
      </header>

      {query.status === 'pending' ? (
        <Card>
          <StatusPanel
            icon={<Truck aria-hidden="true" size={20} />}
            title="Считаем скорость доставки…"
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
            title="Не удалось посчитать скорость доставки"
            tone="negative"
          />
        </Card>
      ) : null}

      {query.status === 'success' && !hasReport ? (
        <Card>
          <StatusPanel
            description="За выбранный период нет заказов Ozon FBO, которые мы видели с первого дня."
            icon={<Truck aria-hidden="true" size={20} />}
            title="Пока считать нечего"
          />
        </Card>
      ) : null}

      {query.status === 'success' && hasReport ? (
        <>
          <Summary report={query.data} />

          <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
            <div
              aria-label="Отчёт"
              className="flex flex-wrap items-center gap-1 border-b border-border-default px-4 py-3"
              role="tablist"
            >
              {TABS.map((tab) => (
                <Button
                  aria-controls={`delivery-speed-panel-${tab.view}`}
                  aria-selected={tab.view === view}
                  id={`delivery-speed-tab-${tab.view}`}
                  key={tab.view}
                  onClick={() => {
                    setSearch(deliverySpeedSearchWithView(search, tab.view), {
                      replace: true,
                    })
                  }}
                  role="tab"
                  size="compact"
                  type="button"
                  variant={tab.view === view ? 'primary' : 'ghost'}
                >
                  {tab.label}
                </Button>
              ))}
            </div>

            {view === 'clusters' ? (
              <Panel
                hint="Сколько лишних дней в сумме прождали покупатели, потому что товар везли из другого кластера: нелокальные доставки × разница медиан."
                truncated={query.data.clustersTruncated}
                view="clusters"
              >
                <DeliverySpeedClusterTable items={query.data.clusters} />
              </Panel>
            ) : null}

            {view === 'items' ? (
              <Panel
                hint="Товары по кластерам доставки, сверху — больше всего лишних дней ожидания."
                view="items"
              >
                {query.data.items.length === 0 && cursors.length <= 1 ? (
                  <p className="px-4 py-6 text-sm text-text-muted">
                    Пока не с чем сравнивать: потеря считается в кластере, где
                    доставлено не меньше {query.data.definitions.minPostings}{' '}
                    локальных и {query.data.definitions.minPostings} нелокальных
                    заказов.
                  </p>
                ) : (
                  <DeliverySpeedSkuTable items={query.data.items} />
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
              </Panel>
            ) : null}

            {view === 'buyout' ? (
              <Panel
                hint="Процент выкупа у заказов, доехавших за разное время."
                view="buyout"
              >
                <DeliverySpeedBuyoutTable items={query.data.buyoutBySpeed} />
              </Panel>
            ) : null}

            {view === 'routes' ? (
              <Panel
                hint="Кластер отгрузки → кластер доставки."
                truncated={query.data.routesTruncated}
                view="routes"
              >
                <DeliverySpeedRouteTable items={query.data.routes} />
              </Panel>
            ) : null}
          </div>
        </>
      ) : null}
    </section>
  )
}

function Summary({ report }: { report: DeliverySpeedReportResponse }) {
  const summary = report.summary

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Tile
        hint={`9 из 10 — быстрее ${formatDuration(summary.p90DeliverySeconds)}`}
        label="До покупателя, медиана"
        value={formatDuration(summary.medianDeliverySeconds)}
      />
      <Tile
        hint="сборка на складе · в пути"
        label="Этапы, медианы"
        value={`${formatDuration(summary.medianAssemblySeconds)} · ${formatDuration(summary.medianTransitSeconds)}`}
      />
      <Tile
        hint="из своего кластера · из чужого"
        label="Локальные против нелокальных"
        value={`${formatDuration(summary.medianLocalSeconds)} · ${formatDuration(summary.medianNonlocalSeconds)}`}
      />
      <Tile
        hint={`видели с первого дня ${QUANTITY.format(summary.postings)} из ${QUANTITY.format(report.periodPostings)}; у ${QUANTITY.format(summary.rescanArrivedPostings)} прибытие видно только ночным опросом, точность ±12 ч`}
        label="Доставлено за период"
        value={QUANTITY.format(summary.arrivedPostings)}
      />
    </div>
  )
}

function Panel({
  view,
  hint,
  truncated,
  children,
}: {
  view: DeliverySpeedView
  hint: string
  truncated?: boolean
  children: ReactNode
}) {
  return (
    <div
      aria-labelledby={`delivery-speed-tab-${view}`}
      id={`delivery-speed-panel-${view}`}
      role="tabpanel"
    >
      <div className="border-b border-border-default px-4 py-2">
        <span className="text-xs text-text-muted">
          {hint}
          {truncated === true ? ' Показаны крупнейшие 50.' : ''}
        </span>
      </div>
      {children}
    </div>
  )
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
