import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter } from 'react-router'
import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import { authQueryKey } from '../../../shared/lib/authQueryKey'
import { connectionsQueryKey } from '../../../shared/lib/connectionsQueryKey'
import {
  ADD_ANOTHER_CABINET_INTENT,
  onboardingPathToAddAnotherCabinet,
} from '../../../shared/lib/onboardingIntent'
import {
  ConnectFormView,
  OnboardingStartPage,
  connectAccountFailureFromError,
  resolveOnboardingDecision,
  selectOnboardingCompany,
} from './OnboardingStartPage'

type MeCompany = components['schemas']['MeCompanyResponse']
type MeResponse = components['schemas']['MeResponse']
type ConnectionResponse = components['schemas']['ConnectionResponse']

const COMPANY_A: MeCompany = { id: 'company-a', name: 'Компания А' }
const COMPANY_B: MeCompany = { id: 'company-b', name: 'Компания Б' }

const ACTIVE_CONNECTION: ConnectionResponse = {
  id: 'connection-a',
  marketplace: 'ozon',
  externalShopId: '12345',
  state: 'active',
  createdAt: '2026-08-01T00:00:00+00:00',
  lastLoadedAt: {},
  version: 1,
}

const BROKEN_CONNECTION: ConnectionResponse = {
  ...ACTIVE_CONNECTION,
  id: 'connection-b',
  state: 'broken',
}

// Обязательное покрытие §10 — единственная содержательная логика этого
// экрана: какая компания получает форму. Параметр из адресной строки
// проверяется по членству, а не берётся на веру: адрес правит кто
// угодно, а companyId из него уходит в запрос.
describe('selectOnboardingCompany', () => {
  it('выбирает единственную компанию пользователя без параметра', () => {
    expect(selectOnboardingCompany([COMPANY_A], null)).toEqual(COMPANY_A)
  })

  it('не решает за пользователя, когда компаний несколько и параметра нет', () => {
    expect(
      selectOnboardingCompany([COMPANY_A, COMPANY_B], null),
    ).toBeUndefined()
  })

  it('выбирает компанию по параметру, когда пользователь в ней состоит', () => {
    expect(
      selectOnboardingCompany([COMPANY_A, COMPANY_B], COMPANY_B.id),
    ).toEqual(COMPANY_B)
  })

  it('игнорирует параметр компании, в которой пользователь не состоит', () => {
    // Без этой проверки участник одной компании мог бы вписать в адрес
    // чужой companyId, и запрос ушёл бы с ним.
    expect(
      selectOnboardingCompany([COMPANY_A], 'not-a-member-here'),
    ).toBeUndefined()
  })

  it('без компаний возвращает undefined без обращения к массиву', () => {
    expect(selectOnboardingCompany([], null)).toBeUndefined()
  })
})

