/** SKU Ozon — положительное целое; артикул продавца сюда не подходит. */
export function ozonProductUrl(sku: string | null | undefined): string | null {
  if (!sku || sku !== sku.trim() || !/^[1-9][0-9]*$/.test(sku)) {
    return null
  }

  return `https://www.ozon.ru/product/${sku}/`
}
