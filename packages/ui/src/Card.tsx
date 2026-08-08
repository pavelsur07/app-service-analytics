import type { ReactNode } from "react";

// Поверхность из макета: surface/raised, border/default, радиус 12
// (rounded-xl в шкале Tailwind), тень 0 1px 2px.
export function Card({ children }: { children: ReactNode }) {
  return (
    <div className="rounded-xl border border-border-default bg-surface-raised p-5 shadow-card">
      {children}
    </div>
  );
}
