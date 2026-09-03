import {
  ChevronLeft,
  ChevronRight,
  CircleX,
  MousePointerClick,
} from 'lucide-react'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { formatMonthLabel, isCurrentMonth, shiftMonth } from '../model/month'
import { useMonthlyClicks } from '../model/useMonthlyClicks'

const NUMBER = new Intl.NumberFormat('ru-RU')

export function MonthlyClicksTable({
  linkId,
  month,
  onMonthChange,
}: {
  linkId: string | null
  month: string
  onMonthChange: (month: string) => void
}) {
  const clicks = useMonthlyClicks(linkId, month)

  return (
    <Card>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Переходы</h2>
          <p className="text-sm text-text-muted">
            Учтены переходы людей; известные боты исключены.
          </p>
        </div>
        <div className="flex items-center gap-2" aria-label="Выбор месяца">
          <Button
            aria-label="Предыдущий месяц"
            onClick={() => {
              onMonthChange(shiftMonth(month, -1))
            }}
            size="compact"
            type="button"
            variant="secondary"
          >
            <ChevronLeft aria-hidden="true" size={16} />
          </Button>
          <span className="min-w-36 text-center text-sm font-medium">
            {formatMonthLabel(month)}
          </span>
          <Button
            aria-label="Следующий месяц"
            disabled={isCurrentMonth(month)}
            onClick={() => {
              onMonthChange(shiftMonth(month, 1))
            }}
            size="compact"
            type="button"
            variant="secondary"
          >
            <ChevronRight aria-hidden="true" size={16} />
          </Button>
        </div>
      </div>

      {linkId === null && (
        <StatusPanel
          description="Выберите ссылку в таблице выше."
          icon={<MousePointerClick aria-hidden="true" size={20} />}
          title="Ссылка не выбрана"
        />
      )}

      {linkId !== null && clicks.status === 'pending' && (
        <div className="h-32 animate-pulse rounded bg-border-subtle" />
      )}

      {linkId !== null && clicks.status === 'error' && (
        <StatusPanel
          action={
            <Button
              onClick={() => {
                void clicks.refetch()
              }}
              size="compact"
              type="button"
              variant="secondary"
            >
              Повторить
            </Button>
          }
          description={
            clicks.error instanceof Error
              ? clicks.error.message
              : 'Попробуйте обновить страницу.'
          }
          icon={<CircleX aria-hidden="true" size={20} />}
          role="alert"
          title="Не удалось загрузить переходы"
          tone="negative"
        />
      )}

      {linkId !== null && clicks.status === 'success' && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-text-muted">
                <th className="py-2">Дата</th>
                <th className="py-2 text-right">Переходы</th>
              </tr>
            </thead>
            <tbody>
              {clicks.data.items.map((item) => (
                <tr className="border-t border-border-default" key={item.date}>
                  <td className="py-2">
                    <time dateTime={item.date}>{item.date}</time>
                  </td>
                  <td className="py-2 text-right tabular-nums">
                    {NUMBER.format(item.clicks)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}
