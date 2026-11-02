import { Badge, Card } from '../../../../../../packages/ui/src'
import { dateTime, monthStatus, researchFailure } from '../lib/research'
import type { ProjectProgress } from '../model/types'

export function ProjectMonths({
  state,
  checkedAt,
}: {
  state: ProjectProgress
  checkedAt: number
}) {
  return (
    <section className="flex flex-col gap-3" aria-label="Прогресс по месяцам">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">Месяцы проекта</h2>
        <p className="text-xs text-text-muted">
          Проверено: {dateTime(new Date(checkedAt).toISOString())} · Москва
        </p>
      </div>
      <p className="text-sm text-text-secondary">
        Готово {state.months.filter((m) => m.status === 'ready').length} из{' '}
        {state.months.length} мес.
      </p>
      <ol className="flex flex-col gap-3">
        {state.months.map((month) => (
          <li key={month.period.month}>
            <Card>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="font-semibold">{month.period.month}</h3>
                <Badge
                  tone={
                    month.status === 'ready'
                      ? 'positive'
                      : month.status === 'error'
                        ? 'negative'
                        : 'neutral'
                  }
                >
                  {monthStatus(month.status)}
                </Badge>
              </div>
              <p className="mt-2 text-xs text-text-secondary">
                {month.period.dateFrom} — {month.period.dateTo} ·{' '}
                {month.period.days} дн.
                {month.period.partial ? ' · Неполный месяц' : ''}
              </p>
              {month.status === 'ready' && (
                <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                  <div>
                    <dt className="text-xs text-text-muted">Товаров</dt>
                    <dd>
                      {typeof month.rowCount !== 'number'
                        ? '—'
                        : new Intl.NumberFormat('ru-RU').format(month.rowCount)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs text-text-muted">Источник</dt>
                    <dd>
                      {month.origin === 'cache'
                        ? 'Кэш'
                        : month.origin === 'api'
                          ? 'API MPStats'
                          : 'Не указан'}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs text-text-muted">Данные получены</dt>
                    <dd>{dateTime(month.collectedAt ?? null)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-text-muted">Использованы</dt>
                    <dd>{dateTime(month.usedAt ?? null)}</dd>
                  </div>
                </dl>
              )}
              {month.status === 'error' && (
                <p className="mt-3 text-sm text-negative-text">
                  {researchFailure(month.errorCode)}
                </p>
              )}
              {month.status === 'ready' && month.rowCount === 0 && (
                <p className="mt-3 text-sm text-warning-text">
                  В этом месяце нет товаров. Возможно, категория пуста или
                  переименована.
                </p>
              )}
            </Card>
          </li>
        ))}
      </ol>
    </section>
  )
}
