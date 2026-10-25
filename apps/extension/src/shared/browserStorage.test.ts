import { describe, expect, it, vi } from 'vitest'

import { browserStorage, readConnection } from './connection'

function nativeStorage(
  restrict: (options: { accessLevel: string }) => Promise<void>,
) {
  const data: Record<string, unknown> = {
    connection: {
      token: 'test-secret',
      companyId: 'ours',
      companyName: 'Company',
    },
  }
  const operations: string[] = []
  return {
    data,
    operations,
    area: {
      setAccessLevel: restrict,
      get: (keys: string[]) => {
        operations.push('get')
        return Promise.resolve(
          Object.fromEntries(
            keys.filter((key) => key in data).map((key) => [key, data[key]]),
          ),
        )
      },
      set: (items: Record<string, unknown>) => {
        operations.push('set')
        Object.assign(data, items)
        return Promise.resolve()
      },
      clear: () => {
        operations.push('clear')
        for (const key of Object.keys(data)) Reflect.deleteProperty(data, key)
        return Promise.resolve()
      },
    },
  }
}

describe('доступ к секрету только после ограничения native storage', () => {
  it('ранние чтение и запись ожидают TRUSTED_CONTEXTS', async () => {
    let release: () => void = () => undefined
    const barrier = new Promise<void>((resolve) => {
      release = resolve
    })
    const levels: string[] = []
    const native = nativeStorage(async ({ accessLevel }) => {
      levels.push(accessLevel)
      await barrier
    })
    vi.stubGlobal('chrome', { storage: { local: native.area } })
    const storage = browserStorage()
    const result = readConnection(storage)
    const written = storage.set({ marker: 'after-restriction' })
    await Promise.resolve()

    expect(native.operations).toEqual([])
    expect(levels).toEqual(['TRUSTED_CONTEXTS'])
    release()
    expect(await result).toEqual({
      token: 'test-secret',
      companyId: 'ours',
      companyName: 'Company',
    })
    await written
    expect(native.data['marker']).toBe('after-restriction')
    await browserStorage().get(['connection'])
    expect(levels).toEqual(['TRUSTED_CONTEXTS'])
  })

  it('отказ ограничения запрещает чтение, запись и очистку', async () => {
    const failure = new Error('Storage restriction failed')
    const native = nativeStorage(() => Promise.reject(failure))
    vi.stubGlobal('chrome', { storage: { local: native.area } })
    const storage = browserStorage()
    const results = await Promise.allSettled([
      storage.get(['connection']),
      storage.set({ connection: 'unsafe' }),
      storage.clear(),
    ])
    expect(results).toEqual([
      { status: 'rejected', reason: failure },
      { status: 'rejected', reason: failure },
      { status: 'rejected', reason: failure },
    ])
    expect(native.operations).toEqual([])
    expect(native.data['connection']).toEqual({
      token: 'test-secret',
      companyId: 'ours',
      companyName: 'Company',
    })
  })
})
