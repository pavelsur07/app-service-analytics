import { useState } from 'react'
import { CircleX, LoaderCircle } from 'lucide-react'
import { Badge, Button, StatusPanel } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { FormDialog } from '../../../shared/ui/FormDialog'
import { useClientAccount } from '../model/useClientAccount'
import type { ClientAccountDetail } from '../model/useClientAccount'
import { AccountStatusButton } from './AccountStatusButton'

const DATE = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

const EVENTS: Record<string, string> = {
  'company.registered': 'Компания зарегистрирована',
  'company.blocked': 'Компания заблокирована',
  'company.activated': 'Компания включена',
  'marketplace_account.connected': 'Кабинет подключён',
  'marketplace_account.discarded': 'Пустое подключение удалено',
  'marketplace_account.credentials_replaced': 'Ключи кабинета заменены',
  'marketplace_account.advertising_credentials_replaced':
    'Ключи рекламы добавлены или заменены',
  'user.email_confirmed': 'Email подтверждён',
}

function AccountDate({ value }: { value: string }) {
  return <time dateTime={value}>{DATE.format(new Date(value))}</time>
}

function ConnectionState({ state }: { state: string | null }) {
  if (state === null) return <Badge>Не подключена</Badge>
  if (state === 'active') return <Badge tone="positive">Работает</Badge>
  if (state === 'broken')
    return <Badge tone="negative">Ошибка авторизации</Badge>
  if (state === 'revoked') return <Badge>Отключено</Badge>
  return <Badge>{state}</Badge>
}

