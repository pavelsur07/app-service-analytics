import { LogOut } from 'lucide-react'
import { Outlet } from 'react-router'
import { Badge, Button } from '../../../../packages/ui/src'
import { useCurrentAdmin } from '../shared/model/useCurrentAdmin'
import { useLogout } from '../features/auth/model/useLogout'

// Оболочка системного раздела. Сайдбара пока нет намеренно: экран один,
// и меню из одного пункта — украшение, а не навигация. Появится второй
// (управление аккаунтами) — появится и меню.
export function AdminLayout() {
  const currentAdmin = useCurrentAdmin()
  const logout = useLogout()

  return (
    <div className="min-h-screen">
      <header className="flex items-center justify-between border-b border-border px-6 py-3">
        <div className="flex items-center gap-3">
          <span className="font-semibold">Conwix — администрирование</span>
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
