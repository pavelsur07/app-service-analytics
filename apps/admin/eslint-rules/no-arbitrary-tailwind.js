// Произвольные значения Tailwind — главный способ превратить проект
// в свалку: через полгода девять оттенков синего, ни один не назван.
// Ловится не одна форма записи, а все — правило, пропускающее bg-[#fff],
// обнаружится через полгода на двадцати местах.
//
// Осознанное исключение оформляется так:
//   // eslint-disable-next-line local/no-arbitrary-tailwind -- причина
// Причина обязательна по договорённости; проверять её механически
// нечем, но без неё исключение неотличимо от забытого подавления.

// Что считается произвольным:
//   утилита со значением в скобках   text-[13px]  bg-[#3a7bd5]  w-[calc(...)]
//   вариант в скобках                [&>div]:flex  [@supports(...)]:grid
//   произвольное свойство            [mask-type:luminance]
// Модификатор прозрачности и важность значения не мешают: text-[13px]/5!
const ARBITRARY = /(?:^|\s)(?:[a-zA-Z0-9:_-]*\[[^\]]+\])/

// Строка похожа на список классов, а не на текст: слова через пробел,
// без знаков препинания. Иначе правило ругалось бы на обычные строки,
// в которых встретились квадратные скобки.
const CLASS_LIKE = /^[\w\s:[\]()#%./,&>~*+='"$@!-]*$/

function check(context, node, value) {
  if (typeof value !== 'string') return
  if (!ARBITRARY.test(value)) return
  if (!CLASS_LIKE.test(value)) return
  context.report({ node, messageId: 'arbitrary' })
}

export default {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Tailwind arbitrary values are forbidden: every size, colour and spacing must be a named token.',
    },
    messages: {
      arbitrary:
        'Произвольное значение Tailwind запрещено: размер, цвет и отступ берутся из токенов packages/ui (docs/patterns.md, «Запрет произвольных значений»).',
    },
    schema: [],
  },
  create(context) {
    // Проверяются строковые литералы целиком, а не только className:
    // словари вариантов в примитивах — обычные константы, и пропустить
    // их значило бы оставить дыру ровно там, где классов больше всего.
    return {
      Literal(node) {
        check(context, node, node.value)
      },
      TemplateElement(node) {
        check(context, node, node.value.cooked)
      },
    }
  },
}
