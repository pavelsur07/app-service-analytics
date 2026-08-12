import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createBrowserRouter, RouterProvider } from 'react-router'
import { App as PingScreen } from '../App'
import { CompanyListPage } from '../features/auth/ui/CompanyListPage'
import { LoginPage } from '../features/auth/ui/LoginPage'
import { ExtensionConnectPage } from '../features/extension/ui/ExtensionConnectPage'
import { SalesFactsPage } from '../features/ingestion/ui/SalesFactsPage'
import { RequireAuth } from './RequireAuth'

// retry: false — TanStack Query по умолчанию повторяет неудачный запрос
// трижды с задержкой; для 401/403 это не транзиентный сбой, а ответ,
// который не изменится, и повторы только откладывают редирект
// (RequireAuth, SalesFactsPage) на секунды.
const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
})

const router = createBrowserRouter([
  { path: '/', element: <PingScreen /> },
  { path: '/login', element: <LoginPage /> },
  {
    path: '/companies',
    element: (
      <RequireAuth>
        <CompanyListPage />
      </RequireAuth>
    ),
  },
  {
    path: '/companies/:companyId/ingestion/sales-facts',
    element: (
      <RequireAuth>
        <SalesFactsPage />
      </RequireAuth>
    ),
  },
  {
    path: '/companies/:companyId/extension',
    element: (
      <RequireAuth>
        <ExtensionConnectPage />
      </RequireAuth>
    ),
  },
])

// Роутер и провайдеры (docs/structure.md, «app/»). Стартовый ping-экран
// остаётся на "/" без изменений — первый настоящий экран получает свой
// маршрут, не замещает существующий.
export function Root() {
  return (
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  )
}
