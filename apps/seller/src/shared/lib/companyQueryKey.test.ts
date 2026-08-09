import { describe, expect, it } from 'vitest'
import { companyQueryKey } from './companyQueryKey'

describe('companyQueryKey', () => {
  it('puts companyId as the second element', () => {
    expect(companyQueryKey('company-1', 'ingestion', 'sales-facts')).toEqual([
      'company',
      'company-1',
      'ingestion',
      'sales-facts',
    ])
  })

  it('appends params only when given', () => {
    expect(
      companyQueryKey('company-1', 'ingestion', 'sales-facts', {
        cursor: 'abc',
      }),
    ).toEqual([
      'company',
      'company-1',
      'ingestion',
      'sales-facts',
      { cursor: 'abc' },
    ])
  })

  it('produces different keys for different companies', () => {
    const keyA = companyQueryKey('company-a', 'ingestion', 'sales-facts')
    const keyB = companyQueryKey('company-b', 'ingestion', 'sales-facts')

    // Изоляция кэша при смене компании (CLAUDE.md §10, обязательное
    // покрытие) — переключение компании обязано менять ключ, иначе
    // TanStack Query отдаст данные предыдущей компании из кэша.
    expect(keyA).not.toEqual(keyB)
  })
})
