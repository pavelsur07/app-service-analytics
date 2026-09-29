import { afterAll, afterEach, beforeAll, vi } from 'vitest'

import { server } from './server'

/**
 * Подключается для всех тестов через `test.setupFiles` (vite.config.ts).
 *
 * `onUnhandledRequest: 'error'` — обязательное условие, а не строгость
 * ради строгости: без него запрос, для которого забыли хендлер, уходит
 * в настоящую сеть. Тест либо повиснет, либо, что хуже, пройдёт
 * на чужом ответе.
 */
beforeAll(() => {
  server.listen({ onUnhandledRequest: 'error' })
})

// Хендлеры задаёт каждый тест сам; общих на все тесты нет намеренно —
// иначе набор ответов станет глобальной фикстурой, а они запрещены
// (CLAUDE.md §9, тот же принцип и на фронтенде).
afterEach(() => {
  server.resetHandlers()
  // Файлы делят воркер (isolate: false в vite.config.ts): замороженные
  // таймеры одного файла иначе достались бы следующему.
  vi.useRealTimers()
})

afterAll(() => {
  server.close()
})
