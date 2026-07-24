// Shared types for SmartBulk admin SPA.

export interface SmartBulkBootstrap {
  module: { name: string; version: string };
  shop: {
    id: number;
    ids: number[];
    is_all: boolean;
    is_group: boolean;
    is_single: boolean;
  };
  user: { name: string; id: number };
  // Shop's default currency for price display. Symbol + ISO + display format
  // come from the BO context so prices show in PLN/USD/etc. instead of EUR.
  currency?: { symbol: string; iso_code: string; format: string };
  links: {
    api: string;
    buy_a_coffee: string;
    github: string;
  };
  // Optional: when present, the user arrived via the native PS product-grid
  // bulk action "Edit with SmartBulk". App should auto-open Bulk Editor.
  grid_handoff?: {
    product_ids: number[];
  } | null;
  // Optional: when present, the user arrived via the per-issue "Fix with AI"
  // button on the product form's Health panel. App should auto-open AI Assistant.
  ai_handoff?: {
    product_id: number;
    task_type: string;
  } | null;
  // Translations for the SPA — flat key→string map, language picked from
  // the BO admin's current language (en.json fallback if missing).
  i18n?: Record<string, string>;
}

declare global {
  interface Window {
    SmartBulk: SmartBulkBootstrap;
  }
}

export {};
