// @ts-ignore
import type { ReactNode } from "react";

type Tone = "neutral" | "positive" | "negative" | "warning";
type Size = "normal" | "compact";

const TONE: Record<Tone, string> = {
  neutral: "border-border-default bg-surface-sunken text-text-secondary",
  positive: "border-positive-border bg-positive-bg text-positive-text",
  negative: "border-negative-border bg-negative-bg text-negative-text",
  warning: "border-warning-border bg-warning-bg text-warning-text",
};

const SIZE: Record<Size, string> = {
  normal: "gap-2 rounded-md px-2 py-1",
  compact: "gap-1 rounded px-1.5 py-0.5",
};

// Статус читается без цвета — поэтому тон не единственный носитель
// смысла: подпись обязательна, а знак или стрелку передаёт вызывающий
// код (docs/patterns.md, «Данные и статусы»).
export function Badge({
  tone = "neutral",
  size = "normal",
  children,
}: {
  tone?: Tone;
  size?: Size;
  children: ReactNode;
}) {
  return (
    <span
      className={`inline-flex items-center whitespace-nowrap border text-xs font-medium ${TONE[tone]} ${SIZE[size]}`}
    >
      {children}
    </span>
  );
}
