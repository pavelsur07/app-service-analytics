/**
 * Куда цепляется панель на карточке Ozon.
 *
 * ЗДЕСЬ ЖИВЁТ НЕПРОВЕРЕННОЕ ЗНАНИЕ (вопрос 1 спайка, пакет 0).
 * Значения ниже — гипотеза: страница Ozon собирается из виджетов,
 * и у их контейнеров есть атрибут `data-widget`. За классы цепляться
 * нельзя вовсе — они сгенерированы и меняются с каждым деплоем.
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
