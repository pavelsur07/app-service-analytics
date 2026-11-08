import { ApiError } from '../../../api/ApiError'
import type { Policy } from '../model/types'

export const TABS = [
  { id: 'campaigns', label: 'Кампании' },
  { id: 'strategies', label: 'Стратегии' },
  { id: 'history', label: 'История действий' },
  { id: 'settings', label: 'Настройки' },
] as const
export type Tab = (typeof TABS)[number]['id']
export function parseTab(value: string | null): Tab {
  return TABS.find((tab) => tab.id === value)?.id ?? 'campaigns'
}
// Decimal text → integer kopecks. Binary floating point never handles input money.
export function parseBudget(text: string): number | null {
  const match = /^(0|[1-9][0-9]{0,13})(?:[.,]([0-9]{1,2}))?$/.exec(text.trim())
  if (match === null || match[1] === undefined) return null
  const minor =
    BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'))
  return minor > 0n && minor <= BigInt(Number.MAX_SAFE_INTEGER)
    ? Number(minor)
    : null
}
export function budgetInput(minor: number): string {
  if (!Number.isSafeInteger(minor) || minor < 1) return ''
  const value = BigInt(minor)
  return `${value / 100n}.${String(value % 100n).padStart(2, '0')}`
}
export function validWindow(start: string, stop: string): boolean {
  const clock = /^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/
  return clock.test(start) && clock.test(stop) && start < stop
}
export function validCampaign(value: string): boolean {
  return /^[1-9][0-9]{0,18}$/.test(value)
}
export function validInterval(text: string): number | null {
  if (!/^[0-9]{3,5}$/.test(text)) return null
  const value = Number(text)
  return value >= 600 && value <= 86400 ? value : null
}
const reasons: Record<string, string> = {
  policy_disabled: 'Наблюдение выключено',
  permission_expired: 'Ожидается подтверждение доступа',
  primary_unavailable: 'Основные данные недоступны или неполны',
  sku_incomparable: 'SKU-сверка недоступна или несопоставима',
  invalid_previous_spend: 'Не удалось проверить предыдущий расход',
  outside_window: 'Вне заданного окна',
  budget_reached: 'Достигнут суточный лимит',
  reserve_unavailable: 'Защитный запас ещё не подтверждён',
  budget_margin: 'Расход приблизился к лимиту с учётом запаса',
  conditions_met: 'Условия расчёта выполнены',
  invalid_policy: 'Настройки не пригодны для расчёта',
  campaign_unavailable: 'Кампания недоступна',
  campaign_unsupported: 'Тип кампании не поддерживается',
  user_change: 'Настройки сохранены пользователем',
}
export function reasonText(reason: string): string {
  return reasons[reason] ?? 'Условия наблюдения требуют проверки'
}
export function actionText(action: string): string {
  return (
    (
      {
        saved: 'Сохранены настройки',
        would_run: 'Предполагаемый запуск',
        would_stop: 'Предполагаемая остановка',
        blocked: 'Расчёт запуска заблокирован',
      } as Record<string, string>
    )[action] ?? 'Результат неизвестен'
  )
}
export function observationStatus(policy: Policy, now: number): string {
  if (!policy.enabled) return 'Наблюдение выключено'
  if (policy.observation === null) return 'Ожидается первый расчёт'
  if (policy.deliveryStatus !== 'applied')
    return 'Новая версия ещё не применена'
  const at = Date.parse(policy.observation.evaluatedAt)
  const day = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Europe/Moscow',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date(now))
  if (
    !Number.isFinite(at) ||
    at > now + 60_000 ||
    now - at > (policy.pollSeconds + 180) * 1000 ||
    policy.observation.day !== day
  )
    return 'Давно нет подтверждения расчёта'
  return actionText(policy.observation.action)
}
export function adsError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'Сессия завершена. Войдите снова.'
    if (error.status === 403) return 'Нет доступа к данным этой компании.'
    if (error.status === 404)
      return 'Раздел недоступен или наблюдение выключено.'
    if (error.code === 'version_conflict')
      return 'Настройки уже изменены. Закройте форму и откройте актуальную версию.'
    if (error.code === 'account_capacity_exceeded')
      return 'Для подключения уже настроено 10 кампаний.'
    if (error.code === 'account_unavailable')
      return 'Проверьте подключение рекламного кабинета.'
    if (error.status === 422)
      return 'Проверьте поля формы и рекламное подключение.'
    if (error.status === 429) return 'Слишком много запросов. Повторите позже.'
  }
  return 'Не удалось подтвердить результат. Обновите список перед повторным сохранением.'
}
export function uncertainWrite(error: unknown): boolean {
  return !(error instanceof ApiError) || error.status >= 500
}
export function moscowTime(value: string): string {
  const date = new Date(value)
  return Number.isFinite(date.getTime())
    ? new Intl.DateTimeFormat('ru-RU', {
        timeZone: 'Europe/Moscow',
        dateStyle: 'short',
        timeStyle: 'medium',
      }).format(date)
    : 'Время неизвестно'
}
