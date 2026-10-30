import type { components } from '../../../api/schema'
import {
  PerformanceMetricRow,
  PerformanceMoneyRow,
  type PerformanceColumn,
} from './PerformanceMetricRow'
import { PerformanceStatus } from './PerformanceStatus'
import { formatNetQuantity } from '../lib/netForecastPresentation'
import { buildNetForecastStatusPresentation } from '../lib/netForecastStatusPresentation'

export function PerformanceNetForecast({
  columns,
  currency,
}: {
  columns: readonly PerformanceColumn[]
  currency: string
}) {
  return (
    <>
      <PerformanceMetricRow
        label="Ожидаемые нетто-продажи"
        unit="шт."
        columns={columns}
      >
        {({ cohort }) =>
          cohort?.expectedNetSalesQuantity == null
            ? '—'
            : formatNetQuantity(cohort.expectedNetSalesQuantity, {
                withUnit: false,
              })
        }
      </PerformanceMetricRow>
      <PerformanceMoneyRow
        label="Ожидаемая нетто-выручка"
        columns={columns}
        currency={currency}
        amount={({ cohort }) => cohort?.expectedNetRevenue}
      />
      <PerformanceMetricRow
        label="Доступность нетто-прогноза"
        columns={columns}
      >
        {({ cohort }) => <PerformanceNetStatus cohort={cohort} />}
      </PerformanceMetricRow>
    </>
  )
}

function PerformanceNetStatus({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  const { status, tone, messages } = buildNetForecastStatusPresentation(cohort)
  return (
    <PerformanceStatus
      label="доступность нетто-прогноза"
      status={status}
      tone={tone}
    >
      {messages.map((message) => (
        <p key={message}>{message}</p>
      ))}
    </PerformanceStatus>
  )
}
