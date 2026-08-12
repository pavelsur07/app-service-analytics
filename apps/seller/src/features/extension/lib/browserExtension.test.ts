import { describe, expect, it } from 'vitest'
import { recipientFor } from './browserExtension'

const ANNOUNCED = 'abcdefghijklmnopabcdefghijklmnop'
const PINNED = 'ponmlkjihgfedcbaponmlkjihgfedcba'

// Кому уходит действующий токен — решение с ценой ошибки «чужое
// расширение получило учётные данные», поэтому проверяется тестом,
// а не чтением кода.
describe('получатель токена расширения', () => {
  it('закреплённый идентификатор побеждает всё остальное', () => {
    // В том числе и то, что подсунуто в DOM: закрепление на то
    // и закрепление.
    expect(recipientFor('app.conwix.com', 'подменённый', PINNED)).toEqual({
      kind: 'pinned',
      id: PINNED,
    })
  })

  it('на боевом домене без закрепления подключение недоступно', () => {
    // Никакого «возьмём из DOM»: атрибут может перезаписать
    // content-script другого расширения на этой же странице.
    expect(recipientFor('app.conwix.com', ANNOUNCED, '')).toEqual({
      kind: 'not-configured',
    })
  })

  it('на локальном домене без закрепления доверяем DOM', () => {
    expect(recipientFor('app.conwix.localhost', ANNOUNCED, '')).toEqual({
      kind: 'discovered',
      id: ANNOUNCED,
    })
    expect(recipientFor('localhost', ANNOUNCED, '')).toEqual({
      kind: 'discovered',
      id: ANNOUNCED,
    })
  })

  it('домен, лишь заканчивающийся на localhost, локальным не считается', () => {
    // app.conwix.localhost.evil.example не должен сойти за свой
    // и получить токен.
    expect(
      recipientFor('app.conwix.localhost.evil.example', ANNOUNCED, ''),
    ).toEqual({
      kind: 'not-configured',
    })
    expect(recipientFor('notlocalhost', ANNOUNCED, '')).toEqual({
      kind: 'not-configured',
    })
  })

  it('локальный домен без установленного расширения', () => {
    expect(recipientFor('app.conwix.localhost', undefined, '')).toEqual({
      kind: 'not-installed',
    })
  })
})
