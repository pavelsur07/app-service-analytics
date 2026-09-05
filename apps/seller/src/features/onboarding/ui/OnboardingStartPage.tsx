import { useState } from 'react'
import { CircleCheck, CircleX } from 'lucide-react'
import { Navigate, useSearchParams } from 'react-router'

import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { connectAccountFailure } from '../lib/connectAccountError'
import type { ConnectAccountFailure } from '../lib/connectAccountError'
import { useConnectAccount } from '../model/useConnectAccount'
import type { ConnectAccountInput } from '../model/useConnectAccount'
import { useCurrentUser } from '../../../shared/model/useCurrentUser'

type MeCompany = components['schemas']['MeCompanyResponse']

/**
 * Подключение кабинета — обязательный шаг, а не приглашение (ADR-021):
 * компания без активного подключения не имеет содержательного экрана,
 * и пустой дашборд с нулями не показывается никогда.
 *
 * companyId в пути /onboarding нет, а company-scoped запрос без него
 * невозможен. Источников два, и порядок между ними важен:
 *
 * 1. параметр ?company= — его ставит гейт CompanyLayout, который уже
 *    знает, какая именно компания осталась без подключения;
 * 2. единственная компания пользователя — после саморегистрации она
 *    ровно одна.
 *
 * Без первого источника участник двух компаний попадал бы в петлю:
 * гейт уводит сюда, здесь компаний больше одной, значит на /companies,
 * оттуда снова в ту же компанию и снова на гейт.
 */
export function OnboardingStartPage() {
  const currentUser = useCurrentUser()
  const [searchParams] = useSearchParams()

  if (currentUser.status !== 'success') {
    return null
  }

  const company = selectOnboardingCompany(
    currentUser.data.companies,
    searchParams.get('company'),
  )

  if (company === undefined) {
    return <Navigate to="/companies" replace />
  }

  return <ConnectForm companyId={company.id} />
}

/**
 * Единственная содержательная логика этого экрана: какая компания
 * получает форму, а какая уходит на /companies. Вынесена и экспортирована
 * отдельно от рендера, чтобы membership-проверка параметра — параметр
 * правит адресная строка, а не сервер — проверялась без монтирования
 * компонента.
 */
export function selectOnboardingCompany(
  companies: readonly MeCompany[],
  requestedCompanyId: string | null,
): MeCompany | undefined {
  if (requestedCompanyId !== null) {
    return companies.find((candidate) => candidate.id === requestedCompanyId)
  }

  return companies.length === 1 ? companies[0] : undefined
}

/**
 * То же сопоставление ошибки с сообщением, что в ReplaceCredentialsForm:
 * ApiError несёт код с площадки, любая другая ошибка (сеть упала до
 * ответа) не может обещать, что подключение не создалось. Вынесена
 * из JSX-ветвления, чтобы разбор проверялся без рендера.
 */
export function connectAccountFailureFromError(
  error: unknown,
): ConnectAccountFailure | null {
  if (error instanceof ApiError) {
    return connectAccountFailure(error.code)
  }

  if (error instanceof Error) {
    return connectAccountFailure(null)
  }

  return null
}

function ConnectForm({ companyId }: { companyId: string }) {
  const mutation = useConnectAccount(companyId)
  const failure = connectAccountFailureFromError(mutation.error)

  return (
    <ConnectFormView
      companyId={companyId}
      status={mutation.status}
      failure={failure}
      onSubmit={(input) => {
        mutation.mutate(input)
      }}
    />
  )
}

export interface ConnectFormViewProps {
  companyId: string
  status: 'idle' | 'pending' | 'error' | 'success'
  failure: ConnectAccountFailure | null
  onSubmit: (input: ConnectAccountInput) => void
}

/**
 * Презентационная часть формы: принимает состояние мутации явно через
 * пропсы вместо того, чтобы читать хук самой. Рендер и разбор ошибки
 * проверяются без сети — сеть остаётся предметом отдельного контракт-теста
 * `useConnectAccount.test.ts`.
 */
export function ConnectFormView({
  companyId,
  status,
  failure,
  onSubmit,
}: ConnectFormViewProps) {
  const [name, setName] = useState('')
  const [clientId, setClientId] = useState('')
  const [apiKey, setApiKey] = useState('')

  if (status === 'success') {
    return (
      <div className="flex min-h-screen items-center justify-center p-6">
        <div className="w-full max-w-sm">
          <Card>
            <StatusPanel
              icon={<CircleCheck aria-hidden="true" size={20} />}
              title="Кабинет подключён"
              description="Загружаем данные за текущий месяц. Это занимает несколько минут — экраны наполнятся сами."
              tone="accent"
              action={
                <Button
                  type="button"
                  variant="primary"
                  size="compact"
                  onClick={() => {
                    window.location.assign(`/companies/${companyId}/sales`)
                  }}
                >
                  Перейти к продажам
                </Button>
              }
            />
          </Card>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <form
            className="flex flex-col gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              onSubmit({ name, clientId, apiKey })
            }}
          >
            <h1 className="text-lg font-semibold">Подключите кабинет Ozon</h1>
            <p className="text-sm text-text-muted">
              Ключи проверяются сразу — сохраняем только рабочие.
            </p>

            <Input
              label="Название магазина"
              value={name}
              onChange={(event) => {
                setName(event.target.value)
              }}
            />
            <Input
              label="Client-Id"
              value={clientId}
              onChange={(event) => {
                setClientId(event.target.value)
              }}
            />
            <Input
              label="Api-Key"
              type="password"
              value={apiKey}
              onChange={(event) => {
                setApiKey(event.target.value)
              }}
            />

            {failure !== null && (
              <StatusPanel
                icon={<CircleX aria-hidden="true" size={20} />}
                title={failure.title}
                description={failure.description}
                role="alert"
                tone="negative"
              />
            )}

            <Button
              type="submit"
              variant="primary"
              disabled={status === 'pending'}
            >
              {status === 'pending' ? 'Проверяем ключи…' : 'Подключить'}
            </Button>
          </form>
        </Card>
      </div>
    </div>
  )
}
