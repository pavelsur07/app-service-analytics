import type { components } from '../../../api/schema'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'

export function PerformanceSales({
  value,
}: {
  value: components['schemas']['PerformanceValuesResponse']
}) {
  const cohort = value.cohort
  const revenue = cohort?.observedRevenue
  return (
    <dl className="flex flex-col gap-1 text-sm tabular-nums">
      <div>
        <dt>Наблюдаемые продажи</dt>
        <dd className="font-semibold">
          {cohort == null ? '—' : `${cohort.observedNetSalesQuantity} шт.`}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Выручка по ценам продаж</dt>
        <dd>
          {revenue == null
            ? '—'
            : formatMinorAmount(revenue.minor, revenue.currency)}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Скорость продаж</dt>
        <dd>
          {value.observedSalesSpeed == null
            ? '—'
            : `${value.observedSalesSpeed.replace('.', ',')} шт./день`}
        </dd>
      </div>
      <div>
        <dt className="text-text-muted">Прогноз продаж —</dt>
        <dd className="text-text-muted">
          Пока не оцениваем будущие возвраты. Прогноз выручки и скорости продаж
          также недоступен.
        </dd>
      </div>
    </dl>
  )
}
