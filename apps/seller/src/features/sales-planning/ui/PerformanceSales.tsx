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
        label="Наблюдаемые продажи"
        unit="шт."
        columns={columns}
      >
        {({ cohort }) => cohort?.observedNetSalesQuantity ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMoneyRow
        label="Выручка по ценам продаж"
        columns={columns}
        currency={currency}
        amount={({ cohort }) => cohort?.observedRevenue}
      />
      <PerformanceMetricRow
        label="Скорость продаж"
        unit="шт./день"
        columns={columns}
      >
        {(value) => value.observedSalesSpeed?.replace('.', ',') ?? '—'}
      </PerformanceMetricRow>
    </>
  )
}
