import { HttpResponse } from 'msw'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { http, server } from '../../tests/msw/server'
import type { Storage } from '../shared/connection'

import {
  allowFirstCapture,
  handlePriceSyncAlarm,
  isCaptureVisit,
  isPriceSyncAlarm,
  submitObservation,
} from './priceSync'

/**
 * Машина захвата целиком построена вокруг того, что service worker
 * засыпает в произвольный момент, а Chrome копит пропущенные будильники
 * и доставляет их пачкой. Здесь проверяется именно это: не «работает
 * ли счастливый путь», а переживает ли он сон.
 */

const COMPANY = '01a01104-8634-7bee-9c85-8f524802c241'
const NOW = 1_755_000_000_000
const TRACKED = '/api/extension/companies/{companyId}/tracked-skus'
const OBSERVATIONS = '/api/extension/companies/{companyId}/price-observations'

interface FakeWindow {
  readonly id: number
  closed: boolean
}

let storage: Storage
let windows: FakeWindow[]
let alarms: Map<string, { delayInMinutes?: number; periodInMinutes?: number }>

function fakeStorage(initial: Record<string, unknown> = {}): Storage {
  let data: Record<string, unknown> = { ...initial }

  return {
    get: (keys: string[]) =>
      Promise.resolve(
        Object.fromEntries(keys.map((key) => [key, data[key]])) as Record<
          string,
          unknown
        >,
      ),
    set: (items: Record<string, unknown>) => {
      data = { ...data, ...items }

      return Promise.resolve()
    },
    clear: () => {
      data = {}

      return Promise.resolve()
    },
  }
}

beforeEach(() => {
  windows = []
  alarms = new Map()
  storage = fakeStorage({
    connection: {
      token: 'conwix_ext_t',
      companyId: COMPANY,
      companyName: 'Acme',
    },
  })

  let nextWindowId = 100
  vi.stubGlobal('chrome', {
    alarms: {
      create: (name: string, info: Record<string, number>) =>
        alarms.set(name, info),
      get: (name: string) =>
        Promise.resolve(alarms.get(name) ? { name } : undefined),
      getAll: () =>
        Promise.resolve([...alarms.keys()].map((name) => ({ name }))),
      clear: (name: string) => Promise.resolve(alarms.delete(name)),
    },
    windows: {
      create: () => {
        const created = { id: (nextWindowId += 1), closed: false }
        windows.push(created)

        return Promise.resolve({ id: created.id })
      },
      remove: (id: number) => {
        const found = windows.find((w) => w.id === id)
        if (undefined === found) {
          return Promise.reject(new Error('no such window'))
        }
        found.closed = true

        return Promise.resolve()
      },
    },
  })
})

function trackedSkus(...items: string[]): void {
  server.use(
    http.get(TRACKED, () => HttpResponse.json({ items, nextCursor: null })),
  )
}

async function runCycle(): Promise<void> {
  await handlePriceSyncAlarm(storage, 'conwix:price-sync')
}

async function fireCapture(sku: string): Promise<void> {
  await handlePriceSyncAlarm(storage, `conwix:capture:${sku}`)
}

describe('цикл обхода артикулов', () => {
  it('размазывает визиты по получасу, а не открывает пачкой', async () => {
    trackedSkus('111', '222', '333')

    await runCycle()

    expect(alarms.get('conwix:capture:111')?.delayInMinutes).toBe(0.5)
    expect(alarms.get('conwix:capture:222')?.delayInMinutes).toBe(10)
    expect(alarms.get('conwix:capture:333')?.delayInMinutes).toBe(20)
  })

  it('отменяет будильники снятых с отслеживания артикулов', async () => {
    trackedSkus('111', '222')
    await runCycle()

    // Продавец снял 222, пока устройство спало.
    trackedSkus('111')
    await runCycle()

    expect(alarms.has('conwix:capture:111')).toBe(true)
    expect(alarms.has('conwix:capture:222')).toBe(false)
  })

  it('пустой список гасит прошлый цикл, а не оставляет его жить', async () => {
    trackedSkus('111')
    await runCycle()

    trackedSkus()
    await runCycle()
    // Будильник, доставленный после сна, не должен открыть окно
    // по списку, которого больше нет.
    await fireCapture('111')

    expect(windows).toHaveLength(0)
  })
})

