import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import type { SkuForecastFactResponse } from '../model/useSkuForecastFact'

const QUANTITY = new Intl.NumberFormat('ru-RU')

export function SkuForecastFactTable({
  days,
  currency,
}: {
  days: SkuForecastFactResponse['days']
  currency: SkuForecastFactResponse['currency']
}) {
  return (
    <div className="overflow-x-auto">
      <table
        aria-label="Прогноз и факт по дням заказа"
        className="w-full min-w-200 border-collapse text-sm"
      >
        <thead>
          <tr className="border-b border-border-default text-xs text-text-muted">
            <th className="px-3 py-3 text-left font-medium" scope="col">
              Дата заказа
            </th>
            <th className="px-3 py-3 text-right font-medium" scope="col">
              Заказано, ₽ до СПП
            </th>
            <th className="px-3 py-3 text-right font-medium" scope="col">
              Заказано, шт.
            </th>
            <th className="px-3 py-3 text-right font-medium" scope="col">
              Плановый выкуп, %
            </th>
            <th className="px-3 py-3 text-right font-medium" scope="col">
              Прогноз выручки, ₽ до СПП
            </th>
            <th className="px-3 py-3 text-right font-medium" scope="col">
              Факт выручки, ₽ до СПП
            </th>
          </tr>
        </thead>
        <tbody>
          {days.map((day) => (
            <tr
              className="border-b border-border-subtle last:border-0"
              key={day.date}
            >
              <th
                className="whitespace-nowrap px-3 py-3 text-left font-medium"
                scope="row"
              >
                {day.date.slice(8, 10)}.{day.date.slice(5, 7)}.
                {day.date.slice(0, 4)}
              </th>
              <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                {formatMinorAmount(day.orderedAmountMinor, currency)}
              </td>
              <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                {QUANTITY.format(day.orderedQuantity)}
              </td>
              <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                {day.plannedBuyoutRateBps === null
                  ? '—'
                  : formatBasisPoints(day.plannedBuyoutRateBps)}
              </td>
              <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                {day.forecastRevenueMinor === null
                  ? '—'
                  : formatMinorAmount(day.forecastRevenueMinor, currency)}
              </td>
              <td className="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                {formatMinorAmount(day.actualRevenueMinor, currency)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
