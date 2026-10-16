/**
 * Витринная цена с карточки Ozon — то единственное число, которого
 * не отдаёт ни один API площадки (ADR-015).
 *
 * ЗДЕСЬ ЖИВЁТ ВТОРОЕ ЗНАНИЕ О ЧУЖОЙ СТРАНИЦЕ, и оно устойчивее первого.
 * Якорь панели (ozon/anchor.ts) цепляется за `data-widget` и ломается
 * при редизайне; цена берётся из разметки schema.org, которую Ozon
 * публикует для поисковиков. Она переживает редизайн, потому что
 * существует не для людей.
 *
 * Проверено на живой карточке 2026-08-18 (спайк ADR-015): в разметке
 * лежит `offers.price` = «1117» при цене кабинета 2537 — то есть именно
 * то, что видит покупатель после соинвеста Ozon, без Ozon Карты.
 * Зачёркнутая цена и цена с картой в разметку не попадают.
 *
 * **Цена с Ozon Картой** читается отдельно (`readCardPrice`) из состояния
 * блока цены — JSON в `data-state` у `[id^="state-webPrice"]`, поле
 * `cardPrice` (образец 2026-10-16: «1 413 ₽» при `price` «1 570 ₽»,
 * ADR-039). Это внутреннее состояние их фронтенда, не контракт, поэтому
 * оно сверяется дважды: `product_id` в ссылке блока обязан совпасть
 * с артикулом карточки, а `price` — с `offers.price` разметки. Одной
 * цены мало: у соседнего товара может быть та же цена без карты
 * и другая цена с картой.
 *
 * **Артикул сверяется, а не принимается на веру.** В той же разметке
 * лежит `sku`, и если он не тот, за которым пришли, — значит Ozon
 * увёл нас редиректом на другую карточку. Записать чужую цену под
 * своим артикулом хуже, чем не записать ничего: ошибка неотличима
 * от настоящих данных.
 */

interface OzonPrice {
  /** Минорные единицы: «1117» → 111700. Дробных чисел не бывает нигде. */
  readonly amountMinor: number
  readonly currency: string
}

/** Что пошло не так — в консоль, чтобы поломка была видна. */
type OzonPriceFailure =
  'markup-missing' | 'markup-unreadable' | 'sku-mismatch' | 'price-unreadable'

export type OzonPriceResult =
  | { readonly ok: true; readonly price: OzonPrice }
  | { readonly ok: false; readonly reason: OzonPriceFailure }

/** Почему цена с картой не прочитана, хотя блок с ней на странице есть. */
type CardPriceFailure =
  | 'state-unreadable'
  | 'state-sku-mismatch'
  | 'state-price-mismatch'
  | 'card-price-unreadable'

/**
 * `price: null` — на карточке нет цены с картой (скидки по карте нет)
 * или нет блока состояния вовсе. Это обычный случай, а не поломка.
 */
export type CardPriceResult =
  | { readonly ok: true; readonly price: OzonPrice | null }
  | { readonly ok: false; readonly reason: CardPriceFailure }

/** Знак рубля в тексте цены — единственная валюта витрины Ozon сегодня. */
const RUBLE_SIGN = '₽'

/**
 * Цена с Ozon Картой. `expectedSku` и `withoutCard` — артикул карточки
 * и уже прочитанная цена из разметки: по ним выбирается и проверяется
 * блок состояния именно этого товара.
 */
export function readCardPrice(
  root: ParentNode,
  expectedSku: string,
  withoutCard: OzonPrice,
): CardPriceResult {
  const holders = [...root.querySelectorAll('[id^="state-webPrice"]')]
  if (0 === holders.length) {
    // Блока нет — на карточке нет и цены с картой.
    return { ok: true, price: null }
  }

  const states = holders
    .map((holder) => parseState(holder.getAttribute('data-state')))
    .filter((state) => null !== state)
  if (0 === states.length) {
    return { ok: false, reason: 'state-unreadable' }
  }

  // Блоков цены на странице бывает несколько (подборки, «похожие»):
  // берётся тот, чья ссылка ведёт на наш артикул, а не первый.
  const fields = states.find((state) => productIdOf(state) === expectedSku)
  if (undefined === fields) {
    return { ok: false, reason: 'state-sku-mismatch' }
  }

  if ('RUB' !== withoutCard.currency) {
    // Знак в тексте — рубль; для другой валюты сопоставить его не с чем.
    return { ok: false, reason: 'card-price-unreadable' }
  }

  // Вторая сверка: цена без карты из состояния совпадает с разметкой.
  if (rublesToMinor(fields.price) !== withoutCard.amountMinor) {
    return { ok: false, reason: 'state-price-mismatch' }
  }

  const raw = fields.cardPrice
  if (undefined === raw || null === raw || '' === raw) {
    return { ok: true, price: null }
  }

  const amountMinor = rublesToMinor(raw)
  if (null === amountMinor) {
    return { ok: false, reason: 'card-price-unreadable' }
  }

  return { ok: true, price: { amountMinor, currency: withoutCard.currency } }
}

