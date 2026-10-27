import type { Grouping } from './salesPlan'

export function performancePageScope(
  companyId: string,
  planId: string,
  version: number,
  window: { from: string; to: string; groupBy: Grouping },
): string {
  return JSON.stringify([
    companyId,
    planId,
    version,
    window.from,
    window.to,
    window.groupBy,
  ])
}

export function performanceCursorStack(
  state: { scope: string; stack: (string | null)[] },
  scope: string,
  cursor: string | null,
): (string | null)[] {
  return state.scope === scope && state.stack[state.stack.length - 1] === cursor
    ? state.stack
    : [cursor]
}
