import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import {
  formatGoalDeviation,
  formatNetQuantity,
  goalForecastReason,
} from '../lib/netForecastPresentation'
import {
  PerformanceMetricRow,
  PerformanceMoneyRow,
  PerformanceValue,
  type PerformanceColumn,
} from './PerformanceMetricRow'

export function PerformanceAttainment({
  columns,
  currency,
}: {
  columns: readonly PerformanceColumn[]
  currency: string
}) {
  return (
    <>
      <PerformanceMetricRow
        label="Выполнение по факту цели"
        unit="%"
        columns={columns}
      >
        {(value) => (
          <PerformanceValue
            reason={
              value.planQuantity === 0
                ? 'Нулевая цель: процент не определён.'
                : null
            }
          >
            {value.attainmentBps == null
              ? '—'
              : formatBasisPoints(value.attainmentBps, { withUnit: false })}
          </PerformanceValue>
        )}
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Отклонение от цели"
        unit="шт."
        columns={columns}
      >
        {(value) => value.quantityDeviation ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMoneyRow
        label="Отклонение выручки от плановой РРЦ"
        columns={columns}
        currency={currency}
        amount={(value) => value.revenueDeviation}
      />
      <PerformanceMetricRow
        label="Темп метрики цели"
        unit="шт./день"
        columns={columns}
      >
        {(value) => value.actualSpeed?.replace('.', ',') ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Ожидаемое количество по метрике цели"
        unit="шт."
        columns={columns}
      >
        {(value) =>
          value.expectedGoalQuantity == null
            ? '—'
            : formatNetQuantity(value.expectedGoalQuantity, { withUnit: false })
        }
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Ожидаемое выполнение"
        unit="%"
        columns={columns}
      >
        {(value) =>
          value.expectedAttainmentBps == null
            ? '—'
            : formatBasisPoints(value.expectedAttainmentBps, {
                withUnit: false,
              })
        }
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Ожидаемое отклонение от цели"
        unit="шт."
        columns={columns}
      >
        {(value) =>
          value.expectedQuantityDeviation == null
            ? '—'
            : formatGoalDeviation(value.expectedQuantityDeviation, {
                withUnit: false,
              })
        }
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Ожидаемый темп метрики цели"
        unit="шт./день"
        columns={columns}
      >
        {(value) => value.expectedSpeed?.replace('.', ',') ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMetricRow label="Доступность прогноза цели" columns={columns}>
        {(value) =>
          value.forecastUnavailableReason == null ? (
            'Доступен'
          ) : (
            <PerformanceValue
              reason={goalForecastReason(value.forecastUnavailableReason)}
            >
              Нет оценки
            </PerformanceValue>
          )
        }
      </PerformanceMetricRow>
    </>
  )
}
