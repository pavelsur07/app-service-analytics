import { fetchMe, fetchSkuSales } from '../api/client'
import {
  isOwnSku,
  isStale,
  readCatalog,
  refreshCatalog,
} from '../shared/catalog'
import { isSalesRequest } from '../shared/salesRequest'
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
 * Запрос итога продаж от оверлея. Сеть живёт здесь, а не в content-script:
 * тот выполняется в origin страницы маркетплейса и его fetch подчиняется
 * CORS (shared/salesRequest.ts). Заодно токен и хранилище не покидают
 * service worker.
 */
chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!isSalesRequest(message)) {
    sendResponse(null)

    return false
  }

  void salesFor(message.marketplaceSku).then(sendResponse)

  return true
})

/**
 * Один и тот же null на «не подключено», «не наш товар» и «не смогли
 * спросить»: оверлею во всех трёх случаях делать одно — молчать.
 *
 * Проверка принадлежности здесь и остаётся локальной: артикул чужого
 * товара никуда не уходит, сервер о нём не узнаёт.
 */
async function salesFor(marketplaceSku: string) {
  const storage = browserStorage()
  const connection = await readConnection(storage)
  if (null === connection) {
    return null
  }

  const catalog = await readCatalog(storage, connection.companyId)
  if (null === catalog || !isOwnSku(catalog, marketplaceSku)) {
    return null
  }

  try {
    return await fetchSkuSales(
      connection.token,
      connection.companyId,
      marketplaceSku,
    )
  } catch {
    return null
  }
}

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
  if (CATALOG_ALARM !== alarm.name) {
    return
  }

  void refreshCatalogIfStale()
})

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