// Обязательное покрытие §10 — единственная содержательная логика после
// выбора компании: показывать форму только той, у которой подключений
// нет вовсе. Симметрично resolveCompanyGate (CompanyLayout.test.tsx) —
// тот же приём, вынесено из JSX в чистую функцию.
describe('resolveOnboardingDecision', () => {
  it('не решает, пока список подключений не прочитан', () => {
    expect(
      resolveOnboardingDecision(COMPANY_A.id, { status: 'pending' }, null),
    ).toEqual({ kind: 'pending' })
  })

  it('не решает, пока список не прочитан, даже с признаком осознанного добавления', () => {
    // Признак меняет решение ПОСЛЕ чтения списка, а не сам факт «список
    // ещё не прочитан» — иначе форма мигнула бы компании, у которой
    // кабинет, возможно, уже есть, раньше ответа сети.
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        { status: 'pending' },
        ADD_ANOTHER_CABINET_INTENT,
      ),
    ).toEqual({ kind: 'pending' })
  })

  it('показывает форму компании, у которой подключений нет вовсе', () => {
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        {
          status: 'success',
          data: { connections: [] },
        },
        null,
      ),
    ).toEqual({ kind: 'form' })
  })

  it('показывает форму компании без подключений и с признаком добавления', () => {
    // Требование задачи: подключений нет вовсе → форма, независимо
    // от признака.
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        {
          status: 'success',
          data: { connections: [] },
        },
        ADD_ANOTHER_CABINET_INTENT,
      ),
    ).toEqual({ kind: 'form' })
  })

  it('ведёт на экран подключений, когда единственное подключение сломано', () => {
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        {
          status: 'success',
          data: { connections: [BROKEN_CONNECTION] },
        },
        null,
      ),
    ).toEqual({
      kind: 'connections',
      to: `/companies/${COMPANY_A.id}/connections`,
    })
  })

  it('ведёт на экран подключений и тогда, когда подключение уже активно', () => {
    // На онбординге компании с активным подключением делать нечего без
    // явного намерения — повторная заявка на тот же кабинет вернёт 409,
    // а не второе подключение.
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        {
          status: 'success',
          data: { connections: [ACTIVE_CONNECTION] },
        },
        null,
      ),
    ).toEqual({
      kind: 'connections',
      to: `/companies/${COMPANY_A.id}/connections`,
    })
  })

  it('показывает форму компании с активным подключением и признаком осознанного добавления', () => {
    // Кнопка «Подключить кабинет» на экране подключений (features/connections)
    // ведёт именно на этот адрес — с признаком, а не без него.
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        {
          status: 'success',
          data: { connections: [ACTIVE_CONNECTION] },
        },
        ADD_ANOTHER_CABINET_INTENT,
      ),
    ).toEqual({ kind: 'form' })
  })

  it('показывает форму компании со сломанным подключением и признаком осознанного добавления', () => {
    // Ровно тот тупик, который признак и должен снимать: чужой или
    // сломанный кабинет остаётся заведённым, а клиент осознанно
    // добавляет другой — не заменяет старый.
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        {
          status: 'success',
          data: { connections: [BROKEN_CONNECTION] },
        },
        ADD_ANOTHER_CABINET_INTENT,
      ),
    ).toEqual({ kind: 'form' })
  })

  it('игнорирует значение intent, не совпадающее с признаком добавления', () => {
    // Любое другое или случайное значение параметра — не согласие,
    // а шум в адресной строке: прежнее поведение (увести на подключения)
    // должно сохраниться.
    expect(
      resolveOnboardingDecision(
        COMPANY_A.id,
        {
          status: 'success',
          data: { connections: [ACTIVE_CONNECTION] },
        },
        'something-else',
      ),
    ).toEqual({
      kind: 'connections',
      to: `/companies/${COMPANY_A.id}/connections`,
    })
  })

  it('показывает форму, когда запрос списка подключений упал', () => {
    // Отказ вспомогательного запроса не должен лишать клиента единственного
    // пути подключиться: форма останется рабочей, а ошибочная повторная
    // отправка вернёт понятный 409 от бэкенда, а не молчание на экране.
    expect(
      resolveOnboardingDecision(COMPANY_A.id, { status: 'error' }, null),
    ).toEqual({ kind: 'form' })
  })

  it('кодирует companyId в адресе редиректа на экран подключений', () => {
    const rawCompanyId = 'company a/b'

    expect(
      resolveOnboardingDecision(
        rawCompanyId,
        {
          status: 'success',
          data: { connections: [ACTIVE_CONNECTION] },
        },
        null,
      ),
    ).toEqual({
      kind: 'connections',
      to: '/companies/company%20a%2Fb/connections',
    })
  })
})

// Обязательное покрытие §10 — разбор ошибок API. Тот же приём, что
// в ReplaceCredentialsForm: ApiError несёт код с площадки, любая другая
// ошибка не может обещать, что подключение не создалось.
describe('connectAccountFailureFromError', () => {
  it('нет ошибки — нечего показывать', () => {
    expect(connectAccountFailureFromError(null)).toBeNull()
  })

  it('разбирает код из ApiError', () => {
    const failure = connectAccountFailureFromError(
      new ApiError(422, 'credentials_rejected', 'backend-only detail'),
    )

    expect(failure?.title).toBe('Площадка не приняла ключ')
  })

  it('ApiError без кода получает общее сообщение', () => {
    const failure = connectAccountFailureFromError(
      new ApiError(502, null, 'backend-only detail'),
    )

    expect(failure?.title).toBe('Не удалось подключить кабинет')
  })

  it('сетевая ошибка без ответа тоже получает общее сообщение', () => {
    const failure = connectAccountFailureFromError(
      new TypeError('network detail'),
    )

    expect(failure?.title).toBe('Не удалось подключить кабинет')
  })
})

function renderView(
  overrides: Partial<Parameters<typeof ConnectFormView>[0]> = {},
) {
  return renderToStaticMarkup(
    <ConnectFormView
      companyId="company-a"
      status="idle"
      failure={null}
      onSubmit={() => undefined}
      {...overrides}
    />,
  )
}

describe('ConnectFormView', () => {
  it('собирает форму с полями подключения, а не заглушку следующего этапа', () => {
    const markup = renderView()

    expect(markup).not.toContain('Stage 4')
    expect(markup).toContain('<form')
    expect(markup).toContain('Название магазина')
    expect(markup).toContain('Client-Id')
    expect(markup).toContain('Api-Key')
    expect(markup).toContain('Подключить')
  })

  it('блокирует повторную отправку и меняет текст, пока идёт проверка', () => {
    const markup = renderView({ status: 'pending' })

    expect(markup).toContain('Проверяем ключи…')
    expect(markup).toMatch(/<button[^>]*disabled/)
  })

  it('показывает разобранную ошибку под формой, а не общий текст', () => {
    const markup = renderView({
      status: 'error',
      failure: {
        title: 'Площадка не приняла ключ',
        description: 'Проверьте, что Client-Id и Api-Key скопированы целиком.',
      },
    })

    expect(markup).toContain('role="alert"')
    expect(markup).toContain('Площадка не приняла ключ')
    expect(markup).toContain(
      'Проверьте, что Client-Id и Api-Key скопированы целиком.',
    )
  })

  it('после успеха ведёт к продажам, а не показывает нули вместо счёта', () => {
    const markup = renderView({ status: 'success' })

    expect(markup).not.toContain('<form')
    expect(markup).toContain('Кабинет подключён')
    expect(markup).toContain('Загружаем данные за текущий месяц')
    expect(markup).toContain('Перейти к продажам')
  })
})

