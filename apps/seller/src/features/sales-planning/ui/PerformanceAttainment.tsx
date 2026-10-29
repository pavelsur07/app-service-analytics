import type { components } from '../../../api/schema'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import {
  formatGoalDeviation,
  formatNetQuantity,
  goalForecastReason,
} from '../lib/netForecastPresentation'

export function PerformanceAttainment({
  value,
}: {
  value: components['schemas']['PerformanceValuesResponse']
}) {
  return (
    <dl className="flex flex-col gap-1 text-sm tabular-nums">
      <div>
        <dt>Выполнение по факту цели</dt>
        <dd className="font-semibold">
          {value.attainmentBps == null
            ? '—'
            : formatBasisPoints(value.attainmentBps)}
        </dd>
      </div>
      {value.planQuantity === 0 && (
        <div className="text-text-muted">
          <dt className="sr-only">Примечание к проценту</dt>
          <dd>Нулевая цель: процент не определён.</dd>
        </div>
      )}
      <div>
        <dt className="text-text-muted">Отклонение от цели</dt>
        <dd>
          {value.quantityDeviation == null
            ? '—'
            : `${value.quantityDeviation} шт.`}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Отклонение выручки от плановой РРЦ</dt>
        <dd>
          {value.revenueDeviation == null
            ? '—'
            : formatMinorAmount(
                value.revenueDeviation.minor,
                value.revenueDeviation.currency,
              )}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Темп метрики цели</dt>
        <dd>
          {value.actualSpeed == null
            ? '—'
            : `${value.actualSpeed.replace('.', ',')} шт./день`}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">
          Ожидаемое количество по метрике цели
        </dt>
        <dd>
          {value.expectedGoalQuantity == null
            ? '—'
            : formatNetQuantity(value.expectedGoalQuantity)}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Ожидаемое выполнение</dt>
        <dd>
          {value.expectedAttainmentBps == null
            ? '—'
            : formatBasisPoints(value.expectedAttainmentBps)}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Ожидаемое отклонение от цели</dt>
        <dd>
          {value.expectedQuantityDeviation == null
            ? '—'
            : formatGoalDeviation(value.expectedQuantityDeviation)}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Ожидаемый темп метрики цели</dt>
        <dd>
          {value.expectedSpeed == null
            ? '—'
            : `${value.expectedSpeed.replace('.', ',')} шт./день`}
        </dd>
      </div>
      {value.forecastUnavailableReason != null && (
        <div className="text-text-muted">
          <dt className="sr-only">Доступность прогноза цели</dt>
          <dd>{goalForecastReason(value.forecastUnavailableReason)}</dd>
        </div>
      )}
    </dl>
  )
}
