export interface HeadlessSchemaContract {
  schema: Record<string, unknown>;
  limits: { max_query_bytes: number; max_depth: number; max_complexity: number; read_only: true };
}

export interface AiSuggestionRequest {
  task: string;
  input: Record<string, unknown>;
  max_cost_minor: number;
}

export interface AiSuggestionResult {
  status: "available" | "provider_unavailable" | "budget_exceeded" | "denied";
  suggestion?: Record<string, unknown>;
  usage?: Record<string, unknown>;
  reason?: string | null;
  autonomous_mutation: false;
}
