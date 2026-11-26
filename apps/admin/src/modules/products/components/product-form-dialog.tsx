"use client";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { ProductForm } from "@/modules/products/components/product-form";
import { useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { useI18n } from "@/providers/i18n-provider";
import type { components } from "@/types/api";
import type { CreateProductFormData, UpdateProductFormData } from "@/modules/products/schemas/product.schema";

type Product = components["schemas"]["Product"];

interface ProductFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  product?: Product;
  onSubmit: (data: CreateProductFormData | UpdateProductFormData) => void;
  isPending: boolean;
}

export function ProductFormDialog({ open, onOpenChange, product, onSubmit, isPending }: ProductFormDialogProps) {
  const { t } = useI18n();
  const [dirty, setDirty] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);
  const handleOpenChange = (nextOpen: boolean) => {
    if (!nextOpen && dirty) {
      setConfirmClose(true);
      return;
    }
    onOpenChange(nextOpen);
  };

  return (
    <>
      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent
          className="max-w-[calc(100%-2rem)] sm:max-w-2xl"
          onInteractOutside={(event) => { if (confirmClose) event.preventDefault(); }}
          onEscapeKeyDown={(event) => { if (confirmClose) event.preventDefault(); }}
        >
          <DialogHeader>
            <DialogTitle>{product ? t("products.edit") : t("products.create")}</DialogTitle>
            <DialogDescription>
              {product ? t("products.edit") : t("products.create")}
            </DialogDescription>
          </DialogHeader>
          <ProductForm product={product} onSubmit={onSubmit} isPending={isPending} onDirtyChange={setDirty} />
        </DialogContent>
      </Dialog>
      <ConfirmDialog
        open={confirmClose}
        title="Discard unsaved changes?"
        description={t("common.unsavedChanges")}
        confirmLabel="Discard"
        destructive
        onCancel={() => setConfirmClose(false)}
        onConfirm={() => {
          setConfirmClose(false);
          setDirty(false);
          onOpenChange(false);
        }}
      />
    </>
  );
}
