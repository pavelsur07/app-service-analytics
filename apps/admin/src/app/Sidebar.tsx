import { Building2, LogOut, ShieldCheck } from 'lucide-react'
import { NavLink } from 'react-router'

import { Button } from '../../../../packages/ui/src'
import { useLogout } from '../features/auth/model/useLogout'
import { useCurrentAdmin } from '../shared/model/useCurrentAdmin'

const ITEM =
  'flex h-9 items-center gap-3 rounded-md px-2 text-sm no-underline ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'
const ITEM_ACTIVE = 'bg-accent-subtle font-semibold text-accent-hover'
const ITEM_IDLE = 'text-text-secondary hover:bg-surface-hover'

export function Sidebar() {
  const currentAdmin = useCurrentAdmin()
  const logout = useLogout()

  return (
    <nav
      aria-label="Разделы администрирования"
      className="flex w-60 flex-col gap-1 overflow-y-auto border-r border-border-default bg-surface-raised px-3 py-4"
    >
      <NavLink
        to="/accounts"
        className={({ isActive }) =>
          `${ITEM} ${isActive ? ITEM_ACTIVE : ITEM_IDLE}`
        }
      >
        <Building2 aria-hidden="true" size={16} />
        Аккаунты
      </NavLink>

      {currentAdmin.data?.role === 'super_admin' && (
        <NavLink
          to="/administrators"
          className={({ isActive }) =>
            `${ITEM} ${isActive ? ITEM_ACTIVE : ITEM_IDLE}`
          }
        >
          <ShieldCheck aria-hidden="true" size={16} />
          Администраторы
        </NavLink>
      )}

      <div className="mt-auto flex flex-col gap-2 border-t border-border-subtle pt-3">
        <span className="truncate px-2 text-xs text-text-muted">
          {currentAdmin.data?.email}
        </span>
        <Button
          type="button"
          variant="ghost"
          size="compact"
          loading={logout.isPending}
          onClick={() => logout.mutate()}
        >
          <LogOut aria-hidden="true" size={14} />
          Выйти
        </Button>
      </div>
    </nav>
  )
}
