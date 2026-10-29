import type { components } from '../../src/api/schema'
import { performanceFixture } from './salesPerformance'

export function performanceColumns(
  value: Partial<components['schemas']['PerformanceValuesResponse']> = {},
) {
  const base = performanceFixture.skus[0]?.points[0]?.period
  if (base === undefined) throw new Error('Missing performance fixture')
  return [{ key: 'period', value: { ...base, ...value } }]
}
