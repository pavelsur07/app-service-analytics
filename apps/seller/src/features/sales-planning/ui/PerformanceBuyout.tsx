import { PerformanceStatus } from './PerformanceStatus'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import {
  PerformanceMetricRow,
  type PerformanceColumn,
} from './PerformanceMetricRow'

export function PerformanceBuyout({
  columns,
}: {
  columns: readonly PerformanceColumn[]
}) {
  return (
    <>
      <PerformanceMetricRow label="Статус расчёта выкупа" columns={columns}>
        {({ cohort }) =>
          cohort == null ? (
            '—'
          ) : (
            <PerformanceStatus
              label="статус расчёта выкупа"
              status={
                cohort.maturityStatus === 'mature'
                  ? 'Рассчитан'
                  : 'Предварительный'
              }
              tone={cohort.maturityStatus === 'mature' ? 'neutral' : 'warning'}
            >
              <p>
                {cohort.maturityStatus === 'mature'
                  ? 'Результаты выкупа достаточно определены для расчёта фактического процента.'
                  : 'Предварительный исход: результаты заказов ещё уточняются.'}
              </p>
              <p>Статус расчёта выкупа не означает завершение возвратов.</p>
            </PerformanceStatus>
          )
        }
      </PerformanceMetricRow>
      {(
        [
          ['Текущий процент выкупа', 'knownBuyoutRateBps'],
          ['Процент выкупа по завершённым когортам', 'actualBuyoutRateBps'],
          ['Прогноз процента выкупа', 'currentProjectedBuyoutRateBps'],
        ] as const
      ).map(([label, field]) => (
        <PerformanceMetricRow
          key={field}
          label={label}
          unit="%"
          columns={columns}
          description={
            field === 'knownBuyoutRateBps' ? (
              <>
                <p>
                  Результаты относятся к дате заказа. Поздние исходы уточняют
                  её. Процент считается среди выкупленных и невыкупленных после
                  передачи в доставку; уже оформленные клиентские возвраты в эту
                  базу не входят. Это не доля продаж от всех заказов.
                </p>
                <p>Статус расчёта выкупа не означает завершение возвратов.</p>
              </>
            ) : undefined
          }
        >
          {({ cohort }) =>
            cohort?.[field] == null
              ? '—'
              : formatBasisPoints(cohort[field], { withUnit: false })
          }
        </PerformanceMetricRow>
      ))}
    </>
  )
}
