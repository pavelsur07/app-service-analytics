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
 * **Всё здесь построено вокруг того, что воркер засыпает и просыпается
 * в произвольный момент.** Это не абстрактная осторожность: Chrome
 * гасит воркер через полминуты простоя, копит пропущенные будильники
 * на спящем устройстве и доставляет их пачкой при пробуждении. Отсюда
 * три решения, каждое из которых чинит целый класс отказов, а не случай.
 *
 * **Состояние — карта «окно → артикул», а не единственный слот.** Слот
 * требовал бы, чтобы проверка «свободен ли» и его занятие были одним
 * действием, а между ними лежат `await` к хранилищу; при доставке
 * пачки будильников несколько обработчиков увидели бы слот свободным,
 * и лишние окна остались бы бесхозными. Карта делает лишнее окно
 * безвредным: каждое записано и каждое будет закрыто.
 *
 * **Будильник закрытия несёт в имени идентификатор окна** и ставится
 * до записи в хранилище. Поэтому окно закроется, даже если воркер
 * умрёт сразу после его открытия: будильник переживает смерть воркера,
 * а хранилище к тому моменту ещё пусто. Общий будильник закрывал бы
 * «текущий» захват — то есть после сна устройства закрыл бы чужой.
 *
 * **Периодический будильник создаётся, только если его ещё нет.**
 * Chrome заменяет одноимённый, сбрасывая отсчёт; воркер, просыпающийся
 * чаще, чем раз в отведённую задержку, откладывал бы цикл вечно.
 */

const CYCLE_ALARM = 'conwix:price-sync'
const CAPTURE_PREFIX = 'conwix:capture:'
const TIMEOUT_PREFIX = 'conwix:capture-timeout:'

const CYCLE_MINUTES = 30

/** Двести — максимум страницы; список короче потолка в 50 артикулов. */
const PAGE_SIZE = 200
const MAX_PAGES = 5

const CYCLE_KEY = 'cycle'
const CAPTURES_KEY = 'captures'

/**
 * Разрешение прислать один снимок из обычной вкладки — выдаётся
 * в момент включения отслеживания.
 */
const FIRST_CAPTURE_KEY = 'firstCapture'

/**
 * Сколько это разрешение живёт. Продавец только что нажал кнопку,
 * карточка перед ним открыта, и снимок уходит в ту же секунду; две
 * минуты — запас на медленную сеть, а не окно возможностей.
 */
const FIRST_CAPTURE_TTL_MS = 120_000

/**
 * Сколько окон держим одновременно. Один: при потолке в 50 артикулов
 * шаг между визитами 36 секунд, вчетверо больше самого визита.
 * Проверка не строгая — доставленная пачка будильников может открыть
 * второе окно, и это безвредно: оба записаны, оба закроются.
 */
const MAX_OPEN_WINDOWS = 1

export async function schedulePriceSync(): Promise<void> {
  await createIfAbsent(CYCLE_ALARM, {
    periodInMinutes: CYCLE_MINUTES,
    delayInMinutes: 1,
  })
}

/**
 * Создаёт будильник, только если такого ещё нет. `chrome.alarms.create`
 * с существующим именем заменяет его и сбрасывает отсчёт: воркер,
 * просыпающийся чаще задержки, откладывал бы срабатывание бесконечно.
 */
export async function createIfAbsent(
  name: string,
  info: { periodInMinutes?: number; delayInMinutes?: number },
): Promise<void> {
  const existing = await chrome.alarms.get(name)
  if (undefined === existing) {
    chrome.alarms.create(name, info)
  }
}

