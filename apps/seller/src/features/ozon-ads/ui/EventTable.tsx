import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { actionText, moscowTime, reasonText } from '../lib/presentation'
import type { AdsEvent } from '../model/types'

export function EventTable({ items }: { items: AdsEvent[] }) {
  return (
    <div className="overflow-x-auto rounded-xl border border-border-default bg-surface-raised">
      <table className="w-full text-left text-sm">
        <caption className="px-4 py-3 text-left text-text-secondary">
          Изменения настроек и принятые расчёты с момента включения журнала.
          Реальные команды Ozon не отправлялись.
        </caption>
        <thead className="border-b border-border-default text-xs text-text-muted">
          <tr>
            <th className="px-4 py-3">Время UTC+3</th>
            <th className="px-4 py-3">Кампания</th>
            <th className="px-4 py-3">Событие</th>
            <th className="px-4 py-3">Подробности</th>
          </tr>
        </thead>
        <tbody>
          {items.map((event) => (
            <tr
              key={event.id}
              className="border-b border-border-subtle align-top"
            >
              <td className="whitespace-nowrap px-4 py-3">
                {moscowTime(event.occurredAt)}
              </td>
              <td className="px-4 py-3">
                {event.campaignId}
                <p className="text-xs text-text-muted">
                  Версия {event.version}
                </p>
              </td>
              <td className="px-4 py-3">
                {actionText(event.action)}
                <p className="text-xs text-text-muted">
                  {event.actorId === null
                    ? 'Система наблюдения'
                    : 'Пользователь'}
                </p>
              </td>
              <td className="px-4 py-3">
                {reasonText(event.reason)}
                {event.dailyLimit !== null && (
                  <p>
                    Суточный лимит:{' '}
                    {formatMinorAmount(
                      event.dailyLimit.amountMinor,
                      event.dailyLimit.currency,
                    )}{' '}
                    · {event.details.start}–{event.details.stop} ·{' '}
                    {event.details.enabled
                      ? 'наблюдение включено'
                      : 'наблюдение выключено'}{' '}
                    · интервал {event.details.pollSeconds} с
                  </p>
                )}
                {event.kind === 'observation' && (
                  <p>
                    {event.details.day} ·{' '}
                    {event.spend === null
                      ? 'Расход неизвестен'
                      : formatMinorAmount(
                          event.spend.amountMinor,
                          event.spend.currency,
                        )}
                    {event.difference !== null
                      ? ` · расхождение ${formatMinorAmount(event.difference.amountMinor, event.difference.currency)}`
                      : ''}
                  </p>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
