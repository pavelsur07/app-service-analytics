import { describe, expect, it, vi } from 'vitest'

import {
  preferenceFingerprint,
  readPreference,
  writePreference,
} from './localPreferences'

function memoryStorage(): Storage {
  const values = new Map<string, string>()

  return {
    get length() {
      return values.size
    },
    clear: () => {
      values.clear()
    },
    getItem: (key) => values.get(key) ?? null,
    key: (index) => [...values.keys()][index] ?? null,
    removeItem: (key) => {
      values.delete(key)
    },
    setItem: (key, value) => {
      values.set(key, value)
    },
  }
}

describe('настройки браузера', () => {
  it('пишет и читает ключ из разрешённого списка', () => {
    vi.stubGlobal('localStorage', memoryStorage())

    writePreference('cwx.dashboard.v1:u:c', '{"hidden":[]}')

    expect(readPreference('cwx.dashboard.v1:u:c')).toBe('{"hidden":[]}')
  })

  it('отказывает ключу вне списка', () => {
    vi.stubGlobal('localStorage', memoryStorage())

    expect(() => writePreference('token', 'x')).toThrow()
    expect(() => readPreference('company-data')).toThrow()
  })

  it('без хранилища — нет значения, а не исключение', () => {
    vi.stubGlobal('localStorage', undefined)

    writePreference('cwx.dashboard.v1:u:c', 'x')

    expect(readPreference('cwx.dashboard.v1:u:c')).toBeNull()
  })

  it('бросающее хранилище не роняет экран', () => {
    const broken = memoryStorage()
    broken.getItem = () => {
      throw new Error('SecurityError')
    }
    broken.setItem = () => {
      throw new Error('QuotaExceededError')
    }
    vi.stubGlobal('localStorage', broken)

    expect(() => {
      writePreference('cwx.dashboard.v1:u:c', 'x')
    }).not.toThrow()
    expect(readPreference('cwx.dashboard.v1:u:c')).toBeNull()
  })

  it('отпечаток устойчив и различает пользователей', () => {
    expect(preferenceFingerprint('a@example.com')).toBe(
      preferenceFingerprint('a@example.com'),
    )
    expect(preferenceFingerprint('a@example.com')).not.toBe(
      preferenceFingerprint('b@example.com'),
    )
    expect(preferenceFingerprint('a@example.com')).toMatch(/^[0-9a-f]{8}$/)
  })
})
