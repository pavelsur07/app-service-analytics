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
import { useConnections } from '../../../shared/model/useConnections'
import { useCurrentUser } from '../../../shared/model/useCurrentUser'

type MeCompany = components['schemas']['MeCompanyResponse']
type ConnectionsResponse = components['schemas']['ConnectionsResponse']

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
 *
 * Форму эта страница показывает не любой определившейся компании,
 * а только той, у которой подключений нет вовсе — см.
 * `resolveOnboardingDecision`. `/onboarding` — верхнеуровневый маршрут
 * (Root.tsx), обёрнутый только в `RequireAuth`, а не в `CompanyLayout`:
 * гейт `resolveCompanyGate`, который решает то же самое в обратную
 * сторону для company-scoped экранов, сюда не дотягивается, и решение
 * приходится принимать здесь же, повторно читая тот же список.
 */
export function OnboardingStartPage() {
  const currentUser = useCurrentUser()
  const [searchParams] = useSearchParams()

  const company =
    currentUser.status === 'success'
      ? selectOnboardingCompany(
          currentUser.data.companies,
          searchParams.get('company'),
        )
      : undefined

  // useConnections вызывается безусловно, до любого раннего return.
  // currentUser.status и company меняются между рендерами (сеть ещё
  // не ответила → ответила → компания определилась), и если бы хук
  // стоял после условного return, число вызванных хуков менялось бы
  // вместе с этим состоянием — React падает («Rendered fewer hooks
  // than expected»). Тот же приём, что у ConnectFormView ниже: все
  // useState стоят до первого return ровно по этой причине.
  // companyId пуст, пока компания не определена, — enabled:false не
  // даёт запросу уйти впустую (тот же приём, что useConnections
  // в ConnectionsPage.tsx: `companyId ?? ''` с `enabled`).
  const connections = useConnections(company?.id ?? '', {
    enabled: company !== undefined,
  })

  if (currentUser.status !== 'success') {
    return null
  }

  if (company === undefined) {
    return <Navigate to="/companies" replace />
  }

  const decision = resolveOnboardingDecision(company.id, connections)

  if (decision.kind === 'pending') {
    return null
  }

  if (decision.kind === 'connections') {
    return <Navigate to={decision.to} replace />
  }

  return <ConnectForm companyId={company.id} />
}

/**
 * Минимум, который решению нужно от результата useConnections — см.
 * тот же приём в CompanyLayout.tsx (`ConnectionsQueryState`). Не
 * импортирован оттуда: `import/no-restricted-paths` (eslint.config.js)
 * запрещает импорт из `app/` кому бы то ни было, а с другой стороны,
 * features/A не импортирует из features/B — общий тип пришлось бы
 * тащить в shared/ ради одного поля, которое и так видно из схемы.
 * Реальный `UseQueryResult`, который отдаёт `useConnections`, структурно
 * этому типу соответствует — тот же приём, что и в `ConnectionGate`.
 */
export type ConnectionsQueryState =
  | { status: 'pending' }
  | { status: 'error' }
  | { status: 'success'; data: ConnectionsResponse }

export type OnboardingDecision =
  { kind: 'pending' } | { kind: 'form' } | { kind: 'connections'; to: string }

function connectionsPath(companyId: string): string {
  return `/companies/${encodeURIComponent(companyId)}/connections`
}

/**
 * Решение: показать компании форму подключения или увести её на экран
 * подключений. Вынесено из JSX в чистую функцию тем же приёмом, что
 * `resolveCompanyGate` (app/CompanyLayout.tsx) и `selectOnboardingCompany`
 * выше — «рендер не тестировать» (CLAUDE.md §9) про разметку, не про
 * решение.
 *
 * - список подключений ещё не прочитан → решения нет: показать форму
 *   сейчас значит нарисовать её компании, у которой кабинет уже есть,
 *   и тут же увести редиректом — заметный мигающий переход вместо
 *   тихого ожидания одного вспомогательного запроса;
 * - запрос списка сам упал с ошибкой → форма, как и при пустом списке.
 *   Осознанный компромисс: отказ вспомогательного запроса не должен
 *   лишать клиента единственного пути подключить кабинет — альтернатива
 *   («решения нет», как у pending) держала бы онбординг в вечном
 *   ожидании при любом транзиентном сбое. Ошибочная отправка формы
 *   компании, у которой кабинет на самом деле уже есть, вернёт понятный
 *   409 `cabinet_already_connected` от бэкенда, а не тихий тупик;
 * - подключений нет вовсе → форма. Единственный случай, когда её
 *   вообще есть смысл показывать: ни одной пары учётных данных,
 *   которую можно было бы чинить, ещё не существует;
 * - подключение есть — любое, в любом состоянии (`active`, `broken`,
 *   `revoked`) → на экран подключений этой компании, адрес несёт
 *   companyId закодированным (тот же `connectionsPath`, что у
 *   `resolveCompanyGate`, для согласованности значения). Кабинет уже
 *   занят: заявка на тот же кабинет вернёт 409 (частичный уникальный
 *   индекс держит `broken` как «не revoked», ADR-006), а чинить или
 *   смотреть состояние подключения умеет только company-scoped экран
 *   «Подключения», не эта форма.
 *
 *   Встречной петли с гейтом (`resolveCompanyGate`) нет: та же
 *   компания, оказавшись на `/companies/{id}/connections` с любым
 *   непустым списком подключений, получает от гейта `ready`
 *   (resolveCompanyGate: `hasActive` не требуется — ветка `!hasActive`
 *   сама сверяет `pathname` с `connectionsPath` и отдаёт `ready`, а при
 *   наличии `active` гейт отдаёт `ready` безусловно) — редиректа назад
 *   на /onboarding оттуда нет. `/onboarding` сюда не ведёт: маршрут
 *   верхнеуровневый (Root.tsx), а не company-scoped экран за гейтом,
 *   и обратно на себя эта функция никогда не адресует.
 */
export function resolveOnboardingDecision(
  companyId: string,
  connections: ConnectionsQueryState,
): OnboardingDecision {
  if (connections.status === 'pending') {
    return { kind: 'pending' }
  }

  if (
    connections.status === 'success' &&
    connections.data.connections.length > 0
  ) {
    return { kind: 'connections', to: connectionsPath(companyId) }
  }

  return { kind: 'form' }
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
