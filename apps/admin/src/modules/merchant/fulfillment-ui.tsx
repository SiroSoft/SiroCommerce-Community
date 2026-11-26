"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/page-header";
import { ApiState } from "./api-state";
import { useCancelFulfillment, useFulfillmentAction, useFulfillmentTrack } from "@/hooks/use-merchant";

export function FulfillmentWorkspace() {
  const [id, setId] = useState("");
  const track = useFulfillmentTrack(id);
  const ship = useFulfillmentAction("ship");
  const deliver = useFulfillmentAction("deliver");
  const cancel = useCancelFulfillment();
  const busy = ship.isPending || deliver.isPending || cancel.isPending;
  return <div className="space-y-5">
    <PageHeader title="Fulfillment" description="Track and advance fulfillment records through the Admin API contract." />
    <Card><CardHeader><CardTitle>Fulfillment record</CardTitle></CardHeader><CardContent className="space-y-4">
      <div className="flex flex-wrap gap-2"><Input aria-label="Fulfillment ID" value={id} onChange={(event) => setId(event.target.value)} placeholder="Enter fulfillment ID" /><Button variant="outline" disabled={!id.trim() || track.isFetching} onClick={() => track.refetch()}>Refresh</Button></div>
      {track.isLoading ? <p role="status">Loading fulfillment...</p> : track.isError ? <ApiState error={track.error} onRetry={() => track.refetch()} /> : track.data ? <div className="space-y-3"><pre className="overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(track.data, null, 2)}</pre><div className="flex flex-wrap gap-2"><Button disabled={busy} onClick={() => ship.mutate({ id })}>Ship</Button><Button disabled={busy} onClick={() => deliver.mutate({ id })}>Deliver</Button><Button variant="destructive" disabled={busy} onClick={() => cancel.mutate({ id })}>Cancel</Button></div></div> : <p className="text-sm text-muted-foreground">Enter an ID to load fulfillment status.</p>}
      {(ship.isError || deliver.isError || cancel.isError) && <ApiState error={ship.error || deliver.error || cancel.error} />}
    </CardContent></Card>
  </div>;
}
