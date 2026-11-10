import type { CatalogCampaign } from '../model/types'
import { validCampaign } from './presentation'

export function catalogState(state: string): string {
  return (
    (
      {
        CAMPAIGN_STATE_RUNNING: 'Работает',
        CAMPAIGN_STATE_INACTIVE: 'Неактивна',
        CAMPAIGN_STATE_STOPPED: 'Остановлена',
        CAMPAIGN_STATE_FINISHED: 'Завершена',
        CAMPAIGN_STATE_ARCHIVED: 'В архиве',
      } as Record<string, string>
    )[state] ?? 'Неизвестный статус'
  )
}

export function catalogPayment(payment: string | null | undefined): string {
  if (payment === 'CPC') return 'За клик (CPC)'
  if (payment === 'CPM') return 'За показы (CPM)'
  if (payment === 'CPO') return 'За заказ (CPO)'
  return 'Тип оплаты неизвестен'
}

export function campaignSelectionIssue(
  campaign: CatalogCampaign,
): string | null {
  if (!validCampaign(campaign.id))
    return 'ID этой кампании пока не поддерживается'
  if (campaign.advObjectType !== 'SKU' || campaign.paymentType !== 'CPC')
    return 'Наблюдение доступно для товарных кампаний с оплатой за клик (CPC)'
  if (campaign.state === 'CAMPAIGN_STATE_ARCHIVED') return 'Архивная кампания'
  if (campaign.state === 'CAMPAIGN_STATE_FINISHED') return 'Кампания завершена'
  if (
    ![
      'CAMPAIGN_STATE_RUNNING',
      'CAMPAIGN_STATE_INACTIVE',
      'CAMPAIGN_STATE_STOPPED',
    ].includes(campaign.state)
  )
    return 'Статус кампании требует проверки'
  return null
}

export function catalogTime(at: string): string {
  const time = new Date(at)
  return Number.isNaN(time.getTime())
    ? 'Время неизвестно'
    : new Intl.DateTimeFormat('ru-RU', {
        timeZone: 'Europe/Moscow',
        dateStyle: 'short',
        timeStyle: 'short',
      }).format(time)
}
