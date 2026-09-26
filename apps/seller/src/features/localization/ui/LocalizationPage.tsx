import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, CircleX, MapPinned } from 'lucide-react'
import { useNavigate, useParams, useSearchParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import {
  formatPerUnit,
  formatQuantity,
  formatShare,
  NO_DATA,
} from '../lib/localizationFormat'
import {
  LOCALIZATION_WINDOWS,
  localizationSearchWithCursor,
  localizationSearchWithDays,
  localizationSearchWithView,
  type LocalizationView,
  parseLocalizationDays,
  parseLocalizationView,
} from '../lib/localizationParams'
import {
  type LocalizationReportResponse,
  useLocalizationReport,
} from '../model/useLocalizationReport'
import {
  LocalizationClusterTable,
  LocalizationSkuTable,
} from './LocalizationTables'

// Двадцать пар «товар × кластер» — список «что куда довезти» читается
// целиком, а длинный хвост с малыми штуками уходит на следующие страницы.
const PAGE_SIZE = 20

const TABS: { view: LocalizationView; label: string }[] = [
  { view: 'clusters', label: 'Кластеры доставки' },
  { view: 'items', label: 'Что куда довезти' },
]

interface CursorStack {
  key: string
  cursors: (string | null)[]
}

export function LocalizationPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [search, setSearch] = useSearchParams()
  const days = parseLocalizationDays(search.get('days'))
  const view = parseLocalizationView(search.get('view'))
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

  const query = useLocalizationReport(
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
    setSearch(localizationSearchWithCursor(search, value), { replace: true })
  }

  const nextCursor =
    query.status === 'success' ? (query.data.nextCursor ?? null) : null
  // Сводка есть и тогда, когда проданных штук нет, а обратная логистика —
  // есть: невыкупы FBO — cancelled, в штуки они не входят, а в неё — да.
  const hasReport =
    query.status === 'success' &&
    (query.data.summary.quantity > 0 ||
      (query.data.summary.reverseCostMinor ?? null) !== null)
  // Сервер отвечает 422 на курсор прошлых суток: страница, открытая
  // по старой ссылке, предлагает начать с первой, а не просто ошибку.
  const staleCursor =
    query.error instanceof ApiError &&
    query.error.status === 422 &&
    cursor !== null

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold">Локализация</h1>
          <p className="max-w-3xl text-sm text-text-muted">
            Локальная продажа — товар отгружен из того же кластера Ozon, куда
            его доставляют. Считаются штуки по дате заказа, кроме отменённых;
            логистика на штуку — только там, где Ozon её уже начислил. Это наша
            доля локальных продаж, а не «индекс локализации» из кабинета Ozon:
            он считается по своей методике.
          </p>
        </div>
        <div className="flex items-center gap-1" aria-label="Период отчёта">
          {LOCALIZATION_WINDOWS.map((window) => (
            <Button
              aria-pressed={window === days}
              key={window}
              onClick={() => {
                setSearch(localizationSearchWithDays(search, window), {
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
            icon={<MapPinned aria-hidden="true" size={20} />}
            title="Считаем локализацию…"
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
            title="Не удалось посчитать локализацию"
            tone="negative"
          />
        </Card>
      ) : null}

      {query.status === 'success' && !hasReport ? (
        <Card>
          <StatusPanel
            description="За выбранный период нет заказов Ozon FBO."
            icon={<MapPinned aria-hidden="true" size={20} />}
            title="Пока считать нечего"
          />
        </Card>
      ) : null}

      {query.status === 'success' && hasReport ? (
        <Summary report={query.data} />
      ) : null}

      {query.status === 'success' && query.data.summary.quantity > 0 ? (
        <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
          <div
            aria-label="Отчёт"
            className="flex flex-wrap items-center gap-1 border-b border-border-default px-4 py-3"
            role="tablist"
          >
            {TABS.map((tab) => (
              <Button
                aria-controls={`localization-panel-${tab.view}`}
                aria-selected={tab.view === view}
                id={`localization-tab-${tab.view}`}
                key={tab.view}
                onClick={() => {
                  setSearch(localizationSearchWithView(search, tab.view), {
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
            <div
              aria-labelledby="localization-tab-clusters"
              id="localization-panel-clusters"
              role="tabpanel"
            >
              {query.data.clustersTruncated ? (
                <div className="border-b border-border-default px-4 py-2">
                  <span className="text-xs text-text-muted">
                    показаны крупнейшие {query.data.clusters.length}
                  </span>
                </div>
              ) : null}
              <LocalizationClusterTable items={query.data.clusters} />
            </div>
          ) : (
            <div
              aria-labelledby="localization-tab-items"
              id="localization-panel-items"
              role="tabpanel"
            >
              <div className="border-b border-border-default px-4 py-2">
                <span className="text-xs text-text-muted">
                  Товары по кластерам доставки, сверху — больше всего штук,
                  приехавших из другого кластера.
                </span>
              </div>
              <LocalizationSkuTable items={query.data.items} />
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
          )}
        </div>
      ) : null}
    </section>
  )
}

function Summary({ report }: { report: LocalizationReportResponse }) {
  const summary = report.summary

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Tile
        hint={`${formatQuantity(summary.localQuantity)} из ${formatQuantity(summary.clusteredQuantity)}`}
        label="Доля локальных продаж"
        value={formatShare(summary.localShareBps)}
      />
      <Tile
        hint={`${formatQuantity(summary.nonlocalQuantity)} везли из другого кластера`}
        label="Продано за период"
        value={formatQuantity(summary.quantity)}
      />
      <Tile
        hint={`локальных · нелокальных; начислена у ${formatShare(summary.chargedShareBps)} штук, округление до копейки`}
        label="Логистика на штуку"
        value={`${formatPerUnit(summary.localForwardCostPerUnitMinor, summary.currency)} · ${formatPerUnit(summary.nonlocalForwardCostPerUnitMinor, summary.currency)}`}
      />
      <Tile
        hint="включая невыкупы и отмены"
        label="Обратная логистика"
        value={
          summary.reverseCostMinor === null ||
          summary.reverseCostMinor === undefined ||
          summary.currency === null ||
          summary.currency === undefined
            ? NO_DATA
            : formatMinorAmount(summary.reverseCostMinor, summary.currency)
        }
      />
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
