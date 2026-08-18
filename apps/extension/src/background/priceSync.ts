import { fetchTrackedSkuPage, recordObservation } from '../api/client'
import {
  readConnection,
  type Connection,
  type Storage,
} from '../shared/connection'

/**
 * Обход отслеживаемых артикулов ради витринной цены (ADR-014, ADR-015).
 *
 * Раз в полчаса service worker берёт список отслеживания и открывает
 * каждый артикул в фоновом свёрнутом окне: цену, которую видит
 * покупатель, не отдаёт ни один API площадки, и взять её можно только
 * с отрисованной страницы.
 *
 * **Визиты размазаны по окну, а не идут пачкой.** Полсотни окон подряд —
 * это и всплеск, похожий на бота, и полсотни окон, мигающих у человека
 * перед глазами. Каждому артикулу заводится свой одноразовый будильник
 * со сдвигом `i × 30мин / N`.
 *
 * **Будильники, а не таймеры.** Service worker MV3 засыпает через
 * полминуты простоя, и `setTimeout` умирает вместе с ним — из тридцати
 * минут ожидания не пережил бы ни один.
 *
 * **Разрешений манифеста не добавляется ни одного.** `chrome.windows`
 * их не требует; `tabs` понадобился бы только чтобы читать адрес чужой
 * вкладки, а мы своё окно и так знаем по идентификатору.
 *
 * ponytail: захват идёт по одному, без очереди и без параллельности.
 * Потолок в 50 артикулов держит бэкенд, и при нём шаг между визитами —
 * 36 секунд, вчетверо больше, чем занимает сам визит. Понадобится
 * больше — обсуждать параллельность, а не растягивать окно.
 */

const CYCLE_ALARM = 'conwix:price-sync'
const CAPTURE_PREFIX = 'conwix:capture:'
const CAPTURE_TIMEOUT_ALARM = 'conwix:capture-timeout'

const CYCLE_MINUTES = 30

/** Двести — максимум страницы; список короче потолка в 50 артикулов. */
const PAGE_SIZE = 200
const MAX_PAGES = 5

/** Что сейчас снимается. В storage, а не в памяти: воркер засыпает. */
const CAPTURE_KEY = 'capture'

interface PendingCapture {
  readonly marketplaceSku: string
  readonly windowId: number
}

export function schedulePriceSync(): void {
  chrome.alarms.create(CYCLE_ALARM, {
    periodInMinutes: CYCLE_MINUTES,
    delayInMinutes: 1,
  })
}

export function isPriceSyncAlarm(name: string): boolean {
  return (
    CYCLE_ALARM === name ||
    CAPTURE_TIMEOUT_ALARM === name ||
    name.startsWith(CAPTURE_PREFIX)
  )
}

export async function handlePriceSyncAlarm(
  storage: Storage,
  name: string,
): Promise<void> {
  if (CYCLE_ALARM === name) {
    await startCycle(storage)

    return
  }

  if (CAPTURE_TIMEOUT_ALARM === name) {
    // Страница не отдала цену за отведённое время: не загрузилась,
    // разметки не оказалось, увели редиректом. Окно закрывается
    // в любом случае — брошенное окно хуже пропущенного наблюдения.
    await finishCapture(storage)

    return
  }

  await capture(storage, name.slice(CAPTURE_PREFIX.length))
}

/**
 * Список берётся у сервера, а не из локального хранилища: он меняется
 * не только с этого устройства (ADR-014), и обход по устаревшей копии
 * ходил бы за снятыми артикулами и пропускал добавленные.
 */
async function startCycle(storage: Storage): Promise<void> {
  const connection = await readConnection(storage)
  if (null === connection) {
    return
  }

  const skus = await trackedSkus(connection)
  if (0 === skus.length) {
    return
  }

  // Список цикла сохраняется, чтобы будильник снятого артикула
  // не открывал окно впустую: имена будильников переживают цикл,
  // а список отслеживания между циклами меняется.
  await storage.set({ [CAPTURE_KEY]: null, cycle: skus })

  const step = CYCLE_MINUTES / skus.length
  skus.forEach((sku, index) => {
    chrome.alarms.create(CAPTURE_PREFIX + sku, {
      // Chrome округляет задержку вверх до получаса минимум лишь для
      // повторяющихся будильников; одноразовым хватает и долей минуты.
      delayInMinutes: Math.max(0.5, index * step),
    })
  })
}

