/** Default for this fork before a user or company has chosen a language. */
export const DEFAULT_LOCALE = 'cs'

export function defaultLocale(): string {
  return typeof window !== 'undefined' && window.app_locale
    ? window.app_locale
    : DEFAULT_LOCALE
}
