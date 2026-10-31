import type { components } from '../../../api/schema'
import { netForecastStatus } from '../lib/netForecastPresentation'

export function PerformanceNetMethodology({
  metadata,
}: {
  metadata:
    | components['schemas']['SalesPlanPerformanceResponse']['netForecast']
    | null
    | undefined
}) {
  const known =
    metadata?.methodology === 'observed_retention_h60_v1' &&
    metadata.trainingVersion === 'net_training_observed_v1' &&
    metadata.scope === 'placed_order_cohorts' &&
    metadata.coveragePolicy === 'daily_complete_receipts_v1' &&
    Number.isInteger(metadata.horizonDays) &&
    metadata.horizonDays > 0
  return (
    <div className="flex flex-col gap-2 text-sm text-text-secondary">
      {metadata != null && (
        <div>
          <p>{netForecastStatus(metadata.validationStatus, 'validation')}</p>
          <p>{netForecastStatus(metadata.assessmentStatus, 'assessment')}</p>
        </div>
      )}
      <details>
        <summary className="cursor-pointer focus-visible:outline-2 focus-visible:outline-accent-default">
          Как рассчитывается прогноз продаж
        </summary>
        {known ? (
          <p>
            Полный ожидаемый результат уже размещённых заказов с учётом будущих
            клиентских возвратов. Продажи за вычетом возвратов уже входят в
            него. Будущие заказы не учитываются. Горизонт возвратов —{' '}
            {metadata.horizonDays} дней от доставки.
          </p>
        ) : (
          <p>Описание прогноза продаж недоступно.</p>
        )}
      </details>
    </div>
  )
}
