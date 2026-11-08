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
    { id: 'claude-haiku-5-5',  label: 'Claude Haiku 5.5 (fastest, cheapest)' },
    { id: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6 (legacy, temperature)' },
    { id: 'claude-sonnet-5',   label: 'Claude Sonnet 5' },
    { id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5 (balanced)' },
    { id: 'claude-opus-4-7',   label: 'Claude Opus 4.7 (legacy)' },
    { id: 'claude-opus-5-5',   label: 'Claude Opus 5.5 (best quality)' },
  ],
  openai: [
    { id: 'gpt-4o-mini', label: 'GPT-4o-mini (fast, cheap)' },
    { id: 'gpt-4o',      label: 'GPT-4o (quality)' },
  ],
};

// ---------------- Claude model capabilities ----------------
// Mirror of src/Service/Ai/ClaudeModelCapabilities.php — keep both in sync.
// The PHP side is authoritative: it drops any field a model doesn't accept,
// so this table only decides what the editor offers.

export const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'] as const;
export type EffortLevel = (typeof EFFORT_LEVELS)[number];

/** Highest effort at which thinking may be switched off on default-on models. */
export const THINKING_OFF_MAX_EFFORT: EffortLevel = 'high';

/**
 * Below this max_tokens the editor warns when thinking is on. Heuristic only
 * (the API's old minimum thinking budget); not measured on our prompts.
 */
export const THINKING_MIN_MAX_TOKENS = 1024;

export type ThinkingMode = 'default_on' | 'always_on' | 'default_off' | 'none';

/** Stored in params.thinking; '' (absent) = API default. */
export type ThinkingSetting = '' | 'adaptive' | 'disabled';

export interface ClaudeModelCaps {
  /** Accepted output_config.effort levels ([] = effort not supported). */
  effort: EffortLevel[];
  /** Level the API uses when effort is omitted. */
  defaultEffort: EffortLevel | null;
  thinking: ThinkingMode;
  /** thinking.type sent to switch thinking off (null = can't be switched off). */
  thinkingOff: 'disabled' | 'between_tools' | null;
  /** Whether temperature/top_p are forwarded. */
  temperature: boolean;
}

const ALL_EFFORT: EffortLevel[] = [...EFFORT_LEVELS];

export const CLAUDE_MODEL_CAPS: Record<string, ClaudeModelCaps> = {
  'claude-haiku-4-5':  { effort: [],                                  defaultEffort: null,     thinking: 'none',        thinkingOff: null,            temperature: true },
  'claude-haiku-5-5':  { effort: ALL_EFFORT,                          defaultEffort: 'medium', thinking: 'default_on',  thinkingOff: 'disabled',      temperature: false },
  'claude-sonnet-4-6': { effort: ['low', 'medium', 'high', 'max'],    defaultEffort: 'high',   thinking: 'default_off', thinkingOff: 'disabled',      temperature: true },
  'claude-sonnet-5':   { effort: ALL_EFFORT,                          defaultEffort: 'high',   thinking: 'default_on',  thinkingOff: 'disabled',      temperature: false },
  'claude-sonnet-5-5': { effort: ALL_EFFORT,                          defaultEffort: 'high',   thinking: 'default_on',  thinkingOff: 'between_tools', temperature: false },
  // Opus 4.7 rejects temperature/top_p (Anthropic migration guide) — see the PHP table.
  'claude-opus-4-7':   { effort: ALL_EFFORT,                          defaultEffort: 'high',   thinking: 'default_off', thinkingOff: 'disabled',      temperature: false },
  'claude-opus-5':     { effort: ALL_EFFORT,                          defaultEffort: 'high',   thinking: 'default_on',  thinkingOff: 'disabled',      temperature: false },
  'claude-opus-5-5':   { effort: ALL_EFFORT,                          defaultEffort: 'medium', thinking: 'always_on',   thinkingOff: null,            temperature: false },
};

/** Conservative entry for unknown Claude models. */
const UNKNOWN_CLAUDE_CAPS: ClaudeModelCaps = {
  effort: [], defaultEffort: null, thinking: 'none', thinkingOff: null, temperature: false,
};

/** OpenAI models: temperature yes, Claude-specific fields no. */
const OPENAI_CAPS: ClaudeModelCaps = { ...UNKNOWN_CLAUDE_CAPS, temperature: true };

export function modelCaps(provider: Provider, model: string): ClaudeModelCaps {
  if (provider !== 'claude') return OPENAI_CAPS;
  return CLAUDE_MODEL_CAPS[model] ?? UNKNOWN_CLAUDE_CAPS;
}

export function effortRank(level: EffortLevel | '' | null): number {
  return level ? EFFORT_LEVELS.indexOf(level) : 0;
}

/** Whether thinking may be switched off at the given effort ('' = model default). */
export function canDisableThinking(caps: ClaudeModelCaps, effort: EffortLevel | ''): boolean {
  if (caps.thinking !== 'default_on' || caps.thinkingOff === null) return false;
  return effortRank(effort || caps.defaultEffort) <= effortRank(THINKING_OFF_MAX_EFFORT);
}

/** Whether the model will think with these settings. */
export function thinkingActive(caps: ClaudeModelCaps, setting: ThinkingSetting, effort: EffortLevel | ''): boolean {
  switch (caps.thinking) {
    case 'always_on':   return true;
    case 'default_on':  return setting !== 'disabled' || !canDisableThinking(caps, effort);
    case 'default_off': return setting === 'adaptive';
    default:            return false;
  }
}

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
