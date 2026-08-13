import { setupServer } from 'msw/node'
import { createOpenApiHttp } from 'openapi-msw'

import type { paths } from '../../src/api/schema'

/**
 * Мок-сервер, отвечающий по схеме OpenAPI (CLAUDE.md §10).
 *
 * До него тесты подменяли глобальный `fetch` и собирали `Response`
 * руками. Так можно успешно проверить ответ, которого контракт вообще
 * не допускает: тест зелёный, а с настоящим бэкендом код падает.
 *
 * `createOpenApiHttp<paths>` типизирован сгенерированной схемой —
 * несуществующий путь и ответ неверной формы не компилируются.
 * Перехват идёт на уровне сети, поэтому код под тестом зовёт настоящий
 * `fetch` и проходит весь свой путь, включая разбор ошибок.
 */
export const http = createOpenApiHttp<paths>({ baseUrl: '*' })

export const server = setupServer()