describe('захват одного артикула', () => {
  beforeEach(() => {
    server.use(http.post(OBSERVATIONS, () => HttpResponse.json(null)))
  })

  it('открывает окно и заводит будильник закрытия с его номером', async () => {
    trackedSkus('111')
    await runCycle()

    await fireCapture('111')

    expect(windows).toHaveLength(1)
    // Идентификатор окна в имени будильника — то, из-за чего окно
    // закроется даже если воркер умрёт сразу после открытия.
    expect(alarms.has(`conwix:capture-timeout:${windows[0]?.id}`)).toBe(true)
  })

  it('закрывает по таймауту ровно своё окно, а не текущее', async () => {
    trackedSkus('111', '222')
    await runCycle()
    await fireCapture('111')
    const first = windows[0]

    // Наблюдение по первому пришло, окно закрылось, открылось второе.
    await submitObservation(
      storage,
      {
        marketplaceSku: '111',
        observedAt: '2026-08-18T09:00:00.000Z',
        amountMinor: 111_700,
        currency: 'RUB',
      },
      first?.id,
      '0.2.0',
      NOW,
    )
    await fireCapture('222')
    const second = windows[1]

    // Просроченный таймаут первого доставлен после сна устройства.
    await handlePriceSyncAlarm(storage, `conwix:capture-timeout:${first?.id}`)

    expect(second?.closed).toBe(false)
  })

  it('не открывает второе окно, пока первое не закрылось', async () => {
    trackedSkus('111', '222')
    await runCycle()

    await fireCapture('111')
    await fireCapture('222')

    expect(windows).toHaveLength(1)
  })
})

describe('приём наблюдения', () => {
  beforeEach(() => {
    server.use(http.post(OBSERVATIONS, () => HttpResponse.json(null)))
  })

  it('отправляет снимок и закрывает окно', async () => {
    trackedSkus('111')
    await runCycle()
    await fireCapture('111')

    await submitObservation(
      storage,
      {
        marketplaceSku: '111',
        observedAt: '2026-08-18T09:00:00.000Z',
        amountMinor: 111_700,
        currency: 'RUB',
      },
      windows[0]?.id,
      '0.2.0',
      NOW,
    )

    expect(windows[0]?.closed).toBe(true)
    expect(await isCaptureVisit(storage, '111', windows[0]?.id)).toBe(false)
  })

  it('отвергает снимок не из того окна', async () => {
    trackedSkus('111')
    await runCycle()
    await fireCapture('111')

    // Повторно запущенный content-script прислал бы новый момент
    // снимка, и естественный ключ базы такой дубль не отсёк бы.
    await submitObservation(
      storage,
      {
        marketplaceSku: '111',
        observedAt: '2026-08-18T09:00:01.000Z',
        amountMinor: 111_700,
        currency: 'RUB',
      },
      999,
      '0.2.0',
      NOW,
    )

    expect(windows[0]?.closed).toBe(false)
  })

  it('признаёт фоновым только тот визит, который сам открыл', async () => {
    trackedSkus('111')
    await runCycle()
    await fireCapture('111')
    const windowId = windows[0]?.id

    expect(await isCaptureVisit(storage, '111', windowId)).toBe(true)
    // Человек открыл ту же карточку в своём окне — снимок оттуда
    // слать нельзя, иначе панель писала бы наблюдения при каждом
    // обычном визите.
    expect(await isCaptureVisit(storage, '111', 42)).toBe(false)
    expect(await isCaptureVisit(storage, '222', windowId)).toBe(false)
  })
})

