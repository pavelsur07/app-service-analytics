import type { Policy } from '../model/types'

export function samplePolicy(): Policy {
  return {
    id: '019a0000-0000-7000-8000-000000000001',
    accountId: '019a0000-0000-7000-8000-000000000002',
    campaignId: '49761882',
    version: 2,
    mode: 'observe',
    enabled: true,
    dailyLimit: { amountMinor: 10000, currency: 'RUB' },
    start: '09:00',
    stop: '21:00',
    timezone: 'Europe/Moscow',
    pollSeconds: 600,
    deliveryStatus: 'applied',
    observation: {
      appliedVersion: 2,
      day: '2026-11-08',
      spend: { amountMinor: 463, currency: 'RUB' },
      difference: { amountMinor: 360, currency: 'RUB' },
      action: 'blocked',
      reason: 'reserve_unavailable',
      latched: false,
      evaluatedAt: '2026-11-08T10:00:00Z',
    },
    sourceAgeKnown: false,
    budgetProtectionActive: false,
  }
}