function parseState(raw: string | null): Record<string, unknown> | null {
  if (null === raw) {
    return null
  }

  let state: unknown
  try {
    state = JSON.parse(raw)
  } catch {
    return null
  }

  return null !== state && 'object' === typeof state && !Array.isArray(state)
    ? (state as Record<string, unknown>)
    : null
}

/** «/modal/pdpListOfBanks?product_id=308520421» → «308520421». */
function productIdOf(state: Record<string, unknown>): string | null {
  const link = state.link
  if ('string' !== typeof link) {
    return null
  }

  return /[?&]product_id=(\d+)/.exec(link)?.[1] ?? null
}

/**
 * «1 413 ₽» или «1 413,50 ₽» → минорные единицы. Пробелы любые: Ozon
 * разделяет разряды неразрывными и узкими пробелами. Без знака рубля
 * текст не принимается — иначе число из чужого поля сошло бы за цену.
 */
function rublesToMinor(value: unknown): number | null {
  if ('string' !== typeof value) {
    return null
  }

  const compact = value.replace(/\s/g, '')
  if (!compact.endsWith(RUBLE_SIGN)) {
    return null
  }

  return toMinor(compact.slice(0, -RUBLE_SIGN.length).replace(',', '.'))
}

export function readDisplayedPrice(
  root: ParentNode,
  expectedSku: string,
): OzonPriceResult {
  const product = findProductMarkup(root)
  if (null === product) {
    return { ok: false, reason: 'markup-missing' }
  }

  const sku = product.sku
  if ('string' !== typeof sku && 'number' !== typeof sku) {
    return { ok: false, reason: 'markup-unreadable' }
  }
  if (String(sku) !== expectedSku) {
    // Редирект увёл на другую карточку — записать её цену под нашим
    // артикулом означало бы завести правдоподобную ложь.
    return { ok: false, reason: 'sku-mismatch' }
  }

  const offers = product.offers
  if (null === offers || 'object' !== typeof offers) {
    return { ok: false, reason: 'markup-unreadable' }
  }

  const offer = offers as Record<string, unknown>
  const amountMinor = toMinor(offer.price)
  const currency = offer.priceCurrency

  if (null === amountMinor) {
    return { ok: false, reason: 'price-unreadable' }
  }
  if ('string' !== typeof currency || !/^[A-Z]{3}$/.test(currency)) {
    // Валюта обязательна и умолчания не имеет (ADR-004): подставить
    // RUB значило бы решить за площадку.
    return { ok: false, reason: 'price-unreadable' }
  }

  return { ok: true, price: { amountMinor, currency } }
}

interface ProductMarkup {
  readonly sku?: unknown
  readonly offers?: unknown
}

/**
 * Разметка ищется двумя способами: сначала по объявленному типу
 * `application/ld+json`, потом перебором скриптов по содержимому.
 * Второй нужен не «на всякий случай»: Ozon собирает страницу Nuxt'ом,
 * и тип у вставленного скрипта зависит от того, как именно фреймворк
 * его отрендерил, — а это деталь их сборки, не контракт.
 */
function findProductMarkup(root: ParentNode): ProductMarkup | null {
  const scripts = [
    ...root.querySelectorAll('script[type="application/ld+json"]'),
    ...root.querySelectorAll('script'),
  ]

  for (const script of scripts) {
    const text = script.textContent
    if (null === text || !text.includes('"@type"')) {
      continue
    }

    let parsed: unknown
    try {
      parsed = JSON.parse(text)
    } catch {
      continue
    }

    const product = asProduct(parsed)
    if (null !== product) {
      return product
    }
  }

  return null
}

function asProduct(value: unknown): ProductMarkup | null {
  if (null === value || 'object' !== typeof value) {
    return null
  }

  const candidate = value as Record<string, unknown>
  if ('Product' === candidate['@type']) {
    return candidate as ProductMarkup
  }

  // Разметка бывает массивом сущностей или графом @graph — берём
  // из него товар, а не первый попавшийся элемент.
  const nested = Array.isArray(value)
    ? value
    : Array.isArray(candidate['@graph'])
      ? candidate['@graph']
      : null

  if (null === nested) {
    return null
  }

  for (const entry of nested) {
    const product = asProduct(entry)
    if (null !== product) {
      return product
    }
  }

  return null
}

/**
 * «1117» или «1117.50» → минорные единицы. Целочисленный разбор,
 * без промежуточного числа с плавающей точкой: копейки на нём
 * размываются, и запрет ADR-004 распространяется на промежуточные
 * значения тоже.
 */
function toMinor(value: unknown): number | null {
  if ('string' !== typeof value || !/^\d+(\.\d{1,2})?$/.test(value)) {
    return null
  }

  const [whole = '0', fraction = ''] = value.split('.')

  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
}
