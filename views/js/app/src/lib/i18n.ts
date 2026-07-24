// SmartBulk — translation helper.
//
// Reads the dictionary injected by the backend into window.SmartBulk.i18n and
// exposes a single `t()` function. Falls back to the provided English fallback
// (or the key itself) when no translation exists — that way new strings can
// land in the codebase before they're translated, without breaking the UI.

export function t(key: string, fallback?: string): string {
  const dict = (typeof window !== 'undefined' && window.SmartBulk?.i18n) || {};
  return dict[key] ?? fallback ?? key;
}

/**
 * Like t() but performs simple {placeholder} substitution.
 * Example: t2('bulk.apply_to_n', 'Apply to {n} products', { n: 247 })
 */
export function t2(key: string, fallback: string, vars: Record<string, string | number>): string {
  let s = t(key, fallback);
  for (const [k, v] of Object.entries(vars)) {
    s = s.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
  }
  return s;
}
