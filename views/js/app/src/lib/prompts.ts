// SmartBulk — prompt library types + API helpers

import { api } from './api';

export type TaskType =
  | 'meta_title'
  | 'meta_description'
  | 'short_desc'
  | 'long_desc'
  | 'translate'
  | 'alt_text'
  | 'seo_rewrite'
  | 'tagging'
  | 'custom';

export type Provider = 'claude' | 'openai';

export const TASK_TYPE_LABELS: Record<TaskType, string> = {
  meta_title:       'Meta title',
  meta_description: 'Meta description',
  short_desc:       'Short description',
  long_desc:        'Long description',
  translate:        'Translate',
  alt_text:         'Image alt text',
  seo_rewrite:      'SEO rewrite',
  tagging:          'Auto-tagging',
  custom:           'Custom',
};

export const MODELS_BY_PROVIDER: Record<Provider, { id: string; label: string }[]> = {
  claude: [
    { id: 'claude-haiku-4-5',  label: 'Claude Haiku 4.5 (fast, cheap)' },
    { id: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6 (balanced)' },
    { id: 'claude-opus-4-7',   label: 'Claude Opus 4.7 (best quality)' },
  ],
  openai: [
    { id: 'gpt-4o-mini', label: 'GPT-4o-mini (fast, cheap)' },
    { id: 'gpt-4o',      label: 'GPT-4o (quality)' },
  ],
};

export interface Prompt {
  id_prompt: number;
  slug: string;
  name: string;
  task_type: TaskType;
  is_active: boolean;
  current_version: number | null;
  current_version_number: number | null;
  current_model: string | null;
  current_provider: Provider | null;
  current_version_created_at: string | null;
  created_at: string;
}

export interface PromptVersion {
  id_prompt_version: number;
  id_prompt: number;
  version_number: number;
  parent_version: number | null;
  system_prompt: string;
  user_prompt: string;
  model: string;
  provider: Provider;
  params: Record<string, unknown> | null;
  notes: string;
  created_at: string;
}

export interface PromptDetail {
  prompt: Prompt;
  versions: PromptVersion[];
}

interface OkResponse {
  ok: boolean;
  error?: string;
}

interface ListResponse extends OkResponse {
  prompts: Prompt[];
}

interface DetailResponse extends OkResponse, PromptDetail {}

const BASE = '/modules/smartbulk/api/prompts';

export const promptsApi = {
  list: (filters: { task_type?: string; search?: string } = {}) => {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    ).toString();
    return api.get<ListResponse>(`${BASE}${qs ? `?${qs}` : ''}`).then((r) => r.prompts);
  },

  detail: (id: number) =>
    api.get<DetailResponse>(`${BASE}/${id}`).then((r) => ({ prompt: r.prompt, versions: r.versions })),

  create: (input: {
    name: string;
    task_type: TaskType;
    slug?: string;
    system_prompt: string;
    user_prompt: string;
    model?: string;
    provider?: Provider;
    params?: Record<string, unknown>;
    notes?: string;
  }) =>
    api.post<DetailResponse>(BASE, input).then((r) => ({ prompt: r.prompt, versions: r.versions })),

  rename: (id: number, name: string) =>
    api.patch<DetailResponse>(`${BASE}/${id}`, { name }).then((r) => ({
      prompt: r.prompt,
      versions: r.versions,
    })),

  createVersion: (id: number, input: {
    system_prompt: string;
    user_prompt: string;
    model?: string;
    provider?: Provider;
    params?: Record<string, unknown>;
    notes?: string;
  }) =>
    api.post<DetailResponse>(`${BASE}/${id}/versions`, input).then((r) => ({
      prompt: r.prompt,
      versions: r.versions,
    })),

  activateVersion: (id: number, versionId: number) =>
    api.post<DetailResponse>(`${BASE}/${id}/versions/${versionId}/activate`, {}).then((r) => ({
      prompt: r.prompt,
      versions: r.versions,
    })),

  delete: (id: number) =>
    api.del<OkResponse>(`${BASE}/${id}`),
};

export const PLACEHOLDERS = [
  '{name}', '{category}', '{brand}', '{features}',
  '{focus_keyphrase}', '{short_description}', '{description}',
  '{target_lang}', '{source_lang}', '{brand_tone}', '{existing_text}',
];
