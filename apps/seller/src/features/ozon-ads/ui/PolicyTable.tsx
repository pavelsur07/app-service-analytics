import { Button } from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { moscowTime, observationStatus, reasonText } from '../lib/presentation'
import type { Policy } from '../model/types'

export function PolicyTable({
  items,
  accounts,
  blocked,
  now,
  onEdit,
}: {
  items: Policy[]
  accounts: { id: string; label: string }[]
  blocked: boolean
  now: number
  onEdit: (policy: Policy) => void
}) {
  return (
    <div className="overflow-x-auto rounded-xl border border-border-default bg-surface-raised">
      <table className="w-full text-left text-sm">
        <caption className="px-4 py-3 text-left text-text-secondary">
          Кампании с настроенным наблюдением. Состояние показов Ozon и недельный
          бюджет площадки не загружаются.
        </caption>
        <thead className="border-b border-border-default text-xs text-text-muted">
          <tr>
            <th className="px-4 py-3">Кампания</th>
            <th className="px-4 py-3">Расход за день</th>
            <th className="px-4 py-3">Суточный лимит</th>
            <th className="px-4 py-3">Окно UTC+3</th>
            <th className="px-4 py-3">Наблюдение</th>
            <th className="px-4 py-3">Настройки</th>
          </tr>
        </thead>
        <tbody>
          {items.map((policy) => (
            <tr
              key={policy.id}
              className="border-b border-border-subtle align-top"
            >
              <td className="px-4 py-3 font-medium">{policy.campaignId}</td>
              <td className="px-4 py-3 tabular-nums">
                {policy.observation?.spend == null
                  ? 'Неизвестен'
                  : formatMinorAmount(
                      policy.observation.spend.amountMinor,
                      policy.observation.spend.currency,
                    )}
                <p className="text-xs text-text-muted">
                  {policy.observation?.day ?? 'День не подтверждён'}
                </p>
                {policy.observation?.difference != null && (
                  <p className="text-xs text-warning-text">
                    Расхождение:{' '}
                    {formatMinorAmount(
                      policy.observation.difference.amountMinor,
                      policy.observation.difference.currency,
                    )}
                  </p>
                )}
              </td>
              <td className="px-4 py-3 tabular-nums">
                {formatMinorAmount(
                  policy.dailyLimit.amountMinor,
                  policy.dailyLimit.currency,
                )}
              </td>
              <td className="whitespace-nowrap px-4 py-3">
                {policy.start}–{policy.stop}
              </td>
              <td className="px-4 py-3">
                <p>{observationStatus(policy, now)}</p>
                {policy.observation !== null && (
                  <>
                    <p className="text-xs text-text-secondary">
                      {reasonText(policy.observation.reason)}
                    </p>
                    <p className="text-xs text-text-muted">
                      Расчёт: {moscowTime(policy.observation.evaluatedAt)}
                    </p>
                  </>
                )}
                <p className="text-xs text-text-muted">
                  Свежесть источника неизвестна. Защита бюджета не активна.
                </p>
              </td>
              <td className="px-4 py-3">
                <p className="mb-2 text-xs text-text-muted">
                  Версия {policy.version} ·{' '}
                  {policy.deliveryStatus === 'applied'
                    ? 'применена для расчёта'
                    : 'ожидает применения'}
                </p>
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={blocked}
                  onClick={() => onEdit(policy)}
                >
                  Настроить {policy.campaignId}
                  <p className="text-xs font-normal text-text-muted">
                    {accounts.find((account) => account.id === policy.accountId)
                      ?.label ?? 'Подключение недоступно'}
                  </p>
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
