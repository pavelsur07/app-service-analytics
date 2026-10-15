import { describe, expect, it, vi } from 'vitest'

import {
  DEFAULT_PREFERENCES,
  loadPreferences,
  parsePreferences,
  preferencesKey,
  savePreferences,
} from './preferences'

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

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('настройки главной', () => {
  it('ключ свой у пользователя и компании, без адреса почты', () => {
    const key = preferencesKey('seller@example.com', ONE)

    expect(key).not.toContain('seller')
    expect(key).toContain(ONE)
    expect(key).not.toBe(preferencesKey('seller@example.com', TWO))
    expect(key).not.toBe(preferencesKey('other@example.com', ONE))
  })

  it('сохранённое читается обратно, раздельно по компаниям', () => {
    vi.stubGlobal('localStorage', memoryStorage())
    const one = preferencesKey('seller@example.com', ONE)
    const two = preferencesKey('seller@example.com', TWO)

    savePreferences(one, {
      hidden: ['returns', 'drrSales'],
      selection: {
        preset: 'custom',
        compare: 'year',
        custom: { from: '2026-09-01', to: '2026-09-10' },
      },
    })

    expect(loadPreferences(one)).toEqual({
      hidden: ['returns', 'drrSales'],
      selection: {
        preset: 'custom',
        compare: 'year',
        custom: { from: '2026-09-01', to: '2026-09-10' },
      },
    })
    expect(loadPreferences(two)).toEqual(DEFAULT_PREFERENCES)
  })

  it('испорченное значение — умолчания, незнакомое отбрасывается поштучно', () => {
    expect(parsePreferences('{not json')).toEqual(DEFAULT_PREFERENCES)
    expect(parsePreferences('[]')).toEqual(DEFAULT_PREFERENCES)
    expect(
      parsePreferences(
        '{"hidden":["orders","gone","orders",3],"preset":"today","compare":"prev"}',
      ),
    ).toEqual({
      hidden: ['orders'],
      selection: { preset: '7d', compare: 'prev', custom: null },
    })
    expect(
      parsePreferences(
        '{"preset":"custom","custom":{"from":"2026-02-30","to":"x"}}',
      ).selection.preset,
    ).toBe('7d')
  })
})
