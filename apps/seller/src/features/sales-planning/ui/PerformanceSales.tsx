import {
  PerformanceMetricRow,
  PerformanceMoneyRow,
  type PerformanceColumn,
} from './PerformanceMetricRow'

export function PerformanceSales({
  columns,
  currency,
}: {
  columns: readonly PerformanceColumn[]
  currency: string
}) {
  return (
    <>
      <PerformanceMetricRow
        label="Продажи за вычетом возвратов"
        unit="шт."
        columns={columns}
      >
        {({ cohort }) => cohort?.observedNetSalesQuantity ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMoneyRow
        label="Выручка за вычетом возвратов"
        columns={columns}
        currency={currency}
        amount={({ cohort }) => cohort?.observedRevenue}
      />
      <PerformanceMetricRow
        label="Средние продажи в день"
        unit="шт./день"
        columns={columns}
      >
        {(value) => value.observedSalesSpeed?.replace('.', ',') ?? '—'}
      </PerformanceMetricRow>
    </>
  )
}
