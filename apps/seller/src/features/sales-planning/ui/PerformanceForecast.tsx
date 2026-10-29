import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import {
  PerformanceMetricRow,
  PerformanceValue,
  type PerformanceColumn,
} from './PerformanceMetricRow'

export function PerformanceForecast({
  columns,
}: {
  columns: readonly PerformanceColumn[]
}) {
  return (
    <>
      <PerformanceMetricRow
        label="Прогноз выкупа"
        unit="шт."
        columns={columns}
        description={
          <p>
            Это прогноз выкупа, он не учитывает будущие клиентские возвраты и не
            является прогнозом итоговых продаж.
          </p>
        }
      >
        {({ cohort }) => {
          const reason =
            cohort == null
              ? 'Прогноз выкупа — нет полных данных.'
              : cohort.currentProjectedBuyoutQuantity != null
                ? null
                : cohort.rawOrderedQuantity === 0
                  ? 'Нет заказов для оценки.'
                  : cohort.unestimatedRateBps != null &&
                      cohort.unestimatedRateBps > 1000
                    ? 'Больше 10% заказов без оценки: прогноз выкупа недоступен.'
                    : 'Недостаточно данных для прогноза выкупа.'
          return (
            <PerformanceValue reason={reason}>
              {cohort?.currentProjectedBuyoutQuantity ?? '—'}
            </PerformanceValue>
          )
        }}
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Без оценки прогноза"
        unit="шт."
        columns={columns}
        description={
          <p>
            Здесь могут быть нераспознанные исходы и активные заказы без
            подходящей обучающей выборки. При доле без оценки до 10%
            предполагается такой же выкуп, как у оценённых заказов. При большей
            доле прогноз не публикуется.
          </p>
        }
      >
        {({ cohort }) => cohort?.unestimatedQuantity ?? '—'}
      </PerformanceMetricRow>
      <PerformanceMetricRow
        label="Доля без оценки прогноза"
        unit="%"
        columns={columns}
      >
        {({ cohort }) =>
          cohort?.unestimatedRateBps == null
            ? '—'
            : formatBasisPoints(cohort.unestimatedRateBps, { withUnit: false })
        }
      </PerformanceMetricRow>
    </>
  )
}
