import { fetchSkuPage } from '../api/client'

import type { Storage } from './connection'

/**
 * Артикулы компании, выгруженные целиком и хранимые локально.
 *
 * Смысл именно в локальности: вопрос «моя ли это карточка» решается
 * здесь, у клиента, и мы не узнаём, какие чужие карточки он открывает.
 * Список отслеживаемых конкурентов нам не принадлежит.
 */
export interface Catalog {
  readonly companyId: string
  readonly skus: readonly string[]
  /** Момент выгрузки, ISO. По нему решается, не пора ли обновить. */
  readonly fetchedAt: string
}

/**
 * Ключ с companyId внутри (CLAUDE.md §7). Переподключение к другой
 * компании стирает хранилище целиком (writeConnection), так что двух
 * каталогов одновременно не бывает — но ключ всё равно именованный:
 * если очистку когда-нибудь ослабят, чужой каталог не притворится своим.
 */
function catalogKey(companyId: string): string {
  return `catalog:${companyId}`
}

// 200 — максимум страницы у эндпоинта; меньше просить незачем,
// список выгружается целиком и редко.
const PAGE_SIZE = 200

// Потолок на число страниц: страховка от бесконечного цикла, если
// сервер начнёт отдавать nextCursor, не двигая выборку. 200 × 500 —
// сто тысяч артикулов, заведомо больше любого реального каталога.
const MAX_PAGES = 500

export async function readCatalog(
  storage: Storage,
  companyId: string,
): Promise<Catalog | null> {
  const stored = await storage.get([catalogKey(companyId)])

  return parseCatalog(stored[catalogKey(companyId)], companyId)
}

/**
 * Выгружает список постранично и кладёт целиком. Частичный результат
 * не сохраняется: половина каталога хуже его отсутствия — она молча
 * объявляет часть своих товаров чужими.
 */
export async function refreshCatalog(
  storage: Storage,
  token: string,
  companyId: string,
  now: Date,
): Promise<Catalog> {
  const skus: string[] = []
  let cursor: string | null = null
  let complete = false

  for (let page = 0; page < MAX_PAGES; page += 1) {
    const response = await fetchSkuPage(token, companyId, cursor, PAGE_SIZE)
    skus.push(...response.items)

    // nextCursor в схеме необязателен: его отсутствие и явный null
    // означают одно — страниц больше нет.
    cursor = response.nextCursor ?? null
    if (null === cursor) {
      complete = true
      break
    }
  }

  // Потолок страниц исчерпан, а сервер обещает продолжение. Сохранить
  // то, что успели, было бы худшим исходом: неполный список пролежал бы
  // сутки как свежий, и оверлей молчал бы на своих же товарах, притом
  // что в API они есть. Лучше остаться без каталога — тогда молчит всё
  // и одинаково, а вызывающий сохранит прежний, если он был.
  if (!complete) {
    throw new Error(
      `Каталог артикулов не выгружен целиком: исчерпан потолок в ${MAX_PAGES} страниц.`,
    )
  }

  const catalog: Catalog = {
    companyId,
    skus,
    fetchedAt: now.toISOString(),
  }
  await storage.set({ [catalogKey(companyId)]: catalog })

  return catalog
}

export function isStale(
  catalog: Catalog,
  now: Date,
  maxAgeMs: number,
): boolean {
  const fetchedAt = Date.parse(catalog.fetchedAt)

  // Неразбираемая отметка — считаем устаревшим: обновить лишний раз
  // дешевле, чем навсегда застрять на испорченной записи.
  if (Number.isNaN(fetchedAt)) {
    return true
  }

  return now.getTime() - fetchedAt >= maxAgeMs
}

export function isOwnSku(catalog: Catalog, marketplaceSku: string): boolean {
  // ponytail: линейный поиск. На одну карточку приходится одна сверка,
  // и даже десять тысяч артикулов это доли миллисекунды. Понадобится
  // сверять пачками — построить Set один раз на загрузку страницы.
  return catalog.skus.includes(marketplaceSku)
}

function parseCatalog(
  value: unknown,
  expectedCompanyId: string,
): Catalog | null {
  if (null === value || 'object' !== typeof value) {
    return null
  }

  const candidate = value as Record<string, unknown>
  const { companyId, skus, fetchedAt } = candidate

  if ('string' !== typeof companyId || companyId !== expectedCompanyId) {
    return null
  }
  if ('string' !== typeof fetchedAt) {
    return null
  }
  if (!Array.isArray(skus) || !skus.every((sku) => 'string' === typeof sku)) {
    return null
  }

  return { companyId, skus: skus as string[], fetchedAt }
}