describe('первый снимок при включении отслеживания', () => {
  beforeEach(() => {
    server.use(http.post(OBSERVATIONS, () => HttpResponse.json(null)))
  })

  it('принимает снимок из обычной вкладки сразу после включения', async () => {
    // Без этого первые данные появлялись бы только со следующим обходом,
    // то есть до получаса спустя: экран показывал бы «ещё не снимали»,
    // и человек, только что нажавший кнопку, видел бы то же самое,
    // что при сломанном сборе.
    await allowFirstCapture(storage, '111', COMPANY, NOW)

    await submitObservation(
      storage,
      {
        marketplaceSku: '111',
        observedAt: '2026-08-18T09:00:00.000Z',
        amountMinor: 350_000,
        currency: 'RUB',
      },
      777,
      '0.2.0',
      NOW,
    )

    // Вкладку продавца закрывать нельзя — он в ней работает.
    expect(windows).toHaveLength(0)
  })

  it('разрешение одноразовое', async () => {
    await allowFirstCapture(storage, '111', COMPANY, NOW)
    const observation = {
      marketplaceSku: '111',
      observedAt: '2026-08-18T09:00:00.000Z',
      amountMinor: 350_000,
      currency: 'RUB',
    }

    await submitObservation(storage, observation, 777, '0.2.0', NOW)
    // Второй снимок из той же вкладки уже не принимается: иначе
    // content-script слал бы наблюдение при каждом визите на карточку,
    // и история цен зависела бы от того, как часто туда заходят.
    await submitObservation(storage, observation, 777, '0.2.0', NOW)

    expect(await consumedAll()).toBe(true)
  })

  it('два артикула подряд не затирают разрешения друг друга', async () => {
    // Единственный слот на все артикулы означал бы, что второй
    // включённый товар остаётся без первого снимка до фонового обхода.
    await allowFirstCapture(storage, '111', COMPANY, NOW)
    await allowFirstCapture(storage, '222', COMPANY, NOW)

    for (const sku of ['111', '222']) {
      await submitObservation(
        storage,
        {
          marketplaceSku: sku,
          observedAt: '2026-08-18T09:00:00.000Z',
          amountMinor: 350_000,
          currency: 'RUB',
        },
        777,
        '0.2.0',
        NOW,
      )
    }

    expect(await consumedAll()).toBe(true)
  })

  it('разрешение чужой компании не принимается', async () => {
    // Между выдачей и приходом снимка расширение могли переподключить
    // к другой компании: цена, снятая для первой, ушла бы с токеном
    // второй и выглядела бы там настоящей.
    await allowFirstCapture(storage, '111', 'другая-компания', NOW)

    await submitObservation(
      storage,
      {
        marketplaceSku: '111',
        observedAt: '2026-08-18T09:00:00.000Z',
        amountMinor: 350_000,
        currency: 'RUB',
      },
      777,
      '0.2.0',
      NOW,
    )

    expect(await consumedAll()).toBe(false)
  })

  it('разрешение не действует на другой артикул и после срока', async () => {
    await allowFirstCapture(storage, '111', COMPANY, NOW)
    await submitObservation(
      storage,
      {
        marketplaceSku: '222',
        observedAt: '2026-08-18T09:00:00.000Z',
        amountMinor: 1,
        currency: 'RUB',
      },
      777,
      '0.2.0',
      NOW,
    )
    expect(await consumedAll()).toBe(false)

    await submitObservation(
      storage,
      {
        marketplaceSku: '111',
        observedAt: '2026-08-18T09:00:00.000Z',
        amountMinor: 1,
        currency: 'RUB',
      },
      777,
      '0.2.0',
      NOW + 10 * 60_000,
    )
    expect(await consumedAll()).toBe(false)
  })

  /** Не осталось ли неиспользованных разрешений. */
  async function consumedAll(): Promise<boolean> {
    const stored = await storage.get(['firstCapture'])
    const grants = stored.firstCapture

    return (
      null === grants ||
      undefined === grants ||
      0 === Object.keys(grants as Record<string, unknown>).length
    )
  }
})

describe('имена будильников', () => {
  it('свои узнаёт, чужие нет', () => {
    expect(isPriceSyncAlarm('conwix:price-sync')).toBe(true)
    expect(isPriceSyncAlarm('conwix:capture:111')).toBe(true)
    expect(isPriceSyncAlarm('conwix:capture-timeout:100')).toBe(true)
    expect(isPriceSyncAlarm('conwix:catalog')).toBe(false)
  })
})
