import { Outlet } from 'react-router'

import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

// Оболочка системного раздела повторяет геометрию seller-приложения:
// шапка остаётся на месте, независимо прокручиваются меню и рабочая зона.
export function AdminLayout() {
  return (
    <div className="flex h-screen flex-col overflow-hidden bg-bg-base">
      <Topbar />
      <div className="flex min-h-0 flex-1">
        <Sidebar />
        <main className="flex min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
