import { Link } from 'react-router'
import type { components } from '../../../api/schema'

type Period = components['schemas']['DashboardPeriodResponse']

export function ProfitHint({
  companyId,
  period,
}: {
  companyId: string
  period: Period
}) {
  const details = period.profitDetails
  const correctedAt = details.costCorrectedAt

  return (
    <span className="flex flex-col gap-1">
      <span>
        После расходов Ozon и себестоимости. Без налогов и внешних расходов.
      </span>
      {!period.dataComplete ? (
        <span>Данные периода загружены не полностью — прибыль недоступна.</span>
      ) : null}
      {details.quantityWithoutCost > 0 ? (
        <span>
          Не задана себестоимость для {String(details.quantityWithoutCost)} шт.{' '}
          <Link
            className="text-accent-default underline"
            to={`/companies/${companyId}/costs`}
          >
            Задать себестоимость
          </Link>
        </span>
      ) : null}
      {correctedAt === null || correctedAt === undefined ? null : (
        <span>
          Себестоимость периода исправлялась{' '}
          {new Intl.DateTimeFormat('ru-RU', {
            timeZone: 'Europe/Moscow',
          }).format(new Date(correctedAt))}
          .
        </span>
      )}
    </span>
  )
}
