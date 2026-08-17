import type { ButtonHTMLAttributes, ReactNode } from "react";

// Примитив не знает предметной области: variant="primary", а не
// variant="save-costs". Кнопка, знающая про себестоимость, живёт
// в features/*/ui (docs/patterns.md, «Граница примитив / фича»).
type Variant = "primary" | "secondary" | "ghost";
type Size = "normal" | "compact";

// className наружу не выставлен намеренно. Экран компонует примитивы
// и не правит их отступы и цвета; заодно это снимает нужду
// в tailwind-merge — склеивать нечего.
type Props = Omit<ButtonHTMLAttributes<HTMLButtonElement>, "className"> & {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  children: ReactNode;
};

// Простой словарь вместо class-variance-authority: вариантов три,
// TypeScript и так не даст промахнуться мимо ключа.
const VARIANT: Record<Variant, string> = {
  primary:
    "border-accent-default bg-accent-default text-text-inverse hover:border-accent-hover hover:bg-accent-hover active:border-accent-active active:bg-accent-active",
  secondary:
    "border-border-default bg-surface-raised hover:border-border-strong hover:bg-surface-hover",
  ghost:
    "border-transparent bg-transparent text-text-secondary hover:bg-border-subtle hover:text-text-primary",
};

const SIZE: Record<Size, string> = {
  normal: "h-9 px-4",
  // Высота 28, но зона клика обязана быть не меньше 32: псевдоэлемент
  // добавляет по 2px сверху и снизу, не сдвигая соседей. content
  // не указан намеренно — Tailwind подставляет его для before: сам,
  // и произвольное значение здесь не нужно.
  compact:
    "relative h-7 px-2.5 text-xs before:absolute before:inset-x-0 before:-inset-y-0.5",
};

const SECONDARY_TEXT: Record<Size, string> = {
  normal: "text-text-primary",
  compact: "text-text-secondary",
};

const BASE =
  "inline-flex items-center justify-center gap-2 rounded-md border font-medium " +
  "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default " +
  "disabled:cursor-not-allowed disabled:border-border-default disabled:bg-border-subtle disabled:text-text-disabled " +
  "disabled:hover:border-border-default disabled:hover:bg-border-subtle";

export function Button({
  variant = "primary",
  size = "normal",
  loading = false,
  disabled,
  children,
  ...rest
}: Props) {
  return (
    <button
      {...rest}
      // Загрузка блокирует нажатие: иначе второй клик отправит форму
      // дважды, и защищаться придётся на сервере.
      disabled={disabled === true || loading}
      aria-busy={loading || undefined}
      className={`${BASE} ${VARIANT[variant]} ${SIZE[size]} ${variant === "secondary" ? SECONDARY_TEXT[size] : ""} ${loading ? "cursor-progress" : "cursor-pointer"}`}
    >
      {loading ? (
        <span
          aria-hidden="true"
          className="size-3.5 animate-spin rounded-full border-2 border-current/40 border-t-current"
        />
      ) : null}
      {children}
    </button>
  );
}
