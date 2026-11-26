"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { ArrowLeft } from "lucide-react";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { ProductForm } from "@/modules/products/components/product-form";
import { useProduct, useUpdateProduct } from "@/hooks/use-products";
import { useI18n } from "@/providers/i18n-provider";
import { ApiState } from "@/modules/merchant/api-state";
import type { UpdateProductFormData } from "@/modules/products/schemas/product.schema";
import { ProductOptionsEditor } from "@/modules/products/components/product-options-editor";
import { ProductMediaManager } from "@/modules/products/components/product-media-manager";
import { ProductVariantsManager } from "@/modules/products/components/product-variants-manager";
import { BookingSlotsManager } from "@/modules/products/components/booking-slots-manager";

export function ProductDetail({ id }: { id: number }) {
  const { t } = useI18n();
  const router = useRouter();
  const query = useProduct(id);
  const mutation = useUpdateProduct(id);
  const [dirty, setDirty] = useState(false);
  const [leaveConfirmOpen, setLeaveConfirmOpen] = useState(false);

  useEffect(() => {
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", onBeforeUnload);
    return () => window.removeEventListener("beforeunload", onBeforeUnload);
  }, [dirty]);

  if (query.isLoading) return <p role="status" className="py-12 text-center text-muted-foreground">Loading product...</p>;
  if (query.isError) return <ApiState error={query.error} onRetry={() => void query.refetch()} />;
  if (!query.data) return <ApiState error={new Error("Product not found")} />;

  const product = query.data;
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <PageHeader title={product.name ?? `Product ${id}`} description="Catalog detail and supported fields">
        <Button asChild variant="outline"><Link href="/products" onClick={(event) => {
          if (!dirty) return;
          event.preventDefault();
          setLeaveConfirmOpen(true);
        }}><ArrowLeft className="mr-2 h-4 w-4" />Back to products</Link></Button>
      </PageHeader>
      <Card>
        <CardContent className="pt-6">
          <ProductForm
            product={product}
            isPending={mutation.isPending}
            onDirtyChange={setDirty}
            onSubmit={(data) => mutation.mutate(data as UpdateProductFormData, { onSuccess: () => setDirty(false) })}
          />
        </CardContent>
      </Card>
      <ProductOptionsEditor productId={id} />
      <ProductVariantsManager productId={id} />
      <ProductMediaManager productId={id} />
      <BookingSlotsManager productId={id} />
      {mutation.isError && <ApiState error={mutation.error} />}
      {mutation.isSuccess && <p role="status" className="rounded-md border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm">Product saved successfully.</p>}
      <p className="text-xs text-muted-foreground">Changes are validated against the generated UpdateProductRequest contract.</p>
      <ConfirmDialog
        open={leaveConfirmOpen}
        title="Leave product?"
        description={t("common.unsavedChanges")}
        onCancel={() => setLeaveConfirmOpen(false)}
        onConfirm={() => {
          setLeaveConfirmOpen(false);
          router.push("/products");
        }}
      />
    </div>
  );
}
