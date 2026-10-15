import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, CircleX, PackageSearch } from 'lucide-react'
import { useNavigate, useParams, useSearchParams } from 'react-router'

import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import {
  canGoBack,
  canGoForward,
  currentMonth,
  monthFromParam,
  monthLabel,
  shiftMonth,
} from '../../../shared/lib/month'
import {
  useSkuForecastFact,
  useSkuForecastFactSkus,
} from '../model/useSkuForecastFact'
import { SkuForecastFactTable } from './SkuForecastFactTable'

const SEARCH_LIMIT = 50

export function SkuForecastFactPage() {
  const [params] = useSearchParams()
  return <SkuForecastFactView key={params.get('sku') ?? ''} />
}

function SkuForecastFactView() {
  const navigate = useNavigate()
  const { companyId = '' } = useParams<{ companyId: string }>()
  const [params, setParams] = useSearchParams()
  const sku = params.get('sku') ?? ''
  const current = currentMonth()
  const month = monthFromParam(params.get('month'), current)
  const [searchInput, setSearchInput] = useState(sku)
  const [searchText, setSearchText] = useState(sku)
  const [choosing, setChoosing] = useState(sku === '')
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const cursor = cursorStack.at(-1) ?? null
  const skuResults = useSkuForecastFactSkus(
    companyId,
    searchText,
    SEARCH_LIMIT,
    cursor,
    { enabled: choosing },
  )
  const report = useSkuForecastFact(companyId, sku, month)

  useEffect(() => {
    if (params.get('month') !== month) {
      const next = new URLSearchParams(params)
      next.set('month', month)
      setParams(next, { replace: true })
    }
  }, [month, params, setParams])

  useEffect(() => {
    if (
      (skuResults.error instanceof ApiError &&
        skuResults.error.status === 403) ||
      (report.error instanceof ApiError && report.error.status === 403)
    ) {
      void navigate('/companies', { replace: true })
    }
  }, [navigate, report.error, skuResults.error])

  const updateParams = (next: { sku?: string; month?: string }) => {
    const merged = new URLSearchParams(params)
    if (next.sku !== undefined) merged.set('sku', next.sku)
    if (next.month !== undefined) merged.set('month', next.month)
    setParams(merged, { replace: true })
  }

  if (companyId === '') {
    return (
      <Card tone="negative">
        <StatusPanel
          description="Откройте отчёт из списка компаний."
          icon={<CircleX aria-hidden="true" size={20} />}
          role="alert"
          title="Компания не выбрана"
          tone="negative"
        />
      </Card>
    )
  }

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold">Прогноз и факт по SKU</h1>
        <p className="max-w-3xl text-sm text-text-muted">
          Выручка по дате заказа до СПП. Прогноз учитывает ожидаемый выкуп
          каждого заказа по его цене, поэтому может отличаться от произведения
          заказанной суммы на показанный процент. Факт прошлых когорт может
          измениться после позднего выкупа или возврата.
        </p>
      </header>

      <Card>
        <div className="flex flex-col gap-4">
          <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
              event.preventDefault()
              setSearchText(searchInput.trim())
              setCursorStack([null])
              setChoosing(true)
            }}
          >
            <div className="min-w-60 flex-1">
              <Input
                label="Найти SKU"
                maxLength={100}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder="SKU, название или артикул продавца"
                value={searchInput}
              />
            </div>
            <Button type="submit" variant="secondary">
              Найти
            </Button>
          </form>
          <p className="text-sm text-text-muted">
            Для снятых с продажи товаров введите точный SKU или не менее трёх
            букв или цифр подряд.
          </p>

          {!choosing ? null : skuResults.isPending ? (
            <p className="text-sm text-text-muted" role="status">
              Ищем товары…
            </p>
          ) : skuResults.isError ? (
            <StatusPanel
              action={
                <Button
                  onClick={() => void skuResults.refetch()}
                  size="compact"
                  type="button"
                  variant="secondary"
                >
                  Повторить
                </Button>
              }
              description="Не удалось найти SKU."
              icon={<CircleX aria-hidden="true" size={20} />}
              role="alert"
              title="Ошибка поиска"
              tone="negative"
            />
          ) : skuResults.data.items.length === 0 ? (
            <p className="text-sm text-text-muted">SKU не найдены.</p>
          ) : (
            <>
              <ul
                aria-label="Результаты поиска SKU"
                className="divide-y divide-border-subtle"
              >
                {skuResults.data.items.map((item) => (
                  <li key={item.marketplaceSku}>
                    <button
                      aria-current={
                        item.marketplaceSku === sku ? 'true' : undefined
                      }
                      className="flex w-full cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-2 py-2 text-left text-sm hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-accent-default"
                      onClick={() => {
                        updateParams({ sku: item.marketplaceSku })
                        setSearchInput(item.marketplaceSku)
                        setChoosing(false)
                      }}
                      type="button"
                    >
                      <span className="font-medium">
                        SKU {item.marketplaceSku}
                      </span>
                      {item.name ? (
                        <span className="text-text-secondary">{item.name}</span>
                      ) : null}
                      {item.offerId ? (
                        <span className="text-text-muted">{item.offerId}</span>
                      ) : null}
                    </button>
                  </li>
                ))}
              </ul>
              <div className="flex items-center justify-end gap-2">
                <Button
                  disabled={cursorStack.length === 1}
                  onClick={() => setCursorStack(cursorStack.slice(0, -1))}
                  size="compact"
                  type="button"
                  variant="ghost"
                >
                  <ChevronLeft aria-hidden="true" size={16} />
                  Назад
                </Button>
                <Button
                  disabled={skuResults.data.nextCursor == null}
                  onClick={() => {
                    if (skuResults.data.nextCursor != null) {
                      setCursorStack([
                        ...cursorStack,
                        skuResults.data.nextCursor,
                      ])
                    }
                  }}
                  size="compact"
                  type="button"
                  variant="ghost"
                >
                  Дальше
                  <ChevronRight aria-hidden="true" size={16} />
                </Button>
              </div>
            </>
          )}
        </div>
      </Card>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-text-secondary">
          {sku === '' ? 'Выберите SKU для отчёта' : `Выбран SKU ${sku}`}
        </p>
        <div aria-label="Месяц отчёта" className="flex items-center gap-1">
          <Button
            aria-label="Предыдущий месяц"
            disabled={!canGoBack(month)}
            onClick={() => updateParams({ month: shiftMonth(month, -1) })}
            size="compact"
            type="button"
            variant="ghost"
          >
            <ChevronLeft aria-hidden="true" size={16} />
          </Button>
          <span className="min-w-36 text-center text-sm font-medium">
            {monthLabel(month)}
          </span>
          <Button
            aria-label="Следующий месяц"
            disabled={!canGoForward(month, current)}
            onClick={() => updateParams({ month: shiftMonth(month, 1) })}
            size="compact"
            type="button"
            variant="ghost"
          >
            <ChevronRight aria-hidden="true" size={16} />
          </Button>
        </div>
      </div>

      {sku === '' ? (
        <Card>
          <StatusPanel
            description="Найдите товар по SKU, названию или артикулу продавца."
            icon={<PackageSearch aria-hidden="true" size={20} />}
            title="Выберите SKU"
          />
        </Card>
      ) : report.isPending ? (
        <Card>
          <StatusPanel
            icon={<PackageSearch aria-hidden="true" size={20} />}
            title="Загружаем отчёт…"
          />
        </Card>
      ) : report.isError ? (
        <Card tone="negative">
          <StatusPanel
            action={
              <Button
                onClick={() => void report.refetch()}
                size="compact"
                type="button"
                variant="secondary"
              >
                Повторить
              </Button>
            }
            description={
              report.error instanceof Error
                ? report.error.message
                : 'Попробуйте ещё раз.'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось загрузить отчёт"
            tone="negative"
          />
        </Card>
      ) : (
        <Card>
          {report.data.days.every((day) => day.orderedQuantity === 0) ? (
            <p className="mb-4 text-sm text-text-muted">
              За этот месяц заказов по SKU не было.
            </p>
          ) : null}
          <SkuForecastFactTable
            currency={report.data.currency}
            days={report.data.days}
          />
        </Card>
      )}
    </section>
  )
}
