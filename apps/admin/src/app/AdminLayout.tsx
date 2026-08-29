import { LogOut } from 'lucide-react'
import { NavLink, Outlet } from 'react-router'
import { Badge, Button } from '../../../../packages/ui/src'
import { useCurrentAdmin } from '../shared/model/useCurrentAdmin'
import { useLogout } from '../features/auth/model/useLogout'

// Оболочка системного раздела. Меню появилось вместе со вторым экраном,
// а не заранее: из одного пункта оно было бы украшением.
//
// Пункт «Администраторы» скрыт от нижней роли — это подсказка, а не
// защита: отказ всё равно приходит от бэкенда (#[IsGranted]), и прятать
// кнопку вместо проверки было бы ровно тем, чего делать нельзя.
export function AdminLayout() {
  const currentAdmin = useCurrentAdmin()
  const logout = useLogout()

  return (
    <div className="min-h-screen">
      <header className="flex items-center justify-between border-b border-border px-6 py-3">
        <div className="flex items-center gap-4">
          <span className="font-semibold">Conwix — администрирование</span>
          <nav className="flex items-center gap-3 text-sm">
            <NavLink
              to="/accounts"
              className={({ isActive }) =>
                isActive ? 'font-medium' : 'text-muted'
              }
            >
              Аккаунты
            </NavLink>
            {currentAdmin.data?.role === 'super_admin' && (
              <NavLink
                to="/administrators"
                className={({ isActive }) =>
                  isActive ? 'font-medium' : 'text-muted'
                }
              >
                Администраторы
              </NavLink>
            )}
          </nav>
          {currentAdmin.data && (
            // Роль показана, потому что она меняет доступное: SuperAdmin
            // заводит администраторов, Admin — нет. Человек должен
            // понимать, почему у него нет кнопки, а не считать это сбоем.
            <Badge>
              {currentAdmin.data.role === 'super_admin'
                ? 'SuperAdmin'
                : 'Admin'}
            </Badge>
          )}
        </div>
        <div className="flex items-center gap-3">
          {currentAdmin.data && (
            <span className="text-xs text-muted">
              {currentAdmin.data.email}
            </span>
          )}
          <Button
            type="button"
            variant="secondary"
            size="compact"
            loading={logout.isPending}
            onClick={() => {
              logout.mutate()
            }}
          >
            <LogOut aria-hidden="true" size={14} />
            Выйти
          </Button>
        </div>
      </header>
      <main className="p-6">
        <Outlet />
      </main>
    </div>
  )
}
