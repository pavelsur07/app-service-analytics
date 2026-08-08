import { useId } from "react";
import type { InputHTMLAttributes } from "react";

type Props = Omit<
  InputHTMLAttributes<HTMLInputElement>,
  "className" | "id" | "aria-invalid" | "aria-describedby"
> & {
  label: string;
  // Текст ошибки, не флаг: поле без объяснения, что именно не так,
  // заставляет пользователя гадать.
  error?: string;
  hint?: string;
};

const FIELD =
  "h-9 w-full rounded-md border px-3 " +
  "hover:border-border-strong " +
  "focus:border-accent focus:outline-2 focus:outline-border-focus " +
  "disabled:border-border-subtle disabled:bg-surface-sunken disabled:text-text-disabled " +
  "disabled:hover:border-border-subtle";

export function Input({ label, error, hint, ...rest }: Props) {
  const id = useId();
  const messageId = `${id}-message`;
  const invalid = error !== undefined;

  return (
    <label className="flex flex-col gap-1" htmlFor={id}>
      <span className="text-xs font-medium text-text-secondary">{label}</span>
      <input
        {...rest}
        id={id}
        aria-invalid={invalid || undefined}
        // Связь поля с текстом ошибки: без неё программа чтения с экрана
        // объявит поле неверным, но не скажет почему.
        aria-describedby={
          error !== undefined || hint !== undefined ? messageId : undefined
        }
        className={`${FIELD} ${invalid ? "border-negative-solid bg-negative-bg" : "border-border-default bg-surface-raised"}`}
      />
      {error !== undefined ? (
        <span className="text-xs text-negative-text" id={messageId}>
          {error}
        </span>
      ) : hint !== undefined ? (
        <span className="text-xs text-text-muted" id={messageId}>
          {hint}
        </span>
      ) : null}
    </label>
  );
}
