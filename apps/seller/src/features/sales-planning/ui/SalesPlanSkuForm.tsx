import { useDeferredValue, useState } from 'react'
import type { components } from '../../../api/schema'
import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { parseAmountToMinor } from '../../../shared/lib/parseAmountToMinor'
import { parseQuantity } from '../lib/salesPlan'
import { useCatalogSkus, useSetSalesPlanSkus } from '../model/useSalesPlans'

type SalesPlan = components['schemas']['SalesPlanResponse']

/**
 * Ввод SKU за весь период: штуки разложатся по дням поровну, РРЦ
 * скопируется в каждый день. SKU, уже стоящий в плане, раскладывается
 * заново — ручные правки дней при этом заменяются.
 */
export function SalesPlanSkuForm({
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
