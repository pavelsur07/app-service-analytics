import { useState } from 'react'
import {
  Link,
  useLocation,
  useNavigate,
  useParams,
  useSearchParams,
} from 'react-router'
import { CircleX, LoaderCircle } from 'lucide-react'
import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { useClientAccounts } from '../model/useClientAccounts'
import { AccountDetailPanel } from './AccountDetailPanel'
import { AccountStatusButton } from './AccountStatusButton'
import { RegisterAccountForm } from './RegisterAccountForm'

// Экран управления клиентскими аккаунтами (ADR-017). Доступен обеим
// ролям контура — в отличие от заведения администраторов.
export function AccountsPage() {
  const { companyId } = useParams<{ companyId: string }>()
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams, setSearchParams] = useSearchParams()
  const rawPage = Number(searchParams.get('page') ?? '1')
  const page = Number.isSafeInteger(rawPage) && rawPage > 0 ? rawPage : 1
  const setPage = (next: number) => {
    setSearchParams((params) => {
      if (next === 1) params.delete('page')
      else params.set('page', String(next))
      return params
    })
  }
  const cardPath = (id: string) =>
    `/accounts/${encodeURIComponent(id)}${location.search}`
  const closeCard = () => {
    const state: unknown = location.state
    if (
      typeof state === 'object' &&
      state !== null &&
      'companyCard' in state &&
      state.companyCard === true
    ) {
      void navigate(-1)
    } else {
      void navigate(`/accounts${location.search}`, { replace: true })
    }
  }
  const [creatingAccount, setCreatingAccount] = useState(false)
  const accounts = useClientAccounts(page)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-xl font-semibold">Аккаунты</h1>
        <Button
          type="button"
          onClick={() => {
            setCreatingAccount(true)
          }}
        >
          Новый аккаунт
        </Button>
      </header>

      {creatingAccount && (
        <RegisterAccountForm
          onCancel={() => {
            setCreatingAccount(false)
          }}
          onCreated={() => {
            setPage(1)
            setCreatingAccount(false)
          }}
        />
      )}

      <Card>
        {accounts.status === 'pending' && (
          <StatusPanel
            icon={
              <LoaderCircle
                aria-hidden="true"
                className="animate-spin"
                size={20}
              />
            }
            title="Загружаем аккаунты"
          />
        )}

        {accounts.status === 'error' && (
          <StatusPanel
            action={
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  void accounts.refetch()
                }}
              >
                Повторить
              </Button>
            }
            description="Не удалось загрузить список."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Ошибка"
            tone="negative"
          />
        )}

        {accounts.status === 'success' && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-muted">
                    <th className="py-2">Компания</th>
                    <th className="py-2">Состояние</th>
                    <th className="py-2">Заведён</th>
                    <th className="py-2" />
                  </tr>
                </thead>
                <tbody>
                  {accounts.data.items.map((account) => (
                    <tr
                      key={account.id}
                      className="cursor-pointer border-t border-border hover:bg-surface-hover"
                      onClick={(event) => {
                        if (
                          event.target instanceof Element &&
                          event.target.closest(
                            'button, a, input, summary, dialog',
                          )
                        )
                          return
                        event.currentTarget.querySelector('a')?.focus()
                        void navigate(cardPath(account.id), {
                          state: { companyCard: true },
                        })
                      }}
                    >
                      <td className="py-2">
                        <Link
                          to={cardPath(account.id)}
                          state={{ companyCard: true }}
                          className="text-accent-default underline decoration-transparent hover:decoration-current focus-visible:outline-accent-default"
                        >
                          {account.name}
                        </Link>
                      </td>
                      <td className="py-2">
                        <Badge
                          tone={
                            account.status === 'active'
                              ? 'positive'
                              : 'negative'
                          }
                        >
                          {account.status === 'active'
                            ? 'работает'
                            : 'заблокирован'}
                        </Badge>
                      </td>
                      <td className="py-2 text-muted">
                        {account.createdAt.slice(0, 10)}
                      </td>
                      <td className="py-2 text-right">
                        <AccountStatusButton account={account} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {accounts.data.pages > 1 && (
              <div className="mt-4 flex items-center gap-3 text-sm">
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={page <= 1}
                  onClick={() => {
                    setPage(page - 1)
                  }}
                >
                  Назад
                </Button>
                <span className="text-muted">
                  {accounts.data.page} из {accounts.data.pages}
                </span>
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={page >= accounts.data.pages}
                  onClick={() => {
                    setPage(page + 1)
                  }}
                >
                  Вперёд
                </Button>
              </div>
            )}
          </>
        )}
      </Card>
      {companyId && (
        <AccountDetailPanel
          key={companyId}
          companyId={companyId}
          onClose={closeCard}
        />
      )}
    </div>
  )
}
