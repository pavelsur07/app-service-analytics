import type { components } from '../../../api/schema'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'

export function PerformanceForecast({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  if (cohort == null)
    return <p className="text-sm">Прогноз выкупа — нет полных данных.</p>
  return (
    <div className="flex flex-col gap-2 text-sm">
      <dl className="tabular-nums">
        <dt>Прогноз выкупа</dt>
        <dd>
          {cohort.currentProjectedBuyoutQuantity == null
            ? '—'
            : `${cohort.currentProjectedBuyoutQuantity} шт.`}
        </dd>
        <dt className="text-text-muted">Без оценки прогноза</dt>
        <dd>
          {cohort.unestimatedQuantity} шт. ·{' '}
          {cohort.unestimatedRateBps == null
            ? '—'
            : formatBasisPoints(cohort.unestimatedRateBps)}
        </dd>
      </dl>
      {cohort.currentProjectedBuyoutQuantity == null && (
        <p className="text-text-muted">
          {cohort.rawOrderedQuantity === 0
            ? 'Нет заказов для оценки.'
            : cohort.unestimatedRateBps != null &&
                cohort.unestimatedRateBps > 1000
              ? 'Больше 10% заказов без оценки: прогноз выкупа недоступен.'
              : 'Недостаточно данных для прогноза выкупа.'}
        </p>
      )}
      <details>
        <summary>Что значит «без оценки»</summary>
        <p>
          Здесь могут быть нераспознанные исходы и активные заказы без
          подходящей обучающей выборки. При доле без оценки до 10%
          предполагается такой же выкуп, как у оценённых заказов. При большей
          доле прогноз не публикуется.
        </p>
        <p>
          Это прогноз выкупа, он не учитывает будущие клиентские возвраты и не
          является прогнозом итоговых продаж.
        </p>
      </details>
    </div>
  )
}