function renderPage(
  companies: readonly MeCompany[],
  route: string,
  connections?: Record<string, readonly ConnectionResponse[]>,
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  const me: MeResponse = {
    email: 'owner@example.test',
    companies: [...companies],
  }
  queryClient.setQueryData(authQueryKey(), me)

  // Кэш подключений заполняется заранее, а не через сеть: без DOM-окружения
  // (test.environment: 'node') useQuery не запускает queryFn во время
  // синхронного renderToStaticMarkup — эффекты монтирования не выполняются.
  // Не заполнить компанию совсем — способ получить статус 'pending', как
  // и в реальном первом рендере до ответа сети.
  if (connections !== undefined) {
    for (const [companyId, companyConnections] of Object.entries(connections)) {
      queryClient.setQueryData(connectionsQueryKey(companyId), {
        connections: [...companyConnections],
      })
    }
  }

  return renderToStaticMarkup(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <OnboardingStartPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('OnboardingStartPage', () => {
  it('показывает форму подключения единственной компании без параметра', () => {
    const markup = renderPage([COMPANY_A], '/onboarding', {
      [COMPANY_A.id]: [],
    })

    expect(markup).toContain('Подключите кабинет Ozon')
  })

  it('не решает сама за участника нескольких компаний без параметра гейта', () => {
    // Без ?company= участник двух компаний попал бы в петлю с /companies
    // (комментарий у OnboardingStartPage) — здесь достаточно убедиться,
    // что форма не рисуется вслепую для случайной из них.
    const markup = renderPage([COMPANY_A, COMPANY_B], '/onboarding')

    expect(markup).not.toContain('Подключите кабинет Ozon')
  })

  it('доверяет параметру гейта только когда он указывает на компанию пользователя', () => {
    const markup = renderPage(
      [COMPANY_A, COMPANY_B],
      `/onboarding?company=${COMPANY_B.id}`,
      { [COMPANY_B.id]: [] },
    )

    expect(markup).toContain('Подключите кабинет Ozon')
  })

  it('показывает форму, когда у выбранной компании подключений нет вовсе', () => {
    const markup = renderPage([COMPANY_A], '/onboarding', {
      [COMPANY_A.id]: [],
    })

    expect(markup).toContain('Подключите кабинет Ozon')
  })

  it('не показывает форму компании с уже существующим подключением', () => {
    // Форма ведёт к 409 cabinet_already_connected: кабинет занят
    // сломанным подключением, повторная заявка на него онбординг
    // не примет. Место клиента — экран подключений, не эта форма.
    const markup = renderPage([COMPANY_A], '/onboarding', {
      [COMPANY_A.id]: [BROKEN_CONNECTION],
    })

    expect(markup).not.toContain('Подключите кабинет Ozon')
  })

  it('показывает форму компании с подключением по адресу кнопки «Подключить кабинет»', () => {
    // Тот же адрес, что строит ConnectionsPage (onboardingPathToAddAnotherCabinet):
    // компания уже не пуста, но признак в адресе явно просит форму.
    const markup = renderPage(
      [COMPANY_A],
      onboardingPathToAddAnotherCabinet(COMPANY_A.id),
      { [COMPANY_A.id]: [ACTIVE_CONNECTION] },
    )

    expect(markup).toContain('Подключите кабинет Ozon')
  })

  it('не открывает форму компании с подключением без признака в адресе', () => {
    // Голый ?company= при наличии подключений — прежнее поведение,
    // признак его не расширяет молча.
    const markup = renderPage(
      [COMPANY_A],
      `/onboarding?company=${COMPANY_A.id}`,
      { [COMPANY_A.id]: [ACTIVE_CONNECTION] },
    )

    expect(markup).not.toContain('Подключите кабинет Ozon')
  })

  it('не показывает форму, пока список подключений ещё не прочитан', () => {
    // Кэш подключений не заполнен — тот же 'pending', что и при первом
    // реальном запросе до ответа сети. Решения нет, значит и рисовать
    // нечего — ни формы, ни редиректа.
    const markup = renderPage([COMPANY_A], '/onboarding')

    expect(markup).toBe('')
  })
})
