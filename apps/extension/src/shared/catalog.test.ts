import { afterEach, describe, expect, it, vi } from 'vitest'

import { isOwnSku, isStale, readCatalog, refreshCatalog } from './catalog'
import type { Storage } from './connection'

function fakeStorage(initial: Record<string, unknown> = {}): Storage & {
  readonly data: Record<string, unknown>
} {
  const data: Record<string, unknown> = { ...initial }

  return {
    data,
    get: (keys) =>
      Promise.resolve(
        Object.fromEntries(
          keys.filter((key) => key in data).map((key) => [key, data[key]]),
        ),
      ),
    set: (items) => {
      Object.assign(data, items)

      return Promise.resolve()
    },
    clear: () => {
      for (const key of Object.keys(data)) {
        Reflect.deleteProperty(data, key)
      }

      return Promise.resolve()
    },
  }
}

/**
 * Отдаёт страницы по порядку, как их отдал бы эндпоинт артикулов,
 * и возвращает сам мок. Обращаться к глобальному fetch из теста нельзя —
 * запрещено линтером вне src/api/, и правило ослаблять незачем.
 */
function respondWithPages(
  pages: { items: string[]; nextCursor: string | null }[],
) {
  let call = 0
  // Аргумент объявлен и используется в утверждениях о вызовах: без него
  // у мока пустой список параметров, и mock.calls[..][0] не проходит tsc.
  const stub = vi.fn((url: string | URL) => {
    const page = pages[call] ?? { items: [], nextCursor: null }
    call += 1
    void url

    return Promise.resolve(
      new Response(JSON.stringify(page), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
  })
  vi.stubGlobal('fetch', stub)

  return stub
}

const COMPANY = '019ff5ce-e740-7065-b0eb-e8f9acda89ef'
const OTHER_COMPANY = '019ff5ce-0000-7065-b0eb-e8f9acda89ef'
const NOW = new Date('2026-08-12T12:00:00Z')

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('каталог артикулов', () => {
  it('собирает список из всех страниц', async () => {
    const stub = respondWithPages([
      { items: ['111', '222'], nextCursor: '222' },
      { items: ['333'], nextCursor: null },
    ])
    const storage = fakeStorage()

    const catalog = await refreshCatalog(
      storage,
      'conwix_ext_token',
      COMPANY,
      NOW,
    )

    expect(catalog.skus).toEqual(['111', '222', '333'])
    expect(stub.mock.calls).toHaveLength(2)
  })

  it('передаёт курсор следующей страницы', async () => {
    const stub = respondWithPages([
      { items: ['111'], nextCursor: '111' },
      { items: ['222'], nextCursor: null },
    ])

    await refreshCatalog(fakeStorage(), 'conwix_ext_token', COMPANY, NOW)

    const second = stub.mock.calls[1]?.[0]
    expect(String(second)).toContain('cursor=111')
  })

  it('каталог чужой компании не считается своим', async () => {
    // CLAUDE.md §7: ключ содержит companyId, и запись другой компании
    // не должна подойти под запрос текущей — иначе расширение показало бы
    // на карточках товары предыдущей компании.
    respondWithPages([{ items: ['111'], nextCursor: null }])
    const storage = fakeStorage()
    await refreshCatalog(storage, 'conwix_ext_token', OTHER_COMPANY, NOW)

    expect(await readCatalog(storage, COMPANY)).toBeNull()
    expect(await readCatalog(storage, OTHER_COMPANY)).not.toBeNull()
  })

  it('сверяет принадлежность артикула', async () => {
    respondWithPages([{ items: ['111', '222'], nextCursor: null }])
    const catalog = await refreshCatalog(
      fakeStorage(),
      'conwix_ext_token',
      COMPANY,
      NOW,
    )

    expect(isOwnSku(catalog, '222')).toBe(true)
    expect(isOwnSku(catalog, '999')).toBe(false)
  })

  it('устаревание считается по возрасту выгрузки', () => {
    const catalog = {
      companyId: COMPANY,
      skus: [],
      fetchedAt: NOW.toISOString(),
    }
    const day = 24 * 60 * 60 * 1000

    expect(isStale(catalog, new Date(NOW.getTime() + day - 1), day)).toBe(false)
    expect(isStale(catalog, new Date(NOW.getTime() + day), day)).toBe(true)
  })

  it('испорченная отметка времени означает «пора обновить»', () => {
    // Застрять навсегда на битой записи хуже, чем сходить в сеть лишний раз.
    const catalog = { companyId: COMPANY, skus: [], fetchedAt: 'не дата' }

    expect(isStale(catalog, NOW, 1000)).toBe(true)
  })

  it('запись неизвестной формы не считается каталогом', async () => {
    const storage = fakeStorage({
      [`catalog:${COMPANY}`]: { companyId: COMPANY, skus: [1, 2] },
    })

    expect(await readCatalog(storage, COMPANY)).toBeNull()
  })
})
