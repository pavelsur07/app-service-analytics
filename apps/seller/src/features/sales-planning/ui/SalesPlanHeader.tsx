import type { components } from '../../../api/schema'
import { Badge, Card } from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { statusLabel } from '../lib/salesPlan'

type SalesPlan = components['schemas']['SalesPlanResponse']

export function SalesPlanHeader({ plan }: { plan: SalesPlan }) {
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
          <dt className="text-text-muted">Штуки текущей версии</dt>
          <dd className="text-lg font-semibold tabular-nums">
            {plan.totalQuantity}
          </dd>
        </div>
        <div>
          <dt className="text-text-muted">Выручка по РРЦ текущей версии</dt>
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
