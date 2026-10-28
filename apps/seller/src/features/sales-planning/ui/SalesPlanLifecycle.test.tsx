import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import type { components } from '../../../api/schema'
import { SalesPlanLifecycle } from './SalesPlanLifecycle'

const plan: components['schemas']['SalesPlanResponse'] = {
  id: 'plan',
  marketplaceAccountId: 'account',
  name: 'Сезон',
  dateFrom: '2026-07-01',
  dateTo: '2026-07-31',
  currency: 'RUB',
  status: 'draft',
  lineageId: 'line',
  versionNumber: 1,
  version: 7,
  createdAt: '2026-06-01',
  skuCount: 1,
  totalQuantity: 0,
  revenueMinor: 0,
  metric: 'retained_sales',
}
function render(patch: Partial<typeof plan> = {}) {
  const client = new QueryClient()
  const html = renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <SalesPlanLifecycle
        companyId="one"
        plan={{ ...plan, ...patch }}
        onRevision={() => undefined}
      />
    </QueryClientProvider>,
  )
  client.clear()
  return html
}

describe('lifecycle readiness', () => {
  it('allows zero target with an existing SKU but rejects empty plans or missing metric', () => {
    const button = (html: string) =>
      html.match(/<button[^>]*>Утвердить исходную цель<\/button>/)?.[0]
    expect(button(render())).not.toContain('disabled=""')
    expect(button(render({ skuCount: 0 }))).toContain('disabled=""')
    expect(button(render({ metric: null }))).toContain('disabled=""')
    expect(render({ metric: null })).not.toContain('checked=""')
  })
  it('allows an explicit change of metric before the first approval', () => {
    const html = render()
    expect(html).toContain('type="radio"')
    expect(html).toContain('checked=""')
    expect(html).toContain('Сохранить смысл цели')
  })

  it('keeps inherited metric readonly and explains legacy interpretation', () => {
    expect(render({ versionNumber: 2 })).not.toContain('type="radio"')
    const approved = render({ status: 'approved', metric: null })
    expect(approved).toContain('Создать новую версию')
    expect(approved).toContain('сохранив числа')
    expect(approved).not.toContain('type="radio"')
    expect(render({ status: 'cancelled' })).not.toContain(
      'Создать новую версию',
    )
  })
})
