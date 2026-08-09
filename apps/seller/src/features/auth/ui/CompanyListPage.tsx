import { useEffect } from 'react'
import { Link, useNavigate } from 'react-router'
import { Badge, Button, Card } from '../../../../../../packages/ui/src'
import { useCurrentUser } from '../model/useCurrentUser'
import { useLogout } from '../model/useLogout'

// Автопереход при единственной компании, список — при нескольких (ТЗ §7.6).
export function CompanyListPage() {
  const navigate = useNavigate()
  const currentUser = useCurrentUser()
  const logout = useLogout()

  const companies = currentUser.data?.companies ?? []
  const onlyCompanyId = companies.length === 1 ? companies[0]?.id : undefined

  useEffect(() => {
    if (onlyCompanyId !== undefined) {
      void navigate(`/companies/${onlyCompanyId}/ingestion/sales-facts`, {
        replace: true,
      })
    }
  }, [onlyCompanyId, navigate])

  if (currentUser.status === 'pending' || onlyCompanyId !== undefined) {
    return <p className="p-6 text-text-muted">Загрузка…</p>
  }

  if (currentUser.status === 'error') {
    return (
      <div className="p-6">
        <Card>
          <div className="flex items-center gap-3">
            <Badge tone="negative">✕ ошибка</Badge>
            <span className="text-text-secondary">
              Не удалось загрузить список компаний.
            </span>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div className="p-6">
      <Card>
        <div className="flex flex-col gap-4">
          <div className="flex items-center justify-between">
            <h1 className="text-xl font-semibold">Ваши компании</h1>
            <Button
              type="button"
              variant="ghost"
              size="compact"
              onClick={() => {
                logout.mutate()
              }}
            >
              Выйти
            </Button>
          </div>
          {companies.length === 0 ? (
            <p className="text-text-muted">
              Нет доступных компаний — обратитесь к владельцу аккаунта.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {companies.map((company) => (
                <li key={company.id}>
                  <Link
                    className="text-accent hover:underline"
                    to={`/companies/${company.id}/ingestion/sales-facts`}
                  >
                    {company.name}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>
    </div>
  )
}
