// Ключ кэша для identity-эндпоинтов — не company-scoped (companyQueryKey
// сюда не подходит, у /api/auth/me нет companyId), но литеральный массив
// в queryKey линтер запрещает синтаксически независимо от смысла ключа
// (CLAUDE.md §7), поэтому собирается тем же способом — общим хелпером,
// а не вписан в вызов useQuery напрямую.
export function authQueryKey(): readonly unknown[] {
  return ['auth', 'me'] as const
}
