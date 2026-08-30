import { ChevronDown, ChevronRight } from 'lucide-react'

import { Badge } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import type { BuyoutDays } from '../lib/buyoutParams'
import {
  formatRateBps,
  maturityPresentation,
} from '../lib/buyoutStatusPresentation'
import { SkuBuyoutDaily } from './SkuBuyoutDaily'

type BuyoutRateItem = components['schemas']['BuyoutRateItemResponse']

const QUANTITY = new Intl.NumberFormat('ru-RU')
const CELL = 'border-b border-border-subtle px-4 py-3 align-top'

export function BuyoutRateTable({
  companyId,
  days,
  items,
  expandedSku,
  onExpandedSkuChange,
}: {
  companyId: string
  days: BuyoutDays
  items: BuyoutRateItem[]
  expandedSku: string | null
  onExpandedSkuChange: (marketplaceSku: string | null) => void
}) {
  return (
    <table className="w-full min-w-200 text-sm">
      <thead>
        <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
          <th className="border-b border-border-default px-4 py-2" scope="col">
            Артикул
          </th>
          <th
            className="border-b border-border-default px-4 py-2 text-right"
            scope="col"
          >
            Заказано
          </th>
          <th
            className="border-b border-border-default px-4 py-2 text-right"
            scope="col"
          >
            Выкуп
          </th>
          <th className="border-b border-border-default px-4 py-2" scope="col">
            Статус
          </th>
        </tr>
      </thead>
      <tbody>
        {items.map((item) => {
          const expanded = item.marketplaceSku === expandedSku
          const maturity = maturityPresentation(
            item.maturityStatus,
            item.resolutionRateBps,
          )

          return (
            <BuyoutRows
              companyId={companyId}
              days={days}
              expanded={expanded}
              item={item}
              key={item.marketplaceSku}
              maturity={maturity}
              onToggle={() => {
                onExpandedSkuChange(expanded ? null : item.marketplaceSku)
              }}
            />
          )
        })}
      </tbody>
    </table>
  )
}

function BuyoutRows({
  companyId,
  days,
  expanded,
  item,
  maturity,
  onToggle,
}: {
  companyId: string
  days: BuyoutDays
  expanded: boolean
  item: BuyoutRateItem
  maturity: ReturnType<typeof maturityPresentation>
  onToggle: () => void
}) {
  return (
    <>
      <tr className={expanded ? 'bg-surface-selected' : undefined}>
        <td className={CELL}>
          <div className="font-medium">
            {item.name ?? item.offerId ?? item.marketplaceSku}
          </div>
          <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-text-muted">
            <span>SKU {item.marketplaceSku}</span>
            {item.offerId === null || item.offerId === undefined ? null : (
              <span>Артикул продавца {item.offerId}</span>
            )}
          </div>
        </td>
        <td className={`${CELL} text-right font-medium`}>
          {QUANTITY.format(item.orderedQuantity)} шт
        </td>
        <td className={`${CELL} text-right`}>
          <div className="font-semibold">
            {formatRateBps(item.projectedBuyoutRateBps)}
          </div>
          <div className="mt-0.5 text-xs text-text-muted">
            {item.projectedBuyoutQuantity === null ||
            item.projectedBuyoutQuantity === undefined
              ? '—'
              : QUANTITY.format(item.projectedBuyoutQuantity)}{' '}
            из {QUANTITY.format(item.orderedQuantity)} шт
          </div>
        </td>
        <td className={CELL}>
          <div className="flex flex-wrap items-center gap-2">
            <Badge size="compact" tone={maturity.tone}>
              {maturity.label}
            </Badge>
            <button
              aria-expanded={expanded}
              aria-label={`${expanded ? 'Скрыть' : 'Показать'} динамику артикула ${item.marketplaceSku}`}
              className="inline-flex cursor-pointer items-center gap-1 rounded-md border border-border-default px-2 py-1 text-xs font-medium text-text-secondary hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default"
              onClick={onToggle}
              type="button"
            >
              {expanded ? (
                <ChevronDown aria-hidden="true" size={14} />
              ) : (
                <ChevronRight aria-hidden="true" size={14} />
              )}
              Динамика
            </button>
          </div>
          <div className="mt-2 text-xs text-text-muted">
            T1 {formatRateBps(item.t1RateBps)} · T2{' '}
            {formatRateBps(item.t2RateBps)} · P{' '}
            {formatRateBps(item.partialReturnRateBps)} от заказанных
          </div>
        </td>
      </tr>
      {expanded ? (
        <tr>
          <td className="border-b border-border-subtle p-4" colSpan={4}>
            <SkuBuyoutDaily
              companyId={companyId}
              days={days}
              marketplaceSku={item.marketplaceSku}
            />
          </td>
        </tr>
      ) : null}
    </>
  )
}
