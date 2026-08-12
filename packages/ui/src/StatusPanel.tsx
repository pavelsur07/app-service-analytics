import type { ReactNode } from "react";

type Tone = "neutral" | "accent" | "negative";

const TONE: Record<Tone, string> = {
  neutral: "bg-border-subtle text-text-secondary",
  accent: "bg-accent-subtle text-accent",
  negative: "bg-negative-bg text-negative-text",
};

export function StatusPanel({
  icon,
  title,
  description,
  action,
  tone = "neutral",
  role = "status",
}: {
  icon: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
  tone?: Tone;
  role?: "status" | "alert";
}) {
  return (
    <div
      className="flex flex-col items-center gap-3 py-3 text-center"
      role={role}
    >
      <span
        aria-hidden="true"
        className={`grid size-10 place-items-center rounded-full ${TONE[tone]}`}
      >
        {icon}
      </span>
      <span className="text-base font-semibold">{title}</span>
      {description === undefined ? null : (
        <span className="text-text-muted">{description}</span>
      )}
      {action}
    </div>
  );
}
