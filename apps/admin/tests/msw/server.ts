import { setupServer } from 'msw/node'
import { createOpenApiHttp } from 'openapi-msw'

import type { paths } from '../../src/api/schema'

/**
 * Мок-сервер, отвечающий по схеме OpenAPI (CLAUDE.md §10).
 *
 * Подмена глобального `fetch` и собранный руками `Response` запрещены:
 * так тест успешно проходит на ответе, которого контракт не допускает —
 * зелёный тест, падающий код.
 *
 * `createOpenApiHttp<paths>` типизирован сгенерированной схемой:
 * несуществующий путь и ответ неверной формы не компилируются.
 *
 * baseUrl конкретный, а не '*': клиент ходит относительными путями
 * (`/api/...`), а в Node у них нет базы — `fetch` упал бы на разборе
 * адреса ещё до перехвата.
 */
export const http = createOpenApiHttp<paths>({ baseUrl: 'http://localhost' })

export const server = setupServer()