async function trackedSkus(connection: Connection): Promise<string[]> {
  const skus: string[] = []
  let cursor: string | null = null

  for (let page = 0; page < MAX_PAGES; page += 1) {
    const response = await fetchTrackedSkuPage(
      connection.token,
      connection.companyId,
      cursor,
      PAGE_SIZE,
    )
    skus.push(...response.items)

    cursor = response.nextCursor ?? null
    if (null === cursor) {
      return skus
    }
  }

  return skus
}

/**
 * Открывает карточку в фоновом свёрнутом окне. Адрес собирается
 * из одного артикула: `ozon.ru/product/{sku}/` без слага работает —
 * Ozon сам редиректит на полный (проверено 2026-08-18). Что редирект
 * привёл именно на нужный товар, проверяет content-script по `sku`
 * в разметке.
 */
async function capture(
  storage: Storage,
  marketplaceSku: string,
): Promise<void> {
  const stored = await storage.get([CAPTURE_KEY, 'cycle'])
  const cycle = stored.cycle
  if (!Array.isArray(cycle) || !cycle.includes(marketplaceSku)) {
    // Артикул сняли с отслеживания между циклами — будильник остался
    // от прошлого. Открывать окно незачем.
    return
  }
  if (null !== parseCapture(stored[CAPTURE_KEY])) {
    // Предыдущий захват ещё не завершился. Пропускаем: артикул придёт
    // в следующем цикле, а два окна разом — то, чего мы избегаем.
    return
  }

  const created = await chrome.windows.create({
    url: `https://www.ozon.ru/product/${encodeURIComponent(marketplaceSku)}/`,
    focused: false,
    state: 'minimized',
  })

  if (undefined === created.id) {
    return
  }

  await storage.set({
    [CAPTURE_KEY]: {
      marketplaceSku,
      windowId: created.id,
    } satisfies PendingCapture,
  })
  chrome.alarms.create(CAPTURE_TIMEOUT_ALARM, { delayInMinutes: 1 })
}

/**
 * Идёт ли сейчас захват этого артикула в этом окне. Спрашивает
 * обработчик сообщений: content-script один и тот же и на обычном
 * визите человека, и на фоновом, а слать наблюдение он должен только
 * во втором случае.
 */
export async function isCaptureVisit(
  storage: Storage,
  marketplaceSku: string,
  windowId: number | undefined,
): Promise<boolean> {
  const stored = await storage.get([CAPTURE_KEY])
  const pending = parseCapture(stored[CAPTURE_KEY])

  return (
    null !== pending &&
    pending.marketplaceSku === marketplaceSku &&
    pending.windowId === windowId
  )
}

/**
 * Наблюдение доехало: отправляем и закрываем окно. Отказ сети
 * наблюдение теряет — повторять его нельзя, потому что цена уже
 * не та, которую мы видели. Следующий цикл снимет заново.
 */
export async function submitObservation(
  storage: Storage,
  observation: {
    marketplaceSku: string
    observedAt: string
    amountMinor: number
    currency: string
  },
  extensionVersion: string,
): Promise<void> {
  const connection = await readConnection(storage)
  if (null !== connection) {
    try {
      await recordObservation(connection.token, connection.companyId, {
        ...observation,
        extensionVersion,
      })
    } catch {
      // Артикул сняли с отслеживания, сеть отвалилась, токен умер —
      // во всех случаях делать нечего, кроме как закрыть окно.
    }
  }

  await finishCapture(storage)
}

async function finishCapture(storage: Storage): Promise<void> {
  const stored = await storage.get([CAPTURE_KEY])
  const pending = parseCapture(stored[CAPTURE_KEY])
  await storage.set({ [CAPTURE_KEY]: null })

  if (null !== pending) {
    await chrome.windows.remove(pending.windowId).catch(() => undefined)
  }
}

function parseCapture(value: unknown): PendingCapture | null {
  if (null === value || 'object' !== typeof value) {
    return null
  }

  const candidate = value as Record<string, unknown>
  if (
    'string' !== typeof candidate.marketplaceSku ||
    'number' !== typeof candidate.windowId
  ) {
    return null
  }

  return {
    marketplaceSku: candidate.marketplaceSku,
    windowId: candidate.windowId,
  }
}
