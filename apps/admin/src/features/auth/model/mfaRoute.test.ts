import { describe, expect, it } from 'vitest'

import { mfaRoute } from './mfaRoute'

describe('граница входа администратора', () => {
  it('не открывает системный раздел до настройки и проверки фактора', () => {
    expect(mfaRoute('enrollment_required')).toBe('/mfa')
    expect(mfaRoute('verification_required')).toBe('/mfa')
    expect(mfaRoute('authenticated')).toBe('/accounts')
  })
})