function CompanyInformation({ data }: { data: ClientAccountDetail }) {
  const [copyMessage, setCopyMessage] = useState('')
  const owner = data.members.find((member) => member.role === 'owner')
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(data.company.id)
      setCopyMessage('ID скопирован')
    } catch {
      setCopyMessage(
        'Не удалось скопировать ID. Выделите и скопируйте его вручную.',
      )
    }
  }

  return (
    <div className="flex flex-col gap-6 text-sm">
      <section aria-label="Компания" className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Badge
            tone={data.company.status === 'active' ? 'positive' : 'negative'}
          >
            {data.company.status === 'active' ? 'Активна' : 'Заблокирована'}
          </Badge>
          <AccountStatusButton account={data.company} />
        </div>
        {owner && (
          <p className="break-all text-text-secondary">
            Владелец:{' '}
            <a
              className="text-accent-default underline"
              href={`mailto:${owner.email}`}
            >
              {owner.email}
            </a>
          </p>
        )}
        <dl className="grid gap-3 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-text-secondary">Дата создания · МСК</dt>
            <dd>
              <AccountDate value={data.company.createdAt} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-text-secondary">ID компании</dt>
            <dd className="flex flex-wrap items-center gap-2">
              <code className="break-all text-xs">{data.company.id}</code>
              <Button
                type="button"
                variant="ghost"
                size="compact"
                onClick={() => {
                  void copy()
                }}
              >
                Копировать ID
              </Button>
            </dd>
          </div>
        </dl>
        {copyMessage && (
          <p role="status" className="text-xs text-text-secondary">
            {copyMessage}
          </p>
        )}
        {!data.company.hasConfirmedUser && (
          <p className="rounded-lg border border-warning-border bg-warning-bg p-3 text-warning-text">
            В компании нет участников с подтверждённым email.
          </p>
        )}
      </section>

      <section
        aria-labelledby="account-members-title"
        className="border-t border-border-default pt-5"
      >
        <h3 id="account-members-title" className="mb-3 font-semibold">
          Участники
        </h3>
        {data.members.length === 0 ? (
          <p className="text-text-secondary">Участников нет.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {data.members.map((member) => (
              <li
                key={member.id}
                className="rounded-lg border border-border-default p-3"
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <a
                    className="break-all text-accent-default underline"
                    href={`mailto:${member.email}`}
                  >
                    {member.email}
                  </a>
                  <Badge>
                    {member.role === 'owner' ? 'Владелец' : member.role}
                  </Badge>
                </div>
                <p className="mt-2 text-xs text-text-secondary">
                  Добавлен: <AccountDate value={member.joinedAt} /> МСК
                </p>
                <p className="mt-1 text-xs">
                  {member.emailConfirmedAt ? (
                    <>
                      Email подтверждён:{' '}
                      <AccountDate value={member.emailConfirmedAt} /> МСК
                    </>
                  ) : (
                    <span className="text-warning-text">
                      Email не подтверждён
                    </span>
                  )}
                </p>
              </li>
            ))}
          </ul>
        )}
        {data.membersTruncated && (
          <p className="mt-2 text-warning-text">
            Показаны первые 50 участников.
          </p>
        )}
      </section>

      <section
        aria-labelledby="account-connections-title"
        className="border-t border-border-default pt-5"
      >
        <h3 id="account-connections-title" className="mb-3 font-semibold">
          Подключения маркетплейсов
        </h3>
        {data.connections.length === 0 ? (
          <p className="text-text-secondary">Маркетплейсы ещё не подключены.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {data.connections.map((connection) => (
              <li
                key={connection.id}
                className="rounded-lg border border-border-default p-3"
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="break-all font-medium">
                    {connection.marketplace === 'ozon'
                      ? 'Ozon'
                      : connection.marketplace}{' '}
                    · {connection.externalShopId}
                  </span>
                  <ConnectionState state={connection.state} />
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <span className="text-text-secondary">Реклама:</span>
                  <ConnectionState
                    state={connection.advertisingState ?? null}
                  />
                </div>
                <p className="mt-2 text-xs text-text-secondary">
                  Подключён: <AccountDate value={connection.createdAt} /> МСК
                </p>
              </li>
            ))}
          </ul>
        )}
        {data.connectionsTruncated && (
          <p className="mt-2 text-warning-text">
            Показаны первые 50 подключений.
          </p>
        )}
      </section>

      <details className="border-t border-border-default pt-5">
        <summary className="cursor-pointer font-semibold focus-visible:outline-accent-default">
          История действий
        </summary>
        <p className="mt-2 text-xs text-text-secondary">
          Последние события регистрации, доступа и подключений. Время — МСК.
        </p>
        {data.recentAudit.length === 0 ? (
          <p className="mt-3 text-text-secondary">Событий пока нет.</p>
        ) : (
          <ol className="mt-3 flex flex-col gap-3">
            {data.recentAudit.map((event) => (
              <li
                key={event.id}
                className="border-l-2 border-border-default pl-3"
              >
                <p>{EVENTS[event.action] ?? 'Изменение аккаунта'}</p>
                <p className="mt-1 break-all text-xs text-text-secondary">
                  {event.actorType === 'admin'
                    ? 'Администратор'
                    : 'Пользователь'}
                  : {event.actorEmail ?? event.actorId}
                </p>
                <p className="mt-1 text-xs text-text-secondary">
                  <AccountDate value={event.occurredAt} />
                </p>
              </li>
            ))}
          </ol>
        )}
        {data.auditTruncated && (
          <p className="mt-3 text-xs text-text-secondary">
            Показаны последние 20 событий. Более ранние сохранены в журнале
            аудита.
          </p>
        )}
      </details>
    </div>
  )
}

export function AccountDetailPanel({
  companyId,
  onClose,
}: {
  companyId: string
  onClose: () => void
}) {
  const account = useClientAccount(companyId)
  const notFound =
    account.error instanceof ApiError && account.error.status === 404
  return (
    <FormDialog
      title={account.data?.company.name ?? 'Карточка компании'}
      variant="panel"
      busy={false}
      onClose={onClose}
    >
      {account.isPending && (
        <StatusPanel
          title="Загружаем карточку компании"
          icon={
            <LoaderCircle
              aria-hidden="true"
              size={20}
              className="animate-spin"
            />
          }
        />
      )}
      {account.isError && (
        <StatusPanel
          title={
            notFound ? 'Компания не найдена' : 'Не удалось загрузить карточку'
          }
          description={
            notFound
              ? 'Компания удалена или ссылка неверна.'
              : 'Проверьте соединение и повторите запрос.'
          }
          tone="negative"
          role="alert"
          icon={<CircleX aria-hidden="true" size={20} />}
          action={
            !notFound && (
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  void account.refetch()
                }}
              >
                Повторить
              </Button>
            )
          }
        />
      )}
      {account.data && (
        <CompanyInformation key={companyId} data={account.data} />
      )}
    </FormDialog>
  )
}
