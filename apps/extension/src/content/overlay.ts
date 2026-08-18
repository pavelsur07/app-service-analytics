import { findAnchor } from '../ozon/anchor'
import { readDisplayedPrice } from '../ozon/price'
import { ozonProductIdFromUrl } from '../ozon/productUrl'
import {
  removePanel,
  renderPanel,
  type TrackingOutcome,
} from '../overlay/panel'
import {
  observationMessage,
  overlayRequest,
  parseOverlayData,
  parseTrackingResult,
  setTrackingRequest,
  type OverlayData,
} from '../shared/overlayRequest'

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
 * Смена размера и цвета этого наблюдателя не касается — проверено
 * 2026-08-14: размеры одного товара на Ozon это отдельные товары
 * с отдельными адресами, и переход между ними идёт полной перезагрузкой
 * страницы (внедрённый в консоль перехватчик pushState её не пережил).
 * Content-script в этом случае запускается заново сам.
 *
 * Наблюдатель остаётся ради мягких переходов, которые у Ozon тоже есть:
 * из выдачи и из рекомендаций на карточку.
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
  const data = await requestOverlayData(marketplaceSku)
  if (null === data || isStale(marketplaceSku)) {
    return
  }

  // Фоновый визит: окно открыл service worker ради цены. Панель здесь
  // не нужна — её никто не увидит, а гидратацию чужой страницы она
  // всё равно тревожит.
  if (data.capture) {
    await sendObservation(marketplaceSku)

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
  if (isStale(marketplaceSku)) {
    return
  }

  const toggleTracking = trackingToggle(marketplaceSku)
  renderPanel(anchor, data, toggleTracking)
  renderedForSku = marketplaceSku
  keepPanelAlive(anchor, data, marketplaceSku, toggleTracking)
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
  data: OverlayData,
  marketplaceSku: string,
  toggleTracking: (tracked: boolean) => Promise<TrackingOutcome>,
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
      // Панель перерисовывается из тех же данных, что и в первый раз:
      // состояние кнопки, изменённое кликом, при этом теряется. Сторож
      // срабатывает, когда чужой ре-рендер снёс наш узел, — событие
      // редкое, и переспрашивать сервер ради него дороже, чем показать
      // исходное состояние.
      renderPanel(current, data, toggleTracking)
    }
  })

  observer.observe(document.body, { childList: true, subtree: true })
}

async function requestOverlayData(
  marketplaceSku: string,
): Promise<OverlayData | null> {
  try {
    return parseOverlayData(
      await chrome.runtime.sendMessage(overlayRequest(marketplaceSku)),
    )
  } catch {
    // Service worker перезапускается или расширение обновляют — молчим.
    return null
  }
}

/**
 * Снимок витринной цены на фоновом визите.
 *
 * Ждём загрузки страницы: разметка schema.org приезжает с сервера,
 * но до `load` её может ещё не быть в документе. Дальше — один разбор
 * и одно сообщение; ответа не ждём, окно закроет service worker.
 *
 * Не нашли цену — предупреждение в консоль, как и с ненайденным
 * якорем. Молчаливый пропуск неотличим от «расширение не запускалось»,
 * а поломка здесь означает, что Ozon перестал публиковать разметку,
 * и узнать об этом надо сразу.
 */
async function sendObservation(marketplaceSku: string): Promise<void> {
  await pageSettled()

  const result = readDisplayedPrice(document, marketplaceSku)
  if (!result.ok) {
    console.warn('[conwix] витринная цена не прочитана', {
      marketplaceSku,
      reason: result.reason,
      url: location.href,
    })

    return
  }

  try {
    await chrome.runtime.sendMessage(
      observationMessage(
        marketplaceSku,
        new Date().toISOString(),
        result.price.amountMinor,
        result.price.currency,
      ),
    )
  } catch {
    // Service worker перезапускается или расширение обновляют.
    // Следующий цикл снимет заново.
  }
}

/**
 * Клик по кнопке уезжает в service worker: сеть и токен живут там.
 * Не ответил — состояние остаётся прежним, и об этом говорится прямо,
 * а не молча: кнопка, которая переключилась, ничего не изменив, —
 * тот же класс отказа, что молчаливо устаревшие данные.
 */
function trackingToggle(
  marketplaceSku: string,
): (tracked: boolean) => Promise<TrackingOutcome> {
  return async (tracked: boolean): Promise<TrackingOutcome> => {
    try {
      const result = parseTrackingResult(
        await chrome.runtime.sendMessage(
          setTrackingRequest(marketplaceSku, tracked),
        ),
      )
      if (null !== result) {
        return result
      }
    } catch {
      // Service worker перезапускается или расширение обновляют.
    }

    return { tracked: !tracked, error: 'Не удалось связаться с Conwix.' }
  }
}

/**
 * Пока мы ждали сеть, гидратацию и якорь, человек мог уйти на соседний
 * товар: переход в SPA не перезапускает скрипт, а обработка асинхронна.
 * Без этой сверки более медленный запрос дорисовал бы поверх новой
 * карточки числа предыдущей — отказ, который ничего не роняет
 * и потому особенно опасен.
 */
function isStale(marketplaceSku: string): boolean {
  return marketplaceSku !== ozonProductIdFromUrl(location.href)
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
