import { useDeferredValue, useEffect, useState } from 'react'
import { ArrowLeft, CircleX, PackagePlus } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import {
  Badge,
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { parseAmountToMinor } from '../../../shared/lib/parseAmountToMinor'
import {
  bucketLabel,
  type Grouping,
  GROUPINGS,
  parseQuantity,
  statusLabel,
} from '../lib/salesPlan'
import {
  useCatalogSkus,
  useSalesPlan,
  useSetSalesPlanSkus,
} from '../model/useSalesPlans'

type SalesPlan = components['schemas']['SalesPlanResponse']
type SalesPlanSku = components['schemas']['SalesPlanSkuResponse']

/**
 * План продаж (ADR-040): шапка с итогами, SKU по корзинам выбранной
 * группировки и ввод SKU за период в черновик. Хранится день — неделя,
 * месяц и весь период считает сервер суммой дней.
 */
export function SalesPlanPage() {
  const { companyId, planId } = useParams<{
    companyId: string
    planId: string
  }>()

  // Ключ по компании и плану: курсор, группировка и форма ввода
  // принадлежат одному плану одной компании и при смене любого
  // из них начинаются заново.
  return <SalesPlanScreen key={`${companyId ?? ''}:${planId ?? ''}`} />
}

function SalesPlanScreen() {
  const navigate = useNavigate()
  const { companyId, planId } = useParams<{
    companyId: string
    planId: string
  }>()
  const [groupBy, setGroupBy] = useState<Grouping>('period')
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const cursor = cursorStack[cursorStack.length - 1] ?? null

  const plan = useSalesPlan(companyId ?? '', planId ?? '', groupBy, cursor)

  useEffect(() => {
    if (plan.error instanceof ApiError && plan.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [plan.error, navigate])

  if (companyId === undefined || planId === undefined) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <Link
        className="flex items-center gap-1 text-sm text-text-muted hover:text-text-primary"
        to={`/companies/${companyId}/sales-plans`}
      >
        <ArrowLeft aria-hidden="true" size={16} />
        Все планы
      </Link>

      {plan.status === 'pending' && (
        <Card>
          <div className="h-32 animate-pulse rounded bg-border-subtle" />
        </Card>
      )}

      {plan.status === 'error' && (
        <Card tone="negative">
          <StatusPanel
            description={
              plan.error instanceof Error
                ? plan.error.message
                : 'Неизвестная ошибка'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось загрузить план"
            tone="negative"
          />
        </Card>
      )}

      {plan.status === 'success' && (
        <>
          <PlanHeader plan={plan.data.plan} />

          {plan.data.plan.status === 'draft' && (
            <SkuInputForm companyId={companyId} plan={plan.data.plan} />
          )}

          <Card>
            <div
              className="mb-4 flex flex-wrap gap-2"
              role="group"
              aria-label="Группировка"
            >
              {GROUPINGS.map((option) => (
                <Button
                  key={option.value}
                  type="button"
                  size="compact"
                  variant={groupBy === option.value ? 'primary' : 'secondary'}
                  aria-pressed={groupBy === option.value}
                  onClick={() => {
                    setGroupBy(option.value)
                    setCursorStack([null])
                  }}
                >
                  {option.label}
                </Button>
              ))}
            </div>

            {plan.data.skus.length === 0 ? (
              <StatusPanel
                description="Добавьте SKU: штуки за период разложатся по дням поровну."
                icon={<PackagePlus aria-hidden="true" size={20} />}
                title="В плане пока нет SKU"
                tone="neutral"
              />
            ) : (
              <PlanGrid
                currency={plan.data.plan.currency}
                skus={plan.data.skus}
              />
            )}

            {((plan.data.nextCursor ?? null) !== null ||
              cursorStack.length > 1) && (
              <div className="mt-4 flex items-center justify-end gap-2">
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={cursorStack.length <= 1}
                  onClick={() => {
                    setCursorStack((stack) =>
                      stack.length > 1 ? stack.slice(0, -1) : stack,
                    )
                  }}
                >
                  Назад
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={(plan.data.nextCursor ?? null) === null}
                  onClick={() => {
                    const next = plan.data.nextCursor ?? null
                    if (next !== null) {
                      setCursorStack((stack) => [...stack, next])
                    }
                  }}
                >
                  Дальше
                </Button>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  )
}

function PlanHeader({ plan }: { plan: SalesPlan }) {
  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold">{plan.name}</h1>
          <p className="text-sm text-text-muted tabular-nums">
            {plan.dateFrom} — {plan.dateTo} · версия {plan.versionNumber}
          </p>
        </div>
        <Badge tone={plan.status === 'approved' ? 'positive' : 'neutral'}>
          {statusLabel(plan.status)}
        </Badge>
      </div>
      <dl className="mt-4 flex flex-wrap gap-8 text-sm">
        <div>
          <dt className="text-text-muted">SKU</dt>
          <dd className="text-lg font-semibold tabular-nums">
            {plan.skuCount}
          </dd>
        </div>
        <div>
          <dt className="text-text-muted">Штуки</dt>
          <dd className="text-lg font-semibold tabular-nums">
            {plan.totalQuantity}
          </dd>
        </div>
        <div>
          <dt className="text-text-muted">Выручка по РРЦ</dt>
          <dd className="text-lg font-semibold tabular-nums">
            {formatMinorAmount(plan.revenueMinor, plan.currency, {
              wholeUnits: true,
            })}
          </dd>
        </div>
      </dl>
    </Card>
  )
}

/**
 * Строки — SKU, колонки — корзины группировки. Дни у всех SKU плана
 * одни и те же (строки плотные, ADR-040 п. 3), поэтому заголовок
 * берётся у первого SKU. При группировке по дням таблица прокручивается.
 */
function PlanGrid({
  skus,
  currency,
}: {
  skus: SalesPlanSku[]
  currency: string
}) {
  const columns = skus[0]?.buckets ?? []

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border-default text-xs text-text-muted">
            <th className="px-3 py-2 text-left font-medium" scope="col">
              SKU
            </th>
            <th className="px-3 py-2 text-right font-medium" scope="col">
              Штуки
            </th>
            <th className="px-3 py-2 text-right font-medium" scope="col">
              Выручка
            </th>
            {columns.map((bucket) => (
              <th
                key={bucket.dateFrom}
                className="px-3 py-2 text-right font-medium whitespace-nowrap"
                scope="col"
              >
                {bucketLabel(bucket.dateFrom, bucket.dateTo)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {skus.map((sku) => (
            <tr
              key={sku.marketplaceSku}
              className="border-b border-border-subtle"
            >
              <th className="px-3 py-2 text-left font-medium" scope="row">
                {sku.marketplaceSku}
              </th>
              <td className="px-3 py-2 text-right tabular-nums">
                {sku.totalQuantity}
              </td>
              <td className="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                {formatMinorAmount(sku.revenueMinor, currency, {
                  wholeUnits: true,
                })}
              </td>
              {sku.buckets.map((bucket) => (
                <td
                  key={bucket.dateFrom}
                  className="px-3 py-2 text-right tabular-nums"
                >
                  {bucket.quantity}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Ввод SKU за весь период: штуки разложатся по дням поровну, РРЦ
 * скопируется в каждый день. SKU, уже стоящий в плане, раскладывается
 * заново — ручные правки дней при этом заменяются.
 */
function SkuInputForm({
  companyId,
  plan,
}: {
  companyId: string
  plan: SalesPlan
}) {
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search.trim())
  const [sku, setSku] = useState<string | null>(null)
  const [quantity, setQuantity] = useState('')
  const [price, setPrice] = useState('')
  const catalog = useCatalogSkus(
    companyId,
    plan.marketplaceAccountId,
    deferredSearch,
  )
  const setSkus = useSetSalesPlanSkus(companyId, plan.id)

  const parsedQuantity = parseQuantity(quantity)
  const parsedPrice = parseAmountToMinor(price)
  const ready =
    sku !== null &&
    parsedQuantity !== null &&
    parsedPrice !== null &&
    parsedPrice > 0

  // 409 бывает двух видов: версия устарела (план перечитан — повторить
  // ввод) и план уже не черновик. Различаются кодом сервера, а не
  // статусом; во втором случае показывается текст сервера (patterns.md).
  const conflict =
    setSkus.error instanceof ApiError &&
    setSkus.error.code === 'version_conflict'

  return (
    <Card>
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (!ready) {
            return
          }

          setSkus.mutate(
            {
              // Версия из последнего ответа (ADR-008): без неё ввод был бы
              // безусловным и затирал чужую правку.
              version: plan.version,
              items: [
                {
                  marketplaceSku: sku,
                  totalQuantity: parsedQuantity,
                  priceMinor: parsedPrice,
                },
              ],
            },
            {
              onSuccess: () => {
                setSku(null)
                setQuantity('')
                setPrice('')
              },
            },
          )
        }}
      >
        <h2 className="font-semibold">Добавить SKU</h2>

        <Input
          label="Товар из каталога подключения"
          placeholder="Артикул, артикул продавца или название"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            setSku(null)
          }}
        />

        {sku === null && catalog.status === 'success' && (
          <ul className="flex max-h-48 flex-col overflow-y-auto rounded-md border border-border-default">
            {catalog.data.items.length === 0 && (
              <li className="px-3 py-2 text-sm text-text-muted">
                Ничего не нашлось
              </li>
            )}
            {catalog.data.items.map((item) => (
              <li key={item.marketplaceSku}>
                <button
                  className="w-full px-3 py-2 text-left text-sm hover:bg-surface-hover"
                  type="button"
                  onClick={() => {
                    setSku(item.marketplaceSku)
                    setSearch(item.name ?? item.marketplaceSku)
                  }}
                >
                  <span className="font-medium tabular-nums">
                    {item.marketplaceSku}
                  </span>
                  {item.offerId !== null && item.offerId !== undefined && (
                    <span className="text-text-muted"> · {item.offerId}</span>
                  )}
                  {item.name !== null && item.name !== undefined && (
                    <span className="text-text-muted"> · {item.name}</span>
                  )}
                </button>
              </li>
            ))}
          </ul>
        )}

        {sku !== null && (
          <p className="text-sm">
            Выбран SKU <span className="font-medium tabular-nums">{sku}</span>
          </p>
        )}

        <div className="flex flex-wrap gap-3">
          <Input
            error={
              quantity !== '' && parsedQuantity === null
                ? 'Целое число до 1 000 000'
                : undefined
            }
            inputMode="numeric"
            label="Штук за период"
            value={quantity}
            onChange={(event) => {
              setQuantity(event.target.value)
            }}
          />
          <Input
            error={
              price !== '' && (parsedPrice === null || parsedPrice === 0)
                ? 'Сумма больше нуля'
                : undefined
            }
            inputMode="decimal"
            label="РРЦ за штуку, ₽"
            placeholder="1299,90"
            value={price}
            onChange={(event) => {
              setPrice(event.target.value)
            }}
          />
        </div>

        {setSkus.error !== null && (
          <p className="text-sm text-negative-text" role="alert">
            {conflict
              ? 'План изменил кто-то ещё — он перечитан, повторите ввод.'
              : setSkus.error instanceof ApiError
                ? setSkus.error.message
                : 'Не удалось сохранить SKU.'}
          </p>
        )}

        <div>
          <Button
            type="submit"
            size="compact"
            disabled={!ready || setSkus.isPending}
          >
            Сохранить
          </Button>
        </div>
      </form>
    </Card>
  )
}
