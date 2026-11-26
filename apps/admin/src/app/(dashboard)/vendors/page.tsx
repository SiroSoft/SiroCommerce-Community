"use client";

import { useCallback, useState } from "react";
import { Pencil, Plus, Trash2, PauseCircle, PlayCircle, Wallet } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { CapabilityGate } from "@/components/capability-gate";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { PageHeader } from "@/components/page-header";
import { SearchInput } from "@/components/search-input";
import { VendorFormDialog } from "@/modules/vendors/components/vendor-form-dialog";
import { VendorPayoutsDialog } from "@/modules/vendors/components/vendor-payouts-dialog";
import { useCreateVendor, useDeleteVendor, useUpdateVendor, useVendors } from "@/hooks/use-vendors";
import type { Vendor, VendorInput } from "@/services/vendors.service";

export default function VendorsPage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [showCreate, setShowCreate] = useState(false);
  const [editVendor, setEditVendor] = useState<Vendor | null>(null);
  const [deleteVendor, setDeleteVendor] = useState<Vendor | null>(null);
  const [financeVendor, setFinanceVendor] = useState<Vendor | null>(null);

  const { vendors, meta, isLoading, isError, error, refetch } = useVendors({ page, per_page: 20, q: search || undefined, status: status || undefined });
  const createMutation = useCreateVendor();
  const updateMutation = useUpdateVendor();
  const deleteMutation = useDeleteVendor();

  const handleCreate = useCallback((data: VendorInput) => {
    createMutation.mutate(data, { onSuccess: () => setShowCreate(false) });
  }, [createMutation]);

  const handleEdit = useCallback((data: VendorInput) => {
    if (!editVendor) return;
    updateMutation.mutate({ id: editVendor.id, data }, { onSuccess: () => setEditVendor(null) });
  }, [editVendor, updateMutation]);

  const toggleStatus = useCallback((vendor: Vendor) => {
    updateMutation.mutate({ id: vendor.id, data: { status: vendor.status === "active" ? "suspended" : "active" } });
  }, [updateMutation]);

  return (
    <CapabilityGate capability="products">
      <div className="space-y-4">
        <PageHeader title="Vendors" description="Marketplace-lite vendor ownership for catalog products.">
          <Button onClick={() => setShowCreate(true)}>
            <Plus className="mr-2 h-4 w-4" />
            Create vendor
          </Button>
        </PageHeader>

        <div className="flex flex-wrap items-end gap-2" aria-label="Vendor filters">
          <SearchInput value={search} onChange={(value) => { setSearch(value); setPage(1); }} placeholder="Search vendors..." />
          <label className="grid gap-1 text-sm">Status
            <select className="h-10 rounded-md border bg-background px-3" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} aria-label="Filter vendors by status">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
            </select>
          </label>
        </div>

        {isError ? (
          <div role="alert" className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm">
            <p>{error instanceof Error ? error.message : "Vendors could not be loaded."}</p>
            <Button className="mt-3" variant="outline" size="sm" onClick={() => refetch()}>Try again</Button>
          </div>
        ) : isLoading ? (
          <div className="rounded-md border p-6 text-sm text-muted-foreground" role="status">Loading vendors...</div>
        ) : vendors.length === 0 ? (
          <div className="rounded-md border p-6 text-sm text-muted-foreground" role="status">
            No vendors found. Create one to assign products.
          </div>
        ) : (
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm" aria-label="Vendors">
              <thead className="bg-muted/40 text-left">
                <tr>
                  <th className="p-3">Name</th>
                  <th className="p-3">Slug</th>
                  <th className="p-3">Status</th>
                  <th className="p-3">Products</th>
                  <th className="p-3">Commission</th>
                  <th className="p-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {vendors.map((vendor) => (
                  <tr key={vendor.id} className="border-t">
                    <td className="p-3 font-medium">{vendor.name}</td>
                    <td className="p-3 font-mono text-xs">{vendor.slug}</td>
                    <td className="p-3">
                      <Badge variant={vendor.status === "active" ? "success" : "secondary"}>
                        {vendor.status === "active" ? <PlayCircle className="mr-1 h-3 w-3" /> : <PauseCircle className="mr-1 h-3 w-3" />}
                        {vendor.status}
                      </Badge>
                    </td>
                    <td className="p-3">{vendor.products_count ?? 0}</td>
                    <td className="p-3">{vendor.commission_bps} bps</td>
                    <td className="p-3">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="icon" onClick={() => setEditVendor(vendor)} aria-label={`Edit ${vendor.name}`}>
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => toggleStatus(vendor)} aria-label={`${vendor.status === "active" ? "Suspend" : "Activate"} ${vendor.name}`} disabled={updateMutation.isPending}>
                          {vendor.status === "active" ? <PauseCircle className="h-4 w-4" /> : <PlayCircle className="h-4 w-4" />}
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => setFinanceVendor(vendor)} aria-label={`Manage payouts ${vendor.name}`}>
                          <Wallet className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => setDeleteVendor(vendor)} aria-label={`Delete ${vendor.name}`}>
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => setPage((value) => Math.max(1, value - 1))} disabled={page <= 1}>Previous</Button>
            <span className="text-sm text-muted-foreground">Page {page} of {meta.last_page}</span>
            <Button variant="outline" size="sm" onClick={() => setPage((value) => Math.min(meta.last_page, value + 1))} disabled={page >= meta.last_page}>Next</Button>
          </div>
        )}

        <VendorFormDialog open={showCreate} onOpenChange={setShowCreate} onSubmit={handleCreate} isPending={createMutation.isPending} />
        {editVendor && (
          <VendorFormDialog open={!!editVendor} onOpenChange={() => setEditVendor(null)} vendor={editVendor} onSubmit={handleEdit} isPending={updateMutation.isPending} />
        )}
        <VendorPayoutsDialog open={financeVendor !== null} vendor={financeVendor} onOpenChange={(next) => { if (!next) setFinanceVendor(null); }} />
        <ConfirmDialog
          open={deleteVendor !== null}
          title="Delete vendor?"
          description={deleteVendor ? `Delete ${deleteVendor.name}? Vendors with products cannot be deleted.` : undefined}
          confirmLabel="Delete"
          destructive
          isPending={deleteMutation.isPending}
          onCancel={() => setDeleteVendor(null)}
          onConfirm={() => {
            if (deleteVendor) deleteMutation.mutate(deleteVendor.id, { onSettled: () => setDeleteVendor(null) });
          }}
        />
      </div>
    </CapabilityGate>
  );
}
