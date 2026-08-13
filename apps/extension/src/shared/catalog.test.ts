import { HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import type { CompanySkuListResponse } from '../api/client'
import { http, server } from '../../tests/msw/server'
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
 * Отдаёт страницы по порядку и запоминает запрошенные курсоры.
 *
 * Хендлер типизирован сгенерированной схемой (openapi-msw): страница
 * неверной формы не скомпилируется, и тест не сможет успешно пройти
 * на ответе, которого контракт не допускает.
 */
function respondWithPages(pages: CompanySkuListResponse[]) {
  const cursors: (string | null)[] = []
  let call = 0

  server.use(
    http.get('/api/extension/companies/{companyId}/skus', ({ request }) => {
      cursors.push(new URL(request.url).searchParams.get('cursor'))
      const page = pages[call] ?? { items: [], nextCursor: null }
      call += 1

      return HttpResponse.json(page)
    }),
  )

  return cursors
}

const COMPANY = '019ff5ce-e740-7065-b0eb-e8f9acda89ef'
const OTHER_COMPANY = '019ff5ce-0000-7065-b0eb-e8f9acda89ef'
const NOW = new Date('2026-08-12T12:00:00Z')

describe('каталог артикулов', () => {
  it('собирает список из всех страниц', async () => {
    const cursors = respondWithPages([
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
    expect(cursors).toHaveLength(2)
  })

  it('передаёт курсор следующей страницы', async () => {
    const cursors = respondWithPages([
      { items: ['111'], nextCursor: '111' },
      { items: ['222'], nextCursor: null },
    ])

    await refreshCatalog(fakeStorage(), 'conwix_ext_token', COMPANY, NOW)

    expect(cursors).toEqual([null, '111'])
  })

  it('неполная выгрузка не сохраняется', async () => {
    // Сервер обещает продолжение бесконечно. Сохранить половину каталога
    // со свежей отметкой времени — худший исход: сутки оверлей молчал бы
    // на своих товарах, притом что в API они есть.
    const cursors = respondWithPages(
      Array.from({ length: 600 }, (_, index) => ({
        items: [String(index)],
        nextCursor: String(index),
      })),
    )
    const storage = fakeStorage()

    await expect(
      refreshCatalog(storage, 'conwix_ext_token', COMPANY, NOW),
    ).rejects.toThrow(/целиком/)

    expect(await readCatalog(storage, COMPANY)).toBeNull()
    expect(cursors.length).toBeLessThanOrEqual(500)
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
