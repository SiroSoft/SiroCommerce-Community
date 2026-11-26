"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { Vendor, VendorInput } from "@/services/vendors.service";

interface VendorFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  vendor?: Vendor;
  onSubmit: (data: VendorInput) => void;
  isPending: boolean;
}

export function VendorFormDialog({ open, onOpenChange, vendor, onSubmit, isPending }: VendorFormDialogProps) {
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [commissionBps, setCommissionBps] = useState("0");
  const [contactEmail, setContactEmail] = useState("");
  const [description, setDescription] = useState("");
  const [status, setStatus] = useState<"active" | "suspended">("active");

  useEffect(() => {
    if (!open) return;
    setName(vendor?.name ?? "");
    setSlug(vendor?.slug ?? "");
    setCommissionBps(String(vendor?.commission_bps ?? 0));
    setContactEmail(vendor?.contact_email ?? "");
    setDescription(vendor?.description ?? "");
    setStatus(vendor?.status ?? "active");
  }, [open, vendor]);

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    onSubmit({
      name,
      slug: slug.trim() === "" ? null : slug.trim(),
      commission_bps: Number(commissionBps || 0),
      contact_email: contactEmail.trim() === "" ? null : contactEmail.trim(),
      description: description.trim() === "" ? null : description,
      ...(vendor ? { status } : {}),
    });
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[calc(100%-2rem)] sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{vendor ? "Edit vendor" : "Create vendor"}</DialogTitle>
          <DialogDescription>Vendors own products without changing cart, order, or payment flows.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="vendor-name">Name</Label>
            <Input id="vendor-name" value={name} onChange={(event) => setName(event.target.value)} required disabled={isPending} />
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="vendor-slug">Slug</Label>
              <Input id="vendor-slug" value={slug} onChange={(event) => setSlug(event.target.value)} placeholder="auto from name" pattern="[a-z0-9][a-z0-9-]{0,79}" disabled={isPending} />
            </div>
            <div className="space-y-2">
              <Label htmlFor="vendor-commission">Commission (bps)</Label>
              <Input id="vendor-commission" type="number" min={0} max={10000} value={commissionBps} onChange={(event) => setCommissionBps(event.target.value)} disabled={isPending} />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="vendor-email">Contact email</Label>
            <Input id="vendor-email" type="email" value={contactEmail} onChange={(event) => setContactEmail(event.target.value)} disabled={isPending} />
          </div>
          {vendor && (
            <div className="space-y-2">
              <Label htmlFor="vendor-status">Status</Label>
              <select id="vendor-status" className="h-10 w-full rounded-md border bg-background px-3" value={status} onChange={(event) => setStatus(event.target.value === "suspended" ? "suspended" : "active")} disabled={isPending}>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
          )}
          <div className="space-y-2">
            <Label htmlFor="vendor-description">Description</Label>
            <Textarea id="vendor-description" value={description} onChange={(event) => setDescription(event.target.value)} disabled={isPending} />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isPending}>Cancel</Button>
            <Button type="submit" disabled={isPending || name.trim() === ""}>
              {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {vendor ? "Save vendor" : "Create vendor"}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
