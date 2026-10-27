import { Badge } from '../../../../../../packages/ui/src'

export function PerformanceQuality({
  coverage,
  current,
  future = false,
}: {
  coverage: string
  current: boolean
  future?: boolean
}) {
  return (
    <div className="flex flex-col gap-1 text-sm">
      <div>
        <Badge tone={coverage === 'complete' ? 'positive' : 'warning'}>
          {coverage === 'complete'
            ? 'Заказы загружены полностью'
            : coverage === 'missing'
              ? 'Заказы не загружены'
              : 'Заказы загружены частично'}
        </Badge>
      </div>
      {current && (
        <p className="text-text-muted">
          Текущий день ещё не завершён. Его итог неполный.
        </p>
      )}
      {future && (
        <p className="text-text-muted">
          Будущие даты: состоявшихся продаж пока нет.
        </p>
      )}
    </div>
  )
}