export function isPriceSyncAlarm(name: string): boolean {
  return (
    CYCLE_ALARM === name ||
    name.startsWith(CAPTURE_PREFIX) ||
    name.startsWith(TIMEOUT_PREFIX)
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

  if (name.startsWith(TIMEOUT_PREFIX)) {
    // Страница не отдала цену: не загрузилась, разметки не оказалось,
    // увели редиректом. Закрываем ровно то окно, ради которого этот
    // будильник заводился, — «текущее» после сна устройства было бы
    // уже чужим.
    await closeWindow(storage, Number(name.slice(TIMEOUT_PREFIX.length)))

    return
  }

  await capture(storage, name.slice(CAPTURE_PREFIX.length))
}

/**
 * Список берётся у сервера, а не из локального хранилища: он меняется
 * не только с этого устройства (ADR-014), и обход по устаревшей копии
 * ходил бы за снятыми артикулами и пропускал добавленные.
 *
 * Открытые окна прошлого цикла не трогаются: их закроют собственные
 * будильники. Обнулять их здесь значило бы бросить окно, до которого
 * цикл дотянулся на двадцать девятой минуте.
 */
async function startCycle(storage: Storage): Promise<void> {
  const connection = await readConnection(storage)
  const skus = null === connection ? [] : await trackedSkus(connection)

  // Список пишется всегда, в том числе пустой: иначе будильники
  // прошлого цикла, доставленные после сна устройства, прошли бы
  // проверку по старому списку и открыли окна для артикулов, которые
  // продавец уже снял с отслеживания.
  await storage.set({ [CYCLE_KEY]: skus })
  await clearStaleCaptureAlarms(skus)

  if (0 === skus.length) {
    return
  }

  const step = CYCLE_MINUTES / skus.length
  skus.forEach((sku, index) => {
    chrome.alarms.create(CAPTURE_PREFIX + sku, {
      delayInMinutes: Math.max(0.5, index * step),
    })
  })
}

/**
 * Будильники артикулов, снятых с отслеживания, отменяются: имена
 * переживают цикл, а список между циклами меняется.
 */
async function clearStaleCaptureAlarms(skus: readonly string[]): Promise<void> {
  const alarms = await chrome.alarms.getAll()
  const kept = new Set(skus.map((sku) => CAPTURE_PREFIX + sku))

  await Promise.all(
    alarms
      .filter(
        (alarm) =>
          alarm.name.startsWith(CAPTURE_PREFIX) && !kept.has(alarm.name),
      )
      .map((alarm) => chrome.alarms.clear(alarm.name)),
  )
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
  const stored = await storage.get([CYCLE_KEY, CAPTURES_KEY])
  const cycle = stored[CYCLE_KEY]
  if (!Array.isArray(cycle) || !cycle.includes(marketplaceSku)) {
    return
  }
  if (
    Object.keys(parseCaptures(stored[CAPTURES_KEY])).length >= MAX_OPEN_WINDOWS
  ) {
    // Предыдущий визит ещё идёт. Артикул придёт в следующем цикле:
    // пропущенный снимок дешевле десятка окон разом.
    return
  }

  const created = await chrome.windows.create({
    url: `https://www.ozon.ru/product/${encodeURIComponent(marketplaceSku)}/`,
    focused: false,
    state: 'minimized',
  })

  const windowId = created.id
  if (undefined === windowId) {
    return
  }

  // Будильник закрытия — до записи в хранилище, и это важно: если
  // воркер умрёт здесь, окно всё равно закроется, потому что имя
  // будильника несёт его идентификатор.
  chrome.alarms.create(TIMEOUT_PREFIX + windowId, { delayInMinutes: 1 })

  await rememberCapture(storage, windowId, marketplaceSku)
}

/**
 * Разрешает один снимок из обычной вкладки продавца — той самой,
 * где он нажал «Отслеживать цену».
 *
 * Зачем: без этого первые данные появлялись бы только со следующим
 * обходом, то есть до получаса спустя. Экран всё это время показывал
 * бы «ещё не снимали», и человек, только что включивший отслеживание,
 * видел бы ровно то же, что при сломанном сборе.
 *
 * Карточка в этот момент уже открыта и уже разобрана — снимать её
 * фоновым окном значило бы открыть заново то, что и так перед глазами.
 *
 * Разрешение одноразовое и с коротким сроком: снимок принимается один,
 * дальше артикул обходится общим порядком. Иначе content-script слал бы
 * наблюдение при каждом обычном визите на карточку, и история цен
 * зависела бы от того, как часто продавец на неё заходит.
 */
export async function allowFirstCapture(
  storage: Storage,
  marketplaceSku: string,
  now: number,
): Promise<void> {
  await storage.set({
    [FIRST_CAPTURE_KEY]: { marketplaceSku, until: now + FIRST_CAPTURE_TTL_MS },
  })
}

async function consumeFirstCapture(
  storage: Storage,
  marketplaceSku: string,
  now: number,
): Promise<boolean> {
  const stored = await storage.get([FIRST_CAPTURE_KEY])
  const granted = stored[FIRST_CAPTURE_KEY]
  if (null === granted || 'object' !== typeof granted) {
    return false
  }

  const candidate = granted as Record<string, unknown>
  const allowed =
    candidate.marketplaceSku === marketplaceSku &&
    'number' === typeof candidate.until &&
    candidate.until > now

  if (allowed) {
    await storage.set({ [FIRST_CAPTURE_KEY]: null })
  }

  return allowed
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
  if (undefined === windowId) {
    return false
  }

  const stored = await storage.get([CAPTURES_KEY])

  return (
    parseCaptures(stored[CAPTURES_KEY])[String(windowId)] === marketplaceSku
  )
}

/**
 * Наблюдение доехало. Принимается только от того окна, которому этот
 * артикул и поручали: повторно запущенный content-script присылает
 * новый момент снимка, и естественный ключ базы такой дубль
 * не отсекает — отсекать его должны здесь.
 *
 * Отказ отправки наблюдение теряет, и повторять его нельзя: цена
 * уже не та, которую мы видели. Следующий цикл снимет заново.
 */
export async function submitObservation(
  storage: Storage,
  observation: {
    marketplaceSku: string
    observedAt: string
    amountMinor: number
    currency: string
  },
  windowId: number | undefined,
  extensionVersion: string,
  now: number,
): Promise<void> {
  const fromCaptureWindow = await isCaptureVisit(
    storage,
    observation.marketplaceSku,
    windowId,
  )

  // Обычная вкладка принимается ровно один раз и ровно после включения
  // отслеживания (allowFirstCapture). Всё остальное — опоздавшее или
  // чужое сообщение: закрывать по нему нечего, а записывать тем более.
  const firstAfterEnabling =
    !fromCaptureWindow &&
    (await consumeFirstCapture(storage, observation.marketplaceSku, now))

  if (!fromCaptureWindow && !firstAfterEnabling) {
    return
  }

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

  // Закрываем только своё окно. Вкладку продавца трогать нельзя —
  // он в ней работает.
  if (fromCaptureWindow) {
    await closeWindow(storage, Number(windowId))
  }
}

/**
 * Закрывает окно и забывает его. Порядок обратный интуитивному:
 * сначала закрытие, потом очистка состояния. Если закрытие не удалось,
 * запись остаётся, и окно ещё можно найти; очистив состояние первой,
 * мы потеряли бы единственный след, по которому его закрывают.
 */
async function closeWindow(storage: Storage, windowId: number): Promise<void> {
  try {
    await chrome.windows.remove(windowId)
  } catch {
    // Окно уже закрыто — человеком или прошлым вызовом. Запись
    // всё равно надо убрать.
  }

  await chrome.alarms.clear(TIMEOUT_PREFIX + windowId)
  await forgetCapture(storage, windowId)
}

async function rememberCapture(
  storage: Storage,
  windowId: number,
  marketplaceSku: string,
): Promise<void> {
  const stored = await storage.get([CAPTURES_KEY])
  await storage.set({
    [CAPTURES_KEY]: {
      ...parseCaptures(stored[CAPTURES_KEY]),
      [String(windowId)]: marketplaceSku,
    },
  })
}

async function forgetCapture(
  storage: Storage,
  windowId: number,
): Promise<void> {
  const stored = await storage.get([CAPTURES_KEY])
  const remaining = Object.entries(parseCaptures(stored[CAPTURES_KEY])).filter(
    ([id]) => id !== String(windowId),
  )

  await storage.set({ [CAPTURES_KEY]: Object.fromEntries(remaining) })
}

function parseCaptures(value: unknown): Record<string, string> {
  if (null === value || 'object' !== typeof value) {
    return {}
  }

  const entries = Object.entries(value as Record<string, unknown>).filter(
    (entry): entry is [string, string] => 'string' === typeof entry[1],
  )

  return Object.fromEntries(entries)
}
