import { Badge } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { statusPresentation } from '../lib/statusPresentation'

type SalesFactListItemResponse =
  components['schemas']['SalesFactListItemResponse']

// Обычная <table>, не @tanstack/react-table: у экрана нет клиентской
// сортировки/фильтрации/переупорядочивания колонок — headless-модель
// того же react-table здесь нечем управлять. Понадобится с первым
// реальным взаимодействием со столбцами, не раньше.
export function SalesFactsTable({
  items,
}: {
  items: SalesFactListItemResponse[]
}) {
  if (items.length === 0) {
    return <p className="text-text-muted">Продаж за этот период нет.</p>
  }

  return (
    <table className="w-full border-collapse text-left">
      <thead>
        <tr className="border-b border-border-default text-text-muted">
          <th className="py-2 pr-4 font-medium">Дата</th>
          <th className="py-2 pr-4 font-medium">SKU</th>
          <th className="py-2 pr-4 font-medium">Статус</th>
          <th className="py-2 pr-4 text-right font-medium">Кол-во</th>
          <th className="py-2 pr-4 text-right font-medium">Сумма</th>
          <th className="py-2 text-right font-medium">Комиссия</th>
        </tr>
      </thead>
      <tbody>
        {items.map((item) => {
          const status = statusPresentation(item.status)

          return (
            <tr
              key={item.sourceRowId}
              className="border-b border-border-subtle"
            >
              <td className="py-2 pr-4 text-text-primary">
                {item.businessDate}
              </td>
              <td className="py-2 pr-4 text-text-secondary">
                {item.marketplaceSku}
              </td>
              <td className="py-2 pr-4">
                <Badge tone={status.tone}>{status.label}</Badge>
              </td>
              <td className="py-2 pr-4 text-right text-text-primary">
                {item.quantity}
              </td>
              <td className="py-2 pr-4 text-right text-text-primary">
                {formatMinorAmount(item.amountMinor, item.currency)}
              </td>
              <td className="py-2 text-right text-text-secondary">
                {formatMinorAmount(item.commissionAmountMinor, item.currency)}
              </td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}
