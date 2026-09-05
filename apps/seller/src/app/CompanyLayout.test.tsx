import { describe, expect, it } from 'vitest'

import type { components } from '../api/schema'
import { resolveCompanyGate } from './CompanyLayout'

type ConnectionResponse = components['schemas']['ConnectionResponse']

const COMPANY_ID = 'company-a'

const ACTIVE_CONNECTION: ConnectionResponse = {
  id: 'connection-a',
  marketplace: 'ozon',
  externalShopId: '12345',
  state: 'active',
  createdAt: '2026-08-01T00:00:00+00:00',
  lastLoadedAt: {},
  version: 1,
}

const BROKEN_CONNECTION: ConnectionResponse = {
  ...ACTIVE_CONNECTION,
  id: 'connection-b',
  state: 'broken',
}

// Обязательное покрытие §9/§10 — гейт «компания без подключения не
// видит company-scoped экраны» проверяется как решение, вынесенное
// в чистую функцию (CLAUDE.md §9, «рендер не тестировать» — это
// не про решение, а про разметку). Рендер CompanyLayout в MemoryRouter
// в этом проекте не собрать: test.environment здесь 'node'
// (apps/seller/vite.config.ts), ни jsdom, ни @testing-library
// в зависимостях нет — тот же предел, что решило прежнее задание
// приёмом selectOnboardingCompany (OnboardingStartPage.tsx).
describe('resolveCompanyGate', () => {
  it('не решает, пока список подключений не прочитан', () => {
    expect(resolveCompanyGate(COMPANY_ID, { status: 'pending' })).toEqual({
      kind: 'pending',
    })
  })

  it('уводит компанию без единого подключения на онбординг с её companyId', () => {
    const decision = resolveCompanyGate(COMPANY_ID, {
      status: 'success',
      data: { connections: [] },
    })

    expect(decision).toEqual({
      kind: 'onboarding',
      to: `/onboarding?company=${COMPANY_ID}`,
    })
  })

  it('несёт в адресе редиректа именно запрошенную компанию, не первую попавшуюся', () => {
    const decision = resolveCompanyGate('company-b', {
      status: 'success',
      data: { connections: [] },
    })

    expect(decision).toEqual({
      kind: 'onboarding',
      to: '/onboarding?company=company-b',
    })
    expect(decision).not.toEqual({
      kind: 'onboarding',
      to: `/onboarding?company=${COMPANY_ID}`,
    })
  })

  it('кодирует companyId в адресе редиректа', () => {
    const rawCompanyId = 'company a/b'

    const decision = resolveCompanyGate(rawCompanyId, {
      status: 'success',
      data: { connections: [] },
    })

    expect(decision).toEqual({
      kind: 'onboarding',
      to: '/onboarding?company=company%20a%2Fb',
    })
  })

  it('показывает оболочку компании, у которой есть хотя бы одно подключение', () => {
    const decision = resolveCompanyGate(COMPANY_ID, {
      status: 'success',
      data: { connections: [ACTIVE_CONNECTION] },
    })

    expect(decision).toEqual({ kind: 'ready' })
  })

  it('уводит на онбординг компанию, у которой единственное подключение сломано', () => {
    // Гейт формулируется как отсутствие АКТИВНОГО подключения (ADR-021),
    // а не любого: сломанный кабинет не даёт содержательного экрана.
    const decision = resolveCompanyGate(COMPANY_ID, {
      status: 'success',
      data: { connections: [BROKEN_CONNECTION] },
    })

    expect(decision).toEqual({
      kind: 'onboarding',
      to: `/onboarding?company=${COMPANY_ID}`,
    })
  })

  it('показывает оболочку, когда рядом со сломанным есть активное подключение', () => {
    const decision = resolveCompanyGate(COMPANY_ID, {
      status: 'success',
      data: { connections: [BROKEN_CONNECTION, ACTIVE_CONNECTION] },
    })

    expect(decision).toEqual({ kind: 'ready' })
  })

  it('не блокирует экран собственной ошибкой чтения списка подключений', () => {
    // Гейт не имеет права положить весь экран компании из-за отказа
    // одного вспомогательного запроса — у списка подключений своя
    // обработка ошибки на своём экране (features/connections).
    const decision = resolveCompanyGate(COMPANY_ID, { status: 'error' })

    expect(decision).toEqual({ kind: 'ready' })
  })
})
