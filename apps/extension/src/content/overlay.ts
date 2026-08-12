import { fetchSkuSales, isUnauthorized } from '../api/client'
import { findAnchor } from '../ozon/anchor'
import { ozonProductIdFromUrl } from '../ozon/productUrl'
import { removePanel, renderPanel } from '../overlay/panel'
import { isOwnSku, readCatalog } from '../shared/catalog'
import { browserStorage, readConnection } from '../shared/connection'

/**
 * Оверлей на карточке товара Ozon.
 *
 * Порядок намеренно такой: сначала адрес, потом локальный каталог,
 * и только потом сеть. Чужая карточка отсеивается, не покидая машины
 * клиента, — сервер о ней не узнаёт.
 */

// Карточка рисуется асинхронно: скрипт на document_start застаёт пустую
// страницу. Ждём появления якоря, но не бесконечно — иначе на странице
// без него наблюдатель провисит всю сессию.
const ANCHOR_TIMEOUT_MS = 10_000

let renderedForSku: string | null = null

void start()

async function start(): Promise<void> {
  await handleLocation()
  watchLocationChanges()
}

/**
 * Смена адреса без перезагрузки: Ozon — SPA, и переход на соседний
 * товар не перезапускает content-script. Без этого панель осталась бы
 * висеть с числами предыдущего товара — отказ, который ничего
 * не роняет и потому особенно опасен.
 *
 * Открытый вопрос спайка (пункт 2): меняется ли адрес при смене
 * размера/цвета. Если нет — сюда понадобится ещё один наблюдатель,
 * более мелкий, и это отдельная работа.
 */
function watchLocationChanges(): void {
  let previous = location.href

  const check = (): void => {
    if (location.href === previous) {
      return
    }
    previous = location.href
    void handleLocation()
  }

  // navigation API есть не везде, поэтому подстраховываемся наблюдением
  // за DOM: любой переход внутри SPA его перерисовывает.
  window.addEventListener('popstate', check)
  new MutationObserver(check).observe(document.body, {
    childList: true,
    subtree: true,
  })
}

async function handleLocation(): Promise<void> {
  const marketplaceSku = ozonProductIdFromUrl(location.href)

  if (null === marketplaceSku) {
    forget()

    return
  }
  if (marketplaceSku === renderedForSku) {
    return
  }
  forget()

  const storage = browserStorage()
  const connection = await readConnection(storage)
  if (null === connection) {
    return
  }

  const catalog = await readCatalog(storage, connection.companyId)
  // Каталога ещё нет — молчим. Объявлять чужим то, про что мы просто
  // не знаем, хуже, чем не показать ничего.
  if (null === catalog || !isOwnSku(catalog, marketplaceSku)) {
    return
  }

  const anchor = await waitForAnchor()
  if (null === anchor) {
    reportAnchorMissing(marketplaceSku)

    return
  }

  try {
    const summary = await fetchSkuSales(
      connection.token,
      connection.companyId,
      marketplaceSku,
    )
    renderPanel(anchor, summary)
    renderedForSku = marketplaceSku
  } catch (error) {
    // 401 — токен истёк или отозван: панель не показываем, состояние
    // подключения чинит popup. Прочее (сеть, 5xx) — тоже молчим:
    // навязчивая ошибка поверх чужой карточки хуже её отсутствия.
    if (!isUnauthorized(error)) {
      return
    }
  }
}

function forget(): void {
  renderedForSku = null
  removePanel()
}

function waitForAnchor(): Promise<Element | null> {
  const immediate = findAnchor(document)
  if (null !== immediate.element) {
    return Promise.resolve(immediate.element)
  }

  return new Promise<Element | null>((resolve) => {
    const observer = new MutationObserver(() => {
      const found = findAnchor(document)
      if (null !== found.element) {
        observer.disconnect()
        clearTimeout(timer)
        resolve(found.element)
      }
    })

    const timer = setTimeout(() => {
      observer.disconnect()
      resolve(null)
    }, ANCHOR_TIMEOUT_MS)

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
    })
  })
}

/**
 * Якорь не найден — это поломка на нашей стороне, а не отсутствие
 * данных, и она обязана быть видимой. Ozon переверстал карточку, и мы
 * узнаём об этом от расширения, а не через три недели от клиента.
 *
 * ponytail: пока в консоль. Отправка события на бэкенд — вместе
 * с эндпоинтом приёма (этап 3), отдельно ради одного события
 * его заводить незачем.
 */
function reportAnchorMissing(marketplaceSku: string): void {
  console.warn('[conwix] якорь на карточке не найден', {
    marketplaceSku,
    url: location.href,
  })
}
