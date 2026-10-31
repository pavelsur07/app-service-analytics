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
          [
            'Заказано без отмен до передачи в доставку',
            'effectiveOrderedQuantity',
          ],
          ['Заказано товаров', 'rawOrderedQuantity'],
          ['Отменено до передачи в доставку', 'preHandoverCancelledQuantity'],
          ['Товары с неизвестным результатом заказа', 'unknownOutcomeQuantity'],
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
