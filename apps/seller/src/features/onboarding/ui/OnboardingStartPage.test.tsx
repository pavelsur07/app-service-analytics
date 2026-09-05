import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter } from 'react-router'
import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import { authQueryKey } from '../../../shared/lib/authQueryKey'
import {
  ConnectFormView,
  OnboardingStartPage,
  connectAccountFailureFromError,
  selectOnboardingCompany,
} from './OnboardingStartPage'

type MeCompany = components['schemas']['MeCompanyResponse']
type MeResponse = components['schemas']['MeResponse']

const COMPANY_A: MeCompany = { id: 'company-a', name: 'Компания А' }
const COMPANY_B: MeCompany = { id: 'company-b', name: 'Компания Б' }

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

function renderPage(companies: readonly MeCompany[], route: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  const me: MeResponse = {
    email: 'owner@example.test',
    companies: [...companies],
  }
  queryClient.setQueryData(authQueryKey(), me)

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
    const markup = renderPage([COMPANY_A], '/onboarding')

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
    )

    expect(markup).toContain('Подключите кабинет Ozon')
  })
})
