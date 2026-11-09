import { describe, expect, it } from 'vitest'
import type { CatalogCampaign } from '../model/types'
import { campaignSelectionIssue } from './catalog'

const cpc: CatalogCampaign = {
  id: '123',
  title: 'CPC',
  state: 'CAMPAIGN_STATE_RUNNING',
  advObjectType: 'SKU',
  paymentType: 'CPC',
}

describe('Выбор кампании для наблюдения', () => {
  it('не считает SKU доказательством оплаты за клик и не разрешает неизвестный статус', () => {
    expect(campaignSelectionIssue(cpc)).toBeNull()
    for (const campaign of [
      { ...cpc, paymentType: null },
      { ...cpc, paymentType: 'CPO' },
      { ...cpc, paymentType: 'CPM' },
      { ...cpc, advObjectType: 'BRAND_SHELF' },
      { ...cpc, state: 'CAMPAIGN_STATE_ARCHIVED' },
      { ...cpc, state: 'CAMPAIGN_STATE_FINISHED' },
      { ...cpc, state: 'NEW_PROVIDER_STATE' },
      { ...cpc, id: '99999999999999999999' },
    ])
      expect(campaignSelectionIssue(campaign)).not.toBeNull()
  })
})
