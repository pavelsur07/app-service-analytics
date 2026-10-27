import { Badge } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'

export function PerformanceBuyout({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  if (cohort == null)
    return <p className="text-sm">Выкуп — нет полных данных.</p>
  const percent = (value: number | null | undefined) =>
    value == null ? '—' : formatBasisPoints(value)
  return (
    <div className="flex flex-col gap-2 text-sm">
      <div>
        <Badge
          tone={cohort.maturityStatus === 'mature' ? 'positive' : 'warning'}
        >
          {cohort.maturityStatus === 'mature'
            ? 'Созревший выкуп'
            : 'Предварительный исход'}
        </Badge>
      </div>
      <dl className="tabular-nums">
        <dt>Выкуп по известным закрытым исходам</dt>
        <dd>{percent(cohort.knownBuyoutRateBps)}</dd>
        <dt>Факт зрелого выкупа</dt>
        <dd>{percent(cohort.actualBuyoutRateBps)}</dd>
        <dt>Прогноз процента выкупа</dt>
        <dd>{percent(cohort.currentProjectedBuyoutRateBps)}</dd>
      </dl>
      <details>
        <summary>Как понимать выкуп</summary>
        <p>
          Результаты относятся к дате заказа. Поздние исходы уточняют её.
          Процент считается среди выкупленных и невыкупленных после передачи в
          доставку; уже оформленные клиентские возвраты в эту базу не входят.
          Это не доля продаж от всех заказов.
        </p>
        <p>Зрелость выкупа не означает завершение возвратов.</p>
      </details>
    </div>
  )
}
