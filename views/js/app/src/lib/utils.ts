import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

// shadcn-style className merger
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}

export function formatCurrency(value: number, currency = 'USD'): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-US').format(value);
}

/**
 * Format a price using the shop's default currency (read from bootstrap).
 * Falls back to "€" + amount when bootstrap currency is missing.
 */
export function formatPrice(value: number): string {
  const c = (typeof window !== 'undefined' && window.SmartBulk?.currency) || null;
  const amount = value.toFixed(2);
  if (!c) return `€${amount}`;
  return c.format.replace('{sign}', c.symbol).replace('{amount}', amount);
}
