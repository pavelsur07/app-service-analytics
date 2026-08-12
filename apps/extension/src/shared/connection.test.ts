import { describe, expect, it } from 'vitest'

import {
  clearConnection,
  parseConnection,
  readConnection,
  writeConnection,
  type Connection,
  type Storage,
} from './connection'

// Фейк вместо библиотеки моков: chrome.storage — три метода, обёртка
// над ними ровно для этого и заведена.
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
    remove: (keys) => {
      // Reflect.deleteProperty, а не delete по вычисляемому ключу:
      // так требует линтер, поведение то же.
      for (const key of keys) {
        Reflect.deleteProperty(data, key)
      }

      return Promise.resolve()
    },
  }
}

const acme: Connection = {
  token: 'conwix_ext_first',
  companyId: '019ff5ce-e740-7065-b0eb-e8f9acda89ef',
  companyName: 'Acme LLC',
}
const other: Connection = {
  token: 'conwix_ext_second',
  companyId: '019ff5ce-0000-7065-b0eb-e8f9acda89ef',
  companyName: 'Other Company',
}

describe('подключение расширения', () => {
  it('переподключение к другой компании не оставляет следов прежней', async () => {
    // CLAUDE.md §7 в изводе расширения: chrome.storage переживает
    // и logout, и перезапуск браузера, поэтому данные предыдущей
    // компании обязаны исчезнуть в момент переподключения.
    const storage = fakeStorage()
    await writeConnection(storage, acme)
    await writeConnection(storage, other)

    const current = await readConnection(storage)

    expect(current).toEqual(other)
    expect(JSON.stringify(storage.data)).not.toContain(acme.companyId)
    expect(JSON.stringify(storage.data)).not.toContain(acme.token)
  })

  it('отключение стирает подключение', async () => {
    const storage = fakeStorage()
    await writeConnection(storage, acme)

    await clearConnection(storage)

    expect(await readConnection(storage)).toBeNull()
  })

  it('пустое хранилище — это отсутствие подключения, а не ошибка', async () => {
    expect(await readConnection(fakeStorage())).toBeNull()
  })

  it('запись от прежней версии расширения не считается подключением', async () => {
    // В storage могло остаться что угодно: старая форма записи не должна
    // притворяться живым подключением и уводить расширение в состояние
    // «подключено» с мусором вместо токена.
    expect(parseConnection(null)).toBeNull()
    expect(parseConnection('conwix_ext_token')).toBeNull()
    expect(
      parseConnection({ token: '', companyId: 'x', companyName: 'y' }),
    ).toBeNull()
    expect(
      parseConnection({ token: 'x', companyId: '', companyName: 'y' }),
    ).toBeNull()
    expect(parseConnection({ token: 'x', companyId: 'y' })).toBeNull()
    expect(parseConnection({ ...acme, extra: 1 })).toEqual(acme)
  })
})
