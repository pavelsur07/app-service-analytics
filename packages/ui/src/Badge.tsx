import type { ReactNode } from "react";

type Tone = "neutral" | "positive" | "negative" | "warning";

const TONE: Record<Tone, string> = {
  neutral: "border-border-default bg-surface-raised text-text-secondary",
  positive: "border-positive-border bg-positive-bg text-positive-text",
  negative: "border-negative-border bg-negative-bg text-negative-text",
  warning: "border-warning-border bg-warning-bg text-warning-text",
};

// Статус читается без цвета — поэтому тон не единственный носитель
// смысла: подпись обязательна, а знак или стрелку передаёт вызывающий
// код (docs/patterns.md, «Данные и статусы»).
export function Badge({
  tone = "neutral",
  children,
}: {
  tone?: Tone;
  children: ReactNode;
}) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium ${TONE[tone]}`}
    >
      {children}
    </span>
  );
}
