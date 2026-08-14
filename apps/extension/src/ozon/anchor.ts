/**
 * Куда цепляется панель на карточке Ozon.
 *
 * ЗДЕСЬ ЖИВЁТ ЗНАНИЕ О ЧУЖОЙ ВЁРСТКЕ (вопрос 1 спайка, пакет 0).
 * Страница Ozon собирается из виджетов, и у их контейнеров есть атрибут
 * `data-widget`. За классы цепляться нельзя вовсе — они сгенерированы
 * и меняются с каждым деплоем.
 *
 * Список проверен на живых карточках 2026-08-13 и 2026-08-14: панель
 * встаёт. Какой именно селектор сработал, не записывается — если
 * понадобится, это первое, что стоит добавить в сообщение об отсутствии
 * якоря. Знание остаётся хрупким по природе: редизайн Ozon ломает его
 * молча, и ловится это только открытой карточкой
 * (docs/operations-checklist.md, раздел про расширение).
 *
 * Список проверяется по порядку, первый найденный побеждает.
 * Не нашли ничего — панель не показывается, и об этом сообщается
 * наружу (см. onAnchorMissing в content/overlay.ts). Молчаливое
 * отсутствие панели — тот же класс отказа, что молчаливо устаревшие
 * данные: выглядит как «просто нет продаж».
 */
const ANCHOR_SELECTORS = [
  '[data-widget="webPrice"]',
  '[data-widget="webSale"]',
  '[data-widget="webAspects"]',
] as const

export interface AnchorSearch {
  readonly element: Element | null
  /** По какому селектору нашли — уезжает в сигнал о поломке. */
  readonly matchedSelector: string | null
}

export function findAnchor(root: ParentNode): AnchorSearch {
  for (const selector of ANCHOR_SELECTORS) {
    const element = root.querySelector(selector)
    if (null !== element) {
      return { element, matchedSelector: selector }
    }
  }

  return { element: null, matchedSelector: null }
}
