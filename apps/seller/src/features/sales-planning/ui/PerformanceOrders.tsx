import {
  PerformanceMetricRow,
  PerformanceValue,
  type PerformanceColumn,
} from './PerformanceMetricRow'

export function PerformanceOrders({
  columns,
}: {
  columns: readonly PerformanceColumn[]
}) {
  return (
    <>
      {(
        [
          ['Без отмен до передачи', 'effectiveOrderedQuantity'],
          ['Всего заказали', 'rawOrderedQuantity'],
          ['Отменили до передачи', 'preHandoverCancelledQuantity'],
          ['Нераспознанный исход', 'unknownOutcomeQuantity'],
        ] as const
      ).map(([label, field]) => (
        <PerformanceMetricRow
          key={field}
          label={label}
          unit="шт."
          columns={columns}
        >
          {({ cohort }) => (
            <PerformanceValue
              reason={cohort == null ? 'Нет полных данных о заказах' : null}
            >
              {cohort?.[field] ?? '—'}
            </PerformanceValue>
          )}
        </PerformanceMetricRow>
      ))}
    </>
  )
}
