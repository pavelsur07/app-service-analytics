import {
  ApiError,
  fetchMe,
  fetchSkuSales,
  fetchTrackedSkuPage,
  startTracking,
  stopTracking,
} from '../api/client'
import {
  isOwnSku,
  isStale,
  readCatalog,
  refreshCatalog,
} from '../shared/catalog'
import {
  isObservationMessage,
  isOverlayRequest,
  isSetTrackingRequest,
  type OverlayData,
  type TrackingResult,
} from '../shared/overlayRequest'

import {
  handlePriceSyncAlarm,
  isCaptureVisit,
  isPriceSyncAlarm,
  schedulePriceSync,
  submitObservation,
} from './priceSync'
import {
  browserStorage,
  readConnection,
  writeConnection,
  type Connection,
} from '../shared/connection'

// Service worker MV3 засыпает примерно через полминуты простоя, поэтому
// состояния в памяти здесь нет и быть не может: единственное, что он
// делает, — принимает токен от приложения и кладёт его в storage.

interface ConnectMessage {
  readonly type: 'conwix:connect'
  readonly token: string
}

type ConnectResult =
  | { readonly ok: true; readonly companyName: string }
  | { readonly ok: false; readonly error: string }

/**
 * Токен приходит из SPA через externally_connectable. Круг отправителей
 * ограничен манифестом (только домен приложения) — браузер не доставит
 * сюда сообщение с чужой страницы, поэтому проверять origin повторно
 * незачем, а вот форму сообщения проверить надо.
 *
 * companyId и название не берутся из сообщения: их сообщает сервер
 * в ответ на предъявление токена. Иначе страница могла бы записать
 * расширению чужую компанию рядом с настоящим токеном, и расширение
 * показывало бы данные под неверной подписью.
 */
chrome.runtime.onMessageExternal.addListener(
  (message, _sender, sendResponse) => {
    if (!isConnectMessage(message)) {
      sendResponse({
        ok: false,
        error: 'unsupported_message',
      } satisfies ConnectResult)

      return false
    }

    void connect(message.token).then(sendResponse)

    // true — ответ будет асинхронным; без него канал закроется раньше,
    // чем сеть ответит, и приложение не узнает результат.
    return true
  },
)

/**
 * Разговор с оверлеем. Сеть живёт здесь, а не в content-script: тот
 * выполняется в origin страницы маркетплейса и его fetch подчиняется
 * CORS (shared/overlayRequest.ts). Заодно токен и хранилище не покидают
 * service worker.
 */
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  // Чужое сообщение оставляем без ответа и без sendResponse: слушателей
  // может быть несколько, и ответивший первым закрывает канал. Ответить
  // «не знаю» на всё подряд — значит сломать любой обработчик, который
  // появится следом.
  if (isOverlayRequest(message)) {
    void overlayDataFor(message.marketplaceSku, sender.tab?.windowId).then(
      sendResponse,
    )

    return true
  }

  // Снимок цены с фонового визита. Ответа не ждём: content-script
  // после отправки делать нечего — окно всё равно закроет service worker.
  if (isObservationMessage(message)) {
    void submitObservation(
      browserStorage(),
      {
        marketplaceSku: message.marketplaceSku,
        observedAt: message.observedAt,
        amountMinor: message.amountMinor,
        currency: message.currency,
      },
      EXTENSION_VERSION,
    )

    return false
  }

  if (isSetTrackingRequest(message)) {
    void setTracking(message.marketplaceSku, message.tracked).then(sendResponse)

    return true
  }

  return false
})

/**
 * Один и тот же null на «не подключено», «не наш товар» и «не смогли
 * спросить»: оверлею во всех трёх случаях делать одно — молчать.
 *
 * Проверка принадлежности здесь и остаётся локальной: артикул чужого
 * товара никуда не уходит, сервер о нём не узнаёт.
 */
async function overlayDataFor(
  marketplaceSku: string,
  windowId: number | undefined,
): Promise<OverlayData | null> {
  const connection = await ownSkuConnection(marketplaceSku)
  if (null === connection) {
    return null
  }

  try {
    // Оба запроса разом: панель рисуется целиком или не рисуется вовсе,
    // и ждать их по очереди значило бы удвоить задержку до появления
    // цифр на карточке.
    const [sales, tracked, capture] = await Promise.all([
      fetchSkuSales(connection.token, connection.companyId, marketplaceSku),
      isTracked(connection, marketplaceSku),
      isCaptureVisit(browserStorage(), marketplaceSku, windowId),
    ])

    return { sales, tracked, capture }
  } catch {
    return null
  }
}

/**
 * Отслеживается ли артикул. Спрашивается у сервера каждый раз, без
 * локального кэша: список меняется не только с этого устройства
 * (ADR-014), и кнопка, показывающая устаревшее состояние, хуже кнопки,
 * которая стоила одного запроса.
 */
