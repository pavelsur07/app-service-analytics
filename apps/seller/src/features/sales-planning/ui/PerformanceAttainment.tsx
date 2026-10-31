import type { components } from '../../../api/schema'
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
  metric = 'orders_excluding_pre_handover_cancellations',
}: {
  columns: readonly PerformanceColumn[]
  currency: string
  metric?: components['schemas']['SalesPlanPerformanceResponse']['metric']
}) {
  return (
    <>
      <PerformanceMetricRow label="Выполнение плана" unit="%" columns={columns}>
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
        label="Отклонение от плана"
        unit="шт."
        columns={columns}
      >
        {(value) => value.quantityDeviation ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMoneyRow
        label="Отклонение выручки от плановой стоимости"
        description={
          <p>
            Выручка за вычетом возвратов минус плановая стоимость по РРЦ.
            Разница включает изменения количества, цен и скидок.
          </p>
        }
        columns={columns}
        currency={currency}
        amount={(value) => value.revenueDeviation}
      />
      <PerformanceMetricRow
        label={
          metric === 'retained_sales'
            ? 'Средние продажи в день'
            : 'Средние заказы в день'
        }
        unit="шт./день"
        columns={columns}
      >
        {(value) => value.actualSpeed?.replace('.', ',') ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label={
          metric === 'retained_sales'
            ? 'Прогноз продаж по заказам'
            : 'Прогноз заказов без отмен до передачи'
        }
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
        label="Прогноз выполнения плана"
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
        label="Прогноз отклонения от плана"
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
        label={
          metric === 'retained_sales'
            ? 'Прогноз средних продаж в день'
            : 'Прогноз средних заказов в день'
        }
        unit="шт./день"
        columns={columns}
      >
        {(value) => value.expectedSpeed?.replace('.', ',') ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Статус прогноза выполнения плана"
        columns={columns}
      >
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
