// Адрес карточки Ozon → идентификатор товара.
//
// Форма адреса: /product/<слаг>-<цифры>/ — цифры в хвосте слага
// и есть идентификатор. Слаг меняется при переименовании товара,
// цифры нет, поэтому берём только их.
//
// ВНИМАНИЕ, непроверенное допущение (вопрос 3 спайка, пакет 0):
// принято, что это число совпадает с `products[].sku` из
// /v2/posting/fbo/list, по которому у нас записан marketplace_sku
// (ADR-009). Косвенно сходится — в фикстуре постингов sku это
// 9–10 цифр, столько же в хвосте адреса, — но совпадение форматов
// не доказательство. Пока не подтверждено на живой карточке,
// сверка «мой ли товар» может молча не находить своих.
const PRODUCT_PATH = /^\/product\/(?:.*-)?(\d{6,})\/?$/

export function ozonProductIdFromUrl(rawUrl: string): string | null {
  let url: URL
  try {
    url = new URL(rawUrl)
  } catch {
    return null
  }

  if (!isOzonHost(url.hostname)) {
    return null
  }

  const matched = PRODUCT_PATH.exec(url.pathname)

  return matched?.[1] ?? null
}

function isOzonHost(hostname: string): boolean {
  // www.ozon.ru и ozon.ru — один и тот же сайт; поддомены вроде
  // seller.ozon.ru это кабинет, а не карточка, и сюда попадать
  // не должны.
  return 'ozon.ru' === hostname || 'www.ozon.ru' === hostname
}
