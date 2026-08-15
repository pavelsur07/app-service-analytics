import type { ReactNode } from "react";

type Tone = "default" | "negative" | "warning";

const TONE: Record<Tone, string> = {
  default: "border-border-default",
  negative: "border-negative-border",
  // Токены warning заданы в theme.css вместе с остальными и уже
  // используются Badge — здесь тот же набор, не новый цвет.
  warning: "border-warning-border",
};

// Поверхность из макета: surface/raised, border/default, радиус 12
// (rounded-xl в шкале Tailwind), тень 0 1px 2px.
export function Card({
  children,
  tone = "default",
}: {
  children: ReactNode;
  tone?: Tone;
}) {
  return (
    <div
      className={`rounded-xl border bg-surface-raised p-5 shadow-card ${TONE[tone]}`}
    >
      {children}
    </div>
  );
}
