import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, CircleX, PackageCheck } from 'lucide-react'
import { useNavigate, useParams, useSearchParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import {
  BUYOUT_WINDOWS,
  buyoutSearchWithCursor,
  buyoutSearchWithDays,
  buyoutSearchWithSort,
  nextBuyoutSort,
  parseBuyoutDays,
  parseBuyoutSort,
  parseBuyoutSortDirection,
} from '../lib/buyoutParams'
import { formatRateBps } from '../lib/buyoutStatusPresentation'
import { useBuyoutRates } from '../model/useBuyoutRates'
import { BuyoutRateTable } from './BuyoutRateTable'

// В отчёте строка тяжелее обычного списка: вместе с основными цифрами
// она несёт статус матурации и раскрываемый график. Десять строк дают
// сравнить товары без длинного полотна и остаются далеко ниже API cap=200.
const PAGE_SIZE = 10
const QUANTITY = new Intl.NumberFormat('ru-RU')

interface CursorStack {
  key: string
  cursors: (string | null)[]
}

interface ExpandedSku {
  scope: string
  marketplaceSku: string | null
}

export function BuyoutRatePage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [search, setSearch] = useSearchParams()
  const days = parseBuyoutDays(search.get('days'))
  const sort = parseBuyoutSort(search.get('sort'))
  const direction = parseBuyoutSortDirection(search.get('direction'))
  const rawCursor = search.get('cursor')
  const cursor = rawCursor === '' ? null : rawCursor
  const viewKey = `${companyId ?? ''}:${days}:${sort}:${direction}`
  const pageScope = `${viewKey}:${cursor ?? ''}`
  const [stack, setStack] = useState<CursorStack>({
    key: viewKey,
    cursors: [cursor],
  })
  const [expanded, setExpanded] = useState<ExpandedSku>({
    scope: pageScope,
    marketplaceSku: null,
  })

  const cursors =
    stack.key === viewKey && stack.cursors.at(-1) === cursor
      ? stack.cursors
      : [cursor]
  const expandedSku =
    expanded.scope === pageScope ? expanded.marketplaceSku : null

  const query = useBuyoutRates(
    companyId ?? '',
    { days, limit: PAGE_SIZE, sort, direction, cursor },
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
    setSearch(buyoutSearchWithCursor(search, value), { replace: true })
  }

  const nextCursor =
    query.status === 'success' ? (query.data.nextCursor ?? null) : null

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold">Выкуп</h1>
          <p className="max-w-3xl text-sm text-text-muted">
            Фактический выкуп = доставлено / (доставлено + невыкуп после
            передачи + частичный выкуп). Отмены до передачи, незавершённые
            заказы и возвраты после покупки в факт не входят. Прогноз для свежих
            заказов показан отдельно.
          </p>
        </div>
        <div className="flex items-center gap-1" aria-label="Период отчёта">
          {BUYOUT_WINDOWS.map((window) => (
            <Button
              aria-pressed={window === days}
              key={window}
              onClick={() => {
                setSearch(buyoutSearchWithDays(search, window), {
                  replace: true,
                })
                setStack({ key: '', cursors: [null] })
                setExpanded({ scope: '', marketplaceSku: null })
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
            icon={<PackageCheck aria-hidden="true" size={20} />}
            title="Считаем процент выкупа…"
          />
        </Card>
      ) : null}

      {query.status === 'error' ? (
        <Card tone="negative">
          <StatusPanel
            action={
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
            }
            description={
              query.error instanceof Error
                ? query.error.message
                : 'Попробуйте обновить страницу.'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось посчитать выкуп"
            tone="negative"
          />
        </Card>
      ) : null}

      {query.status === 'success' ? (
        <>
          {query.data.items.length > 0 || cursor !== null ? (
            <Card>
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-text-muted">
                  Прогноз по всему периоду
                </span>
                <p className="text-lg font-semibold">
                  {query.data.summary.projectedBuyoutRateBps === null ||
                  query.data.summary.projectedBuyoutRateBps === undefined
                    ? 'Недостаточно данных для прогноза выкупа'
                    : `${formatRateBps(query.data.summary.projectedBuyoutRateBps)} прогноз выкупа`}
                  {' · '}
                  {formatRateBps(query.data.summary.resolutionRateBps)} заказов
                  разрешилось
                  {' · '}
                  ожидается{' '}
                  {query.data.summary.projectedBuyoutQuantity === null ||
                  query.data.summary.projectedBuyoutQuantity === undefined
                    ? '—'
                    : QUANTITY.format(
                        query.data.summary.projectedBuyoutQuantity,
                      )}{' '}
                  выкупленных шт
                </p>
                <p className="text-xs text-text-muted">
                  Заказано {QUANTITY.format(query.data.summary.orderedQuantity)}{' '}
                  шт; разрешилось{' '}
                  {QUANTITY.format(query.data.summary.resolvedQuantity)} шт;
                  итоговый процент дозревает вместе со свежими заказами.
                </p>
              </div>
            </Card>
          ) : null}

          {query.data.items.length === 0 && cursor === null ? (
            <Card>
              <StatusPanel
                description="За выбранный период нет заказов Ozon."
                icon={<PackageCheck aria-hidden="true" size={20} />}
                title="Пока считать нечего"
              />
            </Card>
          ) : null}

          {query.data.items.length > 0 || cursor !== null ? (
            <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
              <div className="flex items-center gap-3 border-b border-border-default px-4 py-3">
                <span className="font-semibold">Товары</span>
                <span className="text-xs text-text-muted">
                  {query.data.items.length} строк на странице
                </span>
              </div>
              <div className="overflow-x-auto">
                <BuyoutRateTable
                  companyId={companyId}
                  days={days}
                  expandedSku={expandedSku}
                  items={query.data.items}
                  sort={sort}
                  direction={direction}
                  onExpandedSkuChange={(marketplaceSku) => {
                    setExpanded({ scope: pageScope, marketplaceSku })
                  }}
                  onSort={(clicked) => {
                    const next = nextBuyoutSort(clicked, sort, direction)
                    setSearch(
                      buyoutSearchWithSort(search, next.sort, next.direction),
                      { replace: true },
                    )
                    setStack({ key: '', cursors: [null] })
                    setExpanded({ scope: '', marketplaceSku: null })
                  }}
                />
              </div>
              <div className="flex items-center justify-end gap-2 border-t border-border-default px-4 py-2">
                <Button
                  disabled={cursors.length <= 1}
                  onClick={() => {
                    const previous = cursors.slice(0, -1)
                    const previousCursor = previous.at(-1) ?? null
                    setStack({ key: viewKey, cursors: previous })
                    writeCursor(previousCursor)
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
          ) : null}
        </>
      ) : null}
    </section>
  )
}
