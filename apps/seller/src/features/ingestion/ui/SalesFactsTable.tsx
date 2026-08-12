import {
  Circle,
  CircleCheck,
  CircleX,
  Clock3,
  RefreshCw,
  SearchX,
} from 'lucide-react'
import type { ReactNode } from 'react'
import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { statusPresentation } from '../lib/statusPresentation'

type SalesFactListItemResponse =
  components['schemas']['SalesFactListItemResponse']

const STATUS_ICON = {
  positive: CircleCheck,
  negative: CircleX,
  warning: Clock3,
  neutral: Circle,
}

const HEADINGS = ['Дата', 'SKU', 'Статус', 'Кол-во', 'Сумма', 'Комиссия']
const SKELETON_WIDTHS = ['w-20', 'w-24', 'w-28', 'w-12', 'w-24', 'w-20']

function TableFrame({
  children,
  count,
}: {
  children: ReactNode
  count?: number
}) {
  return (
    <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
      <div className="flex items-center gap-3 border-b border-border-default px-4 py-3">
        <span className="font-semibold">Операции продаж</span>
        {count === undefined ? null : (
          <span className="text-xs text-text-muted tabular-nums">
            {count} строк на странице
          </span>
        )}
      </div>
      <div className="overflow-x-auto">{children}</div>
    </div>
  )
}

export function SalesFactsTableSkeleton() {
  return (
    <TableFrame>
      <table aria-busy="true" className="min-w-4xl w-full text-left">
        <caption className="sr-only">Загрузка операций продаж</caption>
        <thead>
          <tr className="bg-surface-sunken text-xs font-semibold text-text-secondary">
            {HEADINGS.map((heading, index) => (
              <th
                className={`border-b border-border-default px-3 py-2 ${index >= 3 ? 'text-right' : ''}`}
                key={heading}
              >
                {heading}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {[0, 1, 2, 3, 4].map((row) => (
            <tr key={row}>
              {SKELETON_WIDTHS.map((width, index) => (
                <td
                  className="border-b border-border-subtle px-3 py-2"
                  key={`${row}-${HEADINGS[index]}`}
                >
                  <span
                    className={`block h-3 animate-shimmer rounded bg-border-subtle ${index >= 3 ? 'ml-auto' : ''} ${width}`}
                  />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </TableFrame>
  )
}

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
    return (
      <Card>
        <StatusPanel
          action={
            <Button
              type="button"
              variant="secondary"
              size="compact"
              onClick={() => {
                window.location.reload()
              }}
            >
              <RefreshCw aria-hidden="true" size={16} />
              Обновить
            </Button>
          }
          description="За выбранный период операции продаж не поступили."
          icon={<SearchX aria-hidden="true" size={20} />}
          title="Нет данных за период"
        />
      </Card>
    )
  }

  return (
    <TableFrame count={items.length}>
      <table className="min-w-4xl w-full text-left">
        <thead>
          <tr className="bg-surface-sunken text-xs font-semibold text-text-secondary">
            <th className="border-b border-border-default px-3 py-2">Дата</th>
            <th className="border-b border-border-default px-3 py-2">SKU</th>
            <th className="border-b border-border-default px-3 py-2">Статус</th>
            <th className="border-b border-border-default px-3 py-2 text-right">
              Кол-во
            </th>
            <th className="border-b border-border-default px-3 py-2 text-right">
              Сумма
            </th>
            <th className="border-b border-border-default px-3 py-2 text-right">
              Комиссия
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const status = statusPresentation(item.status)
            const StatusIcon = STATUS_ICON[status.tone]

            return (
              <tr className="hover:bg-surface-hover" key={item.sourceRowId}>
                <td className="border-b border-border-subtle px-3 py-1.5 text-text-primary">
                  {item.businessDate}
                </td>
                <td className="border-b border-border-subtle px-3 py-1.5 text-text-secondary">
                  {item.marketplaceSku}
                </td>
                <td className="border-b border-border-subtle px-3 py-1.5">
                  <Badge size="compact" tone={status.tone}>
                    <StatusIcon aria-hidden="true" size={16} />
                    {status.label}
                  </Badge>
                </td>
                <td className="border-b border-border-subtle px-3 py-1.5 text-right text-text-primary">
                  {item.quantity}
                </td>
                <td className="border-b border-border-subtle px-3 py-1.5 text-right text-text-primary">
                  {formatMinorAmount(item.amountMinor, item.currency)}
                </td>
                <td className="border-b border-border-subtle px-3 py-1.5 text-right text-text-secondary">
                  {formatMinorAmount(item.commissionAmountMinor, item.currency)}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </TableFrame>
  )
}
