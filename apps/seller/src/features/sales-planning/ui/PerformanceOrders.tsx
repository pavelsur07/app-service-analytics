import type { components } from '../../../api/schema'

export function PerformanceOrders({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  if (cohort == null)
    return (
      <p>
        — <span className="text-text-muted">Нет полных данных о заказах</span>
      </p>
    )
  return (
    <dl className="flex flex-col gap-1 text-sm tabular-nums">
      <div>
        <dt>Без отмен до передачи</dt>
        <dd className="font-semibold">{cohort.effectiveOrderedQuantity} шт.</dd>
      </div>
      <div>
        <dt className="text-text-muted">Всего заказали</dt>
        <dd>{cohort.rawOrderedQuantity}</dd>
      </div>
      <div>
        <dt className="text-text-muted">Отменили до передачи</dt>
        <dd>{cohort.preHandoverCancelledQuantity}</dd>
      </div>
      <div>
        <dt className="text-text-muted">Нераспознанный исход</dt>
        <dd>{cohort.unknownOutcomeQuantity}</dd>
      </div>
    </dl>
  )
}
