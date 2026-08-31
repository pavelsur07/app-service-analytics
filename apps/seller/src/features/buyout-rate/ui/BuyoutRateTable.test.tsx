import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'

import type { components } from '../../../api/schema'
import { BuyoutRateTable } from './BuyoutRateTable'

type BuyoutRateItem = components['schemas']['BuyoutRateItemResponse']

const ITEM: BuyoutRateItem = {
  marketplaceSku: 'SKU-1',
  offerId: 'OFFER-1',
  name: 'Товар',
  orderedQuantity: 2715,
  resolvedQuantity: 2037,
  deliveredQuantity: 886,
  actualBuyoutBaseQuantity: 1949,
  actualBuyoutRateBps: 4546,
  projectedBuyoutQuantity: 975,
  projectedBuyoutRateBps: 5000,
  t1RateBps: 77,
  t2RateBps: 3624,
  partialReturnRateBps: 291,
  maturityStatus: 'mature',
  resolutionRateBps: 7503,
}

describe('таблица выкупа', () => {
  it('показывает факт с его знаменателем отдельно от прогноза', () => {
    const html = renderToStaticMarkup(
      <BuyoutRateTable
        companyId="company-1"
        days={30}
        direction="desc"
        expandedSku={null}
        items={[ITEM]}
        onExpandedSkuChange={() => undefined}
        onSort={() => undefined}
        sort="ordered"
      />,
    )

    expect(html).toContain('Факт 45,46%')
    expect(html).toContain('886 из 1 949 шт')
    expect(html).toContain('Прогноз 50% · ожидается 975 шт')
    expect(html).not.toContain('975 из 2 715 шт')
  })

  it('обозначает активную сортировку доступным атрибутом', () => {
    const html = renderToStaticMarkup(
      <BuyoutRateTable
        companyId="company-1"
        days={30}
        direction="desc"
        expandedSku={null}
        items={[ITEM]}
        onExpandedSkuChange={() => undefined}
        onSort={() => undefined}
        sort="ordered"
      />,
    )

    expect(html).toContain('aria-sort="descending"')
    expect(html).toContain('Заказано, шт.')
    expect(html).toContain('Фактический выкуп, %')
  })
})
