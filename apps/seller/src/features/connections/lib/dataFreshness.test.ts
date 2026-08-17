import { describe, expect, it } from 'vitest'
import type { components } from '../../../api/schema'
import { dataFreshness } from './dataFreshness'

type Connection = components['schemas']['ConnectionResponse']

const NOW = new Date('2026-08-17T12:00:00Z').getTime()

function connection(overrides: Partial<Connection>): Connection {
  return {
    id: '019ffe00-0000-7000-8000-000000000001',
    marketplace: 'ozon',
    externalShopId: '12345',
    state: 'active',
    createdAt: '2026-08-01T00:00:00+00:00',
    lastLoadedAt: {},
    version: 1,
    ...overrides,
  }
}

describe('свежесть данных в шапке', () => {
  it('без подключений и без загрузок не показывается', () => {
    expect(dataFreshness([], NOW)).toBeUndefined()
    expect(dataFreshness([connection({})], NOW)).toBeUndefined()
  })

  it('свежая загрузка — нейтральный тон', () => {
    const result = dataFreshness(
      [connection({ lastLoadedAt: { sales: '2026-08-17T10:00:00+00:00' } })],
      NOW,
    )

    expect(result?.tone).toBe('neutral')
    expect(result?.text).toContain('2 ч назад')
  })

  it('загрузка старше порога — предупреждение', () => {
    const result = dataFreshness(
      [connection({ lastLoadedAt: { sales: '2026-08-15T20:00:00+00:00' } })],
      NOW,
    )

    expect(result?.tone).toBe('warning')
    expect(result?.text).toContain('могли устареть')
  })

  it('сутки без загрузок — ещё не тревога: дедупликация raw даёт суточный интервал', () => {
    const result = dataFreshness(
      [connection({ lastLoadedAt: { sales: '2026-08-16T11:00:00+00:00' } })],
      NOW,
    )

    expect(result?.tone).toBe('neutral')
  })

  it('сломанное подключение важнее времени последней загрузки', () => {
    const result = dataFreshness(
      [
        connection({ lastLoadedAt: { sales: '2026-08-17T11:59:00+00:00' } }),
        connection({ id: 'x', state: 'broken' }),
      ],
      NOW,
    )

    expect(result?.tone).toBe('negative')
  })

  it('отключённое подключение не даёт ни свежести, ни поломки', () => {
    expect(
      dataFreshness(
        [
          connection({
            state: 'revoked',
            lastLoadedAt: { sales: '2026-08-01T00:00:00+00:00' },
          }),
        ],
        NOW,
      ),
    ).toBeUndefined()
  })

  it('берётся самая свежая загрузка из всех выгрузок и подключений', () => {
    const result = dataFreshness(
      [
        connection({
          lastLoadedAt: {
            sales: '2026-08-10T00:00:00+00:00',
            catalog: '2026-08-17T11:00:00+00:00',
          },
        }),
      ],
      NOW,
    )

    expect(result?.tone).toBe('neutral')
    expect(result?.text).toContain('1 ч назад')
  })
})
