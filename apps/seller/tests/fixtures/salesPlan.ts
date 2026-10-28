import type { components } from '../../src/api/schema'

export const salesPlanFixture = {
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
} satisfies components['schemas']['SalesPlanResponse']
