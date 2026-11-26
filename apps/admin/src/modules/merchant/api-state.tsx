import { AlertCircle, Ban, LockKeyhole, RefreshCw, ServerCrash } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

export function apiErrorStatus(error: unknown): number | undefined {
  const candidate = error as { status?: number; response?: { status?: number } } | undefined;
  return candidate?.status ?? candidate?.response?.status;
}

export function ApiState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const status = apiErrorStatus(error);
  const copy: { title: string; description: string; icon: typeof AlertCircle } = status === 400 ? { title: "Bad request", description: "The service rejected the request.", icon: AlertCircle }
    : status === 401 ? { title: "Sign-in required", description: "Your session may have expired.", icon: LockKeyhole }
    : status === 403 ? { title: "Access denied", description: "Your account cannot access this resource.", icon: Ban }
    : status === 409 ? { title: "Conflict", description: "The resource changed. Refresh before trying again.", icon: AlertCircle }
    : status === 404 ? { title: "Not found", description: "The requested resource does not exist.", icon: AlertCircle }
    : status === 408 ? { title: "Request timed out", description: "The service did not respond in time.", icon: RefreshCw }
    : status === 422 ? { title: "Validation failed", description: "The request could not be accepted.", icon: AlertCircle }
    : status === 429 ? { title: "Too many requests", description: "Please wait a moment and try again.", icon: RefreshCw }
    : status && status >= 500 ? { title: "Service unavailable", description: "The server could not complete the request.", icon: ServerCrash }
    : status && status >= 400 ? { title: "Request failed", description: "The service rejected the request.", icon: AlertCircle }
    : { title: "Something went wrong", description: "The request returned an unknown outcome.", icon: AlertCircle };
  const Icon = copy.icon;
  return <Card role="alert"><CardContent className="flex flex-col items-center gap-2 py-12 text-center">
    <Icon className="h-8 w-8 text-destructive" aria-hidden="true" />
    <h2 className="font-semibold">{copy.title}</h2><p className="max-w-md text-sm text-muted-foreground">{copy.description}</p>
    {status && <p className="text-xs text-muted-foreground">HTTP {status}</p>}
    {onRetry && <Button variant="outline" onClick={onRetry}>Try again</Button>}
  </CardContent></Card>;
}

export function UnavailableState({ title, reason }: { title: string; reason: string }) {
  return <Card><CardContent className="flex flex-col items-center gap-2 py-12 text-center">
    <Ban className="h-8 w-8 text-muted-foreground" aria-hidden="true" /><h1 className="font-semibold">{title}</h1>
    <p className="max-w-lg text-sm text-muted-foreground">Unavailable: {reason}</p>
  </CardContent></Card>;
}

export function PermissionState() {
  return <p role="alert" className="rounded-md border border-amber-500/30 bg-amber-500/5 p-3 text-sm text-amber-900 dark:text-amber-200">
    You have read-only access to merchant operations. Ask an administrator for permission to make changes.
  </p>;
}