async function isTracked(
  connection: Connection,
  marketplaceSku: string,
): Promise<boolean> {
  let cursor: string | null = null

  // Страниц у списка практически всегда одна: сервер держит потолок
  // в полсотни артикулов на компанию, а страница вмещает двести.
  for (let page = 0; page < TRACKED_MAX_PAGES; page += 1) {
    const response = await fetchTrackedSkuPage(
      connection.token,
      connection.companyId,
      cursor,
      TRACKED_PAGE_SIZE,
    )
    if (response.items.includes(marketplaceSku)) {
      return true
    }

    cursor = response.nextCursor ?? null
    if (null === cursor) {
      return false
    }
  }

  return false
}

/**
 * Включение и выключение отслеживания. Ошибку сервера отдаём как есть:
 * «нет активного подключения Ozon» и «больше 50 артикулов» продавец
 * должен прочитать, а не гадать по общему «не удалось».
 */
async function setTracking(
  marketplaceSku: string,
  tracked: boolean,
): Promise<TrackingResult | null> {
  const connection = await ownSkuConnection(marketplaceSku)
  if (null === connection) {
    return null
  }

  try {
    if (tracked) {
      await startTracking(
        connection.token,
        connection.companyId,
        marketplaceSku,
      )
    } else {
      await stopTracking(connection.token, connection.companyId, marketplaceSku)
    }

    return { tracked, error: null }
  } catch (caught) {
    return {
      // Состояние не изменилось — кнопка обязана вернуться в прежнее
      // положение, а не показывать желаемое как случившееся.
      tracked: !tracked,
      error:
        caught instanceof ApiError
          ? caught.message
          : 'Не удалось связаться с Conwix.',
    }
  }
}

/**
 * Подключение, если оно есть и артикул принадлежит компании. Общая
 * прихожая обоих сценариев: с чужой карточки к серверу не уходит ничего.
 */
async function ownSkuConnection(
  marketplaceSku: string,
): Promise<Connection | null> {
  const storage = browserStorage()
  const connection = await readConnection(storage)
  if (null === connection) {
    return null
  }

  const catalog = await readCatalog(storage, connection.companyId)
  if (null === catalog || !isOwnSku(catalog, marketplaceSku)) {
    return null
  }

  return connection
}

// Версия сборки уезжает с каждым наблюдением: по ней читаются массовые
// пропуски, когда у части клиентов расширение старое.
declare const __EXTENSION_VERSION__: string

const EXTENSION_VERSION: string = __EXTENSION_VERSION__

// Двести — максимум страницы у эндпоинта; потолок страниц страхует
// от бесконечного цикла, если сервер начнёт отдавать nextCursor,
// не двигая выборку.
const TRACKED_PAGE_SIZE = 200
const TRACKED_MAX_PAGES = 5

const CATALOG_ALARM = 'conwix:catalog'
const CATALOG_MAX_AGE_MS = 24 * 60 * 60 * 1000

// Будильник, не setInterval: service worker засыпает через полминуты
// простоя, и таймер в памяти умирает вместе с ним. Период чуть меньше
// суток — чтобы каталог успевал обновиться до того, как признан устаревшим.
chrome.alarms.create(CATALOG_ALARM, {
  periodInMinutes: 12 * 60,
  delayInMinutes: 1,
})

chrome.alarms.onAlarm.addListener((alarm) => {
  if (CATALOG_ALARM === alarm.name) {
    void refreshCatalogIfStale()

    return
  }

  if (isPriceSyncAlarm(alarm.name)) {
    void handlePriceSyncAlarm(browserStorage(), alarm.name)
  }
})

// Обход отслеживаемых артикулов ради витринной цены (ADR-014).
schedulePriceSync()

async function connect(token: string): Promise<ConnectResult> {
  try {
    // Токен проверяется до записи: подключённым расширение считается
    // только когда сервер подтвердил, кто это и какая компания.
    const me = await fetchMe(token)
    const connection: Connection = {
      token,
      companyId: me.company.id,
      companyName: me.company.name,
    }
    await writeConnection(browserStorage(), connection)
    // Каталог сразу, не по будильнику: иначе первые сутки после
    // подключения оверлей молчал бы на всех карточках, и выглядело бы
    // это как «расширение не работает».
    await refreshCatalogIfStale()

    return { ok: true, companyName: connection.companyName }
  } catch {
    return { ok: false, error: 'connect_failed' }
  }
}

async function refreshCatalogIfStale(): Promise<void> {
  const storage = browserStorage()
  const connection = await readConnection(storage)
  if (null === connection) {
    return
  }

  const now = new Date()
  const catalog = await readCatalog(storage, connection.companyId)
  if (null !== catalog && !isStale(catalog, now, CATALOG_MAX_AGE_MS)) {
    return
  }

  try {
    await refreshCatalog(storage, connection.token, connection.companyId, now)
  } catch {
    // Сеть недоступна или токен умер — прежний каталог остаётся жить.
    // Он устареет, но устаревший список артикулов лучше пустого:
    // товары редко исчезают, чаще добавляются.
  }
}

function isConnectMessage(value: unknown): value is ConnectMessage {
  if (null === value || 'object' !== typeof value) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return (
    'conwix:connect' === candidate.type &&
    'string' === typeof candidate.token &&
    '' !== candidate.token
  )
}
