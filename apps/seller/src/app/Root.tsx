import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createBrowserRouter, RouterProvider } from 'react-router'
import { App as PingScreen } from '../App'
import { SalesFactsPage } from '../features/ingestion/ui/SalesFactsPage'

const queryClient = new QueryClient()

const router = createBrowserRouter([
  { path: '/', element: <PingScreen /> },
  {
    path: '/companies/:companyId/ingestion/sales-facts',
    element: <SalesFactsPage />,
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
