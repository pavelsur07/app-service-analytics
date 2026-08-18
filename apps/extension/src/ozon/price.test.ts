import { describe, expect, it } from 'vitest'

import markup from './__fixtures__/product-markup-308403988.json'
import { readDisplayedPrice } from './price'

/**
 * Фикстура — настоящая разметка карточки, снятая спайком ADR-015
 * 2026-08-18. Цена в ней 1117 ₽ при цене кабинета 2537 ₽: это и есть
 * то, что видит покупатель после соинвеста Ozon, а разница между ними —
 * СПП.
 *
 * DOM подделан десятью строками, а не поднят jsdom: разбор трогает
 * ровно два вызова — `querySelectorAll` и `textContent`, — и ради них
 * заводить внешнюю зависимость не нужно (её установка к тому же
 * требует согласования, CLAUDE.md). Плата названа честно: опечатку
 * в самом селекторе такой тест не поймает, её поймает ручная проверка
 * из чек-листа публикации.
 */
const SKU = '308403988'

interface FakeScript {
  readonly type: string
  readonly text: string
}

function pageWith(...scripts: readonly FakeScript[]): ParentNode {
  const nodes = scripts.map((script) => ({ textContent: script.text }))

  return {
    querySelectorAll: (selector: string) =>
      selector.includes('ld+json')
        ? scripts
            .map((script, i) => ({ script, node: nodes[i] }))
            .filter(({ script }) => 'application/ld+json' === script.type)
            .map(({ node }) => node)
        : nodes,
  } as unknown as ParentNode
}

function ldJson(value: unknown): FakeScript {
  return { type: 'application/ld+json', text: JSON.stringify(value) }
}

describe('витринная цена из разметки карточки', () => {
  it('читает цену и валюту с настоящей карточки', () => {
    expect(readDisplayedPrice(pageWith(ldJson(markup)), SKU)).toEqual({
      ok: true,
      price: { amountMinor: 111_700, currency: 'RUB' },
    })
  })

  it('находит разметку и без объявленного типа', () => {
    // Тип вставленного скрипта — деталь сборки Nuxt, не контракт.
    const untyped = { type: 'application/json', text: JSON.stringify(markup) }

    expect(readDisplayedPrice(pageWith(untyped), SKU)).toMatchObject({
      ok: true,
    })
  })

  it('достаёт товар из @graph', () => {
    const graph = ldJson({
      '@context': 'http://schema.org',
      '@graph': [{ '@type': 'WebPage' }, markup],
    })

    expect(readDisplayedPrice(pageWith(graph), SKU)).toMatchObject({ ok: true })
  })

  it('отказывается писать цену чужой карточки', () => {
    // Редирект увёл на другой товар: записать его цену под нашим
    // артикулом означало бы завести правдоподобную ложь.
    expect(readDisplayedPrice(pageWith(ldJson(markup)), '999999999')).toEqual({
      ok: false,
      reason: 'sku-mismatch',
    })
  })

  it('разбирает копейки целочисленно', () => {
    const withKopecks = ldJson({
      ...markup,
      offers: { ...markup.offers, price: '1117.05' },
    })

    expect(readDisplayedPrice(pageWith(withKopecks), SKU)).toEqual({
      ok: true,
      price: { amountMinor: 111_705, currency: 'RUB' },
    })
  })

  it('не выдумывает валюту', () => {
    // Подставить RUB значило бы решить за площадку (ADR-004).
    const withoutCurrency = ldJson({
      ...markup,
      offers: { ...markup.offers, priceCurrency: '' },
    })

    expect(readDisplayedPrice(pageWith(withoutCurrency), SKU)).toEqual({
      ok: false,
      reason: 'price-unreadable',
    })
  })

  it('сообщает, когда разметки нет вовсе', () => {
    // Ozon перестал публиковать schema.org — это поломка на нашей
    // стороне, и она обязана быть видимой, а не выглядеть как «цены нет».
    expect(readDisplayedPrice(pageWith(), SKU)).toEqual({
      ok: false,
      reason: 'markup-missing',
    })
  })

  it('переживает скрипт с испорченным JSON', () => {
    const broken = { type: 'application/ld+json', text: '{"@type": не json' }

    expect(
      readDisplayedPrice(pageWith(broken, ldJson(markup)), SKU),
    ).toMatchObject({ ok: true })
  })
})
