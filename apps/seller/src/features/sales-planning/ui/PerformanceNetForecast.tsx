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
        label="Прогноз продаж за вычетом возвратов"
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
        label="Прогноз выручки за вычетом возвратов"
        columns={columns}
        currency={currency}
        amount={({ cohort }) => cohort?.expectedNetRevenue}
      />
      <PerformanceMetricRow label="Статус прогноза продаж" columns={columns}>
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
      label="статус прогноза продаж"
      status={status}
      tone={tone}
    >
      {messages.map((message) => (
        <p key={message}>{message}</p>
      ))}
    </PerformanceStatus>
  )
}
