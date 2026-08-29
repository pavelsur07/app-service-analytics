import { afterAll, afterEach, beforeAll } from 'vitest'

import { server } from './server'

/**
 * Подключается для всех тестов через `test.setupFiles` (vite.config.ts).
 *
 * `onUnhandledRequest: 'error'` — условие, а не строгость ради строгости:
 * без него запрос без хендлера уходит в настоящую сеть, и тест либо
 * повиснет, либо пройдёт на чужом ответе.
 */
beforeAll(() => {
  server.listen({ onUnhandledRequest: 'error' })
})

// Хендлеры задаёт каждый тест сам; общих на все тесты нет намеренно —
// иначе набор ответов станет глобальной фикстурой, а они запрещены
// (CLAUDE.md §9, тот же принцип и на фронтенде).
afterEach(() => {
  server.resetHandlers()
})

afterAll(() => {
  server.close()
})
