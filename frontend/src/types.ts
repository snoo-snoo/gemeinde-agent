export type Role = 'admin' | 'analyst' | 'reviewer' | 'viewer'

export type User = {
  id: number
  name: string
  email: string
  role: Role
}

export type Municipality = {
  id: number
  slug: string
  name: string
  official_name: string
  bundesland: string
  district: string | null
  website_url: string | null
  notes: string | null
  documents_count?: number
  sources_count?: number
  sources?: Source[]
}

export type Source = {
  id: number
  name: string
  url: string
  source_type: string
  allowed_hosts: string[]
  max_documents: number | null
  active: boolean
  last_crawled_at: string | null
  last_status: string | null
}

export type Overview = {
  municipality: Municipality
  years: number[]
  documents: number
  archived_documents: number
  latest_run: AnalysisRun | null
  headline: Fact[]
  open_questions: number
  findings: number
}

export type AnalysisRun = {
  id: number
  status: string
  workflow_state: string
  trigger: string
  error: string | null
  summary: {
    documents_seen?: number
    documents_downloaded?: number
    facts?: number
    findings?: number
  } | null
  started_at: string | null
  finished_at: string | null
  created_at: string
}

export type DocumentVersion = {
  id: number
  version_number: number
  sha256: string
  byte_size: number
  source_url: string
  fetched_at: string | null
  http_status: number | null
}

export type ArchiveDocument = {
  id: number
  title: string
  document_type: string
  fiscal_year: number | null
  status: string
  canonical_url: string
  versions: DocumentVersion[]
}

export type Page<T> = {
  data: T[]
  total?: number
  meta?: { current_page: number; last_page: number; total: number }
}

export type Fact = {
  id?: number
  code: string | null
  original_label: string
  amount: string
  amount_original: string
  value_kind: string
  statement: string
  scope: string
  fiscal_year: number | null
  page: number | null
  excerpt: string | null
  currency: string
}

export type Evidence = {
  id: number
  source_url: string | null
  page: number | null
  excerpt: string | null
  role: string
}

export type Finding = {
  id: number
  rule_code: string
  title: string
  summary: string
  years: number[] | null
  positions: string[] | null
  absolute_deviation: string | null
  relative_deviation: string | null
  method: string | null
  possible_explanations: string[] | null
  counterarguments: string[] | null
  priority: number
  data_quality: string | null
  confidence: string | null
  review_status: string
  approval_status: string
  review_note: string | null
  financial_relevance: string | null
  evidence: Evidence[]
}

export type Question = {
  id: number
  audience: string
  topic: string
  body: string
  status: string
  answer: string | null
  priority: number
  finding_id: number | null
}

export type Report = {
  id: number
  report_type: string
  title: string
  status: string
  version_number: number
  data_as_of: string | null
  period_start: number | null
  period_end: number | null
  created_at: string
  body_markdown?: string
  limitations?: string | null
  source_index?: { label: string; url: string }[] | null
}

export type AgentConfig = {
  id: number
  agent_key: string
  name: string
  task: string
  enabled: boolean
  allows_llm: boolean
  prompt_version: number
  timeout_seconds: number
  token_budget: number | null
  cost_budget_cents: number | null
  provider_id: number | null
  model_config_id: number | null
  fallback_provider_id: number | null
  provider?: { id: number; name: string; driver: string } | null
  model_config?: { id: number; model_id: string; display_name: string } | null
}

export type AgentRun = {
  id: number
  analysis_run_id: number
  status: string
  attempt: number
  error: string | null
  input_tokens: number | null
  output_tokens: number | null
  cost_cents: number | null
  cost_estimated: boolean
  provider_driver: string | null
  model: string | null
  latency_ms: number | null
  config?: { agent_key: string; name: string } | null
}

export type Credential = {
  id: number
  label: string
  hint: string
  status: string
  last_tested_at: string | null
  last_test_status: string | null
}

export type ModelConfig = {
  id: number
  model_id: string
  display_name: string
  supports_json_mode: boolean
  enabled: boolean
  price_input_per_million: string | null
  price_output_per_million: string | null
  price_source: string
}

export type Provider = {
  id: number
  name: string
  driver: string
  base_url: string | null
  enabled: boolean
  is_fallback: boolean
  requests_per_minute: number | null
  credentials: Credential[]
  models: ModelConfig[]
}

export type Schedule = {
  id: number
  municipality_id: number
  cron: string
  enabled: boolean
  last_ran_at: string | null
}

export type Validation = {
  id: number
  rule_code: string
  status: string
  formula: string
  message: string
  tolerance: string | null
}
