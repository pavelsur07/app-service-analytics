import { PerformanceStatus } from './PerformanceStatus'

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
    <PerformanceStatus
      label="полнота данных о заказах"
      status={
        coverage === 'complete'
          ? 'Полностью'
          : coverage === 'missing'
            ? 'Нет данных'
            : 'Частично'
      }
      tone={coverage === 'complete' ? 'neutral' : 'warning'}
    >
      <p>
        {coverage === 'complete'
          ? 'Заказы загружены полностью'
          : coverage === 'missing'
            ? 'Заказы не загружены'
            : 'Заказы загружены частично'}
      </p>
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
    </PerformanceStatus>
  )
}
