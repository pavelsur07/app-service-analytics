import type { SkuSalesSummaryResponse } from '../api/client'
import { findAnchor } from '../ozon/anchor'
import { ozonProductIdFromUrl } from '../ozon/productUrl'
import { removePanel, renderPanel } from '../overlay/panel'
import { parseSalesResponse, salesRequest } from '../shared/salesRequest'

/**
 * Оверлей на карточке товара Ozon.
 *
 * Сам ничего не решает и ничего не знает: достаёт артикул из адреса,
 * спрашивает service worker и рисует, если тот ответил. Проверка
 * «мой ли товар», токен и хранилище остались там — на чужой странице
 * чем меньше нашего кода знает о секретах, тем лучше.
 *
 * Сеть здесь невозможна и не нужна: content-script живёт в origin
 * страницы, и его fetch подчиняется CORS (см. shared/salesRequest.ts).
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
 * Открытый вопрос спайка: меняется ли адрес при смене размера/цвета.
 * Если нет — сюда понадобится ещё один наблюдатель, более мелкий.
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

  // Спрашиваем до ожидания якоря: на чужом товаре ответ придёт сразу
  // и пустой, и незачем десять секунд ждать разметку ради молчания.
  const summary = await requestSales(marketplaceSku)
  if (null === summary) {
    return
  }

  // Только после гидратации: карточка приходит с сервера уже отрисованной,
  // и Vue сверяет её с собственным деревом. Наш узел, вставленный до этого
  // момента, — лишний ребёнок в разметке: Vue сообщает hydration mismatch
  // и перерисовывает поддерево, снося панель заодно. Мы при этом ломаем
  // чужую страницу, что само по себе недопустимо.
  await pageSettled()

  const anchor = await waitForAnchor()
  if (null === anchor) {
    reportAnchorMissing(marketplaceSku)

    return
  }

  renderPanel(anchor, summary)
  renderedForSku = marketplaceSku
  keepPanelAlive(anchor, summary, marketplaceSku)
}

/**
 * Ждём полной загрузки и один кадр сверх неё. Точного события «Vue
 * закончил гидратацию» страница не публикует, а load + кадр на практике
 * наступают позже: гидратация запускается из скриптов, загруженных
 * к этому моменту.
 *
 * ponytail: эвристика, а не гарантия. Если однажды окажется мало —
 * следующий шаг не увеличивать задержку, а дождаться исчезновения
 * их маркера гидратации; но выяснять его состав до первой поломки
 * незачем.
 */
function pageSettled(): Promise<void> {
  return new Promise<void>((resolve) => {
    const afterFrame = (): void => {
      requestAnimationFrame(() => {
        resolve()
      })
    }

    if ('complete' === document.readyState) {
      afterFrame()

      return
    }

    window.addEventListener('load', afterFrame, { once: true })
  })
}

/**
 * Сторож: их перерисовка может снести панель и после гидратации.
 * Возвращаем её на место, но не бесконечно — иначе при постоянном
 * ре-рендере мы бы дрались с их приложением в цикле, и проиграли бы
 * оба. Исчерпали попытки — молчим, страница клиента важнее панели.
 */
const MAX_REINSERTS = 5

function keepPanelAlive(
  anchor: Element,
  summary: SkuSalesSummaryResponse,
  marketplaceSku: string,
): void {
  let left = MAX_REINSERTS

  const observer = new MutationObserver(() => {
    if (marketplaceSku !== renderedForSku) {
      observer.disconnect()

      return
    }
    if (null !== document.getElementById('conwix-overlay')) {
      return
    }

    if (left <= 0) {
      observer.disconnect()

      return
    }
    left -= 1

    // Якорь мог быть заменён вместе с поддеревом — ищем заново.
    const current = findAnchor(document).element ?? anchor
    if (current.isConnected) {
      renderPanel(current, summary)
    }
  })

  observer.observe(document.body, { childList: true, subtree: true })
}

async function requestSales(marketplaceSku: string) {
  try {
    return parseSalesResponse(
      await chrome.runtime.sendMessage(salesRequest(marketplaceSku)),
    )
  } catch {
    // Service worker перезапускается или расширение обновляют — молчим.
    return null
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
 * с эндпоинтом приёма (этап 3).
 */
function reportAnchorMissing(marketplaceSku: string): void {
  console.warn('[conwix] якорь на карточке не найден', {
    marketplaceSku,
    url: location.href,
  })
}
