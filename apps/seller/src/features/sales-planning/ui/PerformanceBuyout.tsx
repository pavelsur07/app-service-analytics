import { Badge } from '../../../../../../packages/ui/src'
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
      <PerformanceMetricRow label="Зрелость выкупа" columns={columns}>
        {({ cohort }) =>
          cohort == null ? (
            '—'
          ) : (
            <Badge
              tone={cohort.maturityStatus === 'mature' ? 'positive' : 'warning'}
            >
              {cohort.maturityStatus === 'mature'
                ? 'Созревший выкуп'
                : 'Предварительный исход'}
            </Badge>
          )
        }
      </PerformanceMetricRow>
      {(
        [
          ['Выкуп по известным закрытым исходам', 'knownBuyoutRateBps'],
          ['Факт зрелого выкупа', 'actualBuyoutRateBps'],
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
                <p>Зрелость выкупа не означает завершение возвратов.</p>
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
