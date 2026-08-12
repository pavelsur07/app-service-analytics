import { useEffect } from 'react'
import { Building2, CircleX, LoaderCircle, LogOut } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
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
    return (
      <div className="p-6">
        <Card>
          <StatusPanel
            icon={
              <LoaderCircle
                aria-hidden="true"
                className="animate-spin"
                size={20}
              />
            }
            title="Загрузка компаний"
          />
        </Card>
      </div>
    )
  }

  if (currentUser.status === 'error') {
    return (
      <div className="p-6">
        <Card tone="negative">
          <StatusPanel
            action={
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  void currentUser.refetch()
                }}
              >
                Повторить
              </Button>
            }
            description="Не удалось загрузить список компаний."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Ошибка загрузки"
            tone="negative"
          />
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
              <LogOut aria-hidden="true" size={16} />
              Выйти
            </Button>
          </div>
          {companies.length === 0 ? (
            <StatusPanel
              action={
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  onClick={() => {
                    void currentUser.refetch()
                  }}
                >
                  Обновить
                </Button>
              }
              description="Обратитесь к владельцу аккаунта, чтобы получить доступ."
              icon={<Building2 aria-hidden="true" size={20} />}
              title="Нет доступных компаний"
            />
          ) : (
            <ul className="flex flex-col gap-2">
              {companies.map((company) => (
                <li key={company.id}>
                  <Link
                    className="font-medium"
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
