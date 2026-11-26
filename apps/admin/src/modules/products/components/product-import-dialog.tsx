"use client";

import { useState } from "react";
import { AlertCircle, CheckCircle2, FileUp } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { useImportProducts } from "@/hooks/use-products";

const MAX_BYTES = 2 * 1024 * 1024;

interface ProductImportDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function ProductImportDialog({ open, onOpenChange }: ProductImportDialogProps) {
  const importMutation = useImportProducts();
  const [file, setFile] = useState<File | null>(null);
  const [validationError, setValidationError] = useState("");
  const result = importMutation.data;

  const reset = () => {
    setFile(null);
    setValidationError("");
    importMutation.reset();
  };

  const handleOpenChange = (next: boolean) => {
    if (!next) reset();
    onOpenChange(next);
  };

  const run = (dryRun: boolean) => {
    if (!file) {
      setValidationError("Choose a CSV file to import.");
      return;
    }
    if (file.size > MAX_BYTES) {
      setValidationError("CSV file must be 2MB or smaller.");
      return;
    }
    setValidationError("");
    importMutation.mutate({ file, dryRun });
  };

  return <Dialog open={open} onOpenChange={handleOpenChange}>
    <DialogContent className="sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>Import products from CSV</DialogTitle>
        <DialogDescription>
          Upsert by SKU. Maximum 2MB and 500 data rows. Use Dry run to validate without writing.
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-4">
        <label className="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center text-sm hover:bg-muted/40">
          <FileUp className="h-6 w-6 text-muted-foreground" aria-hidden="true" />
          <span className="font-medium">{file ? file.name : "Choose a CSV file"}</span>
          <span className="text-xs text-muted-foreground">Columns: sku,name,slug,status,price_minor,compare_price_minor,stock,description,category,min_tier,allow_backorder,is_preorder</span>
          <input
            type="file"
            accept=".csv,text/csv"
            className="sr-only"
            aria-label="Product CSV file"
            onChange={(event) => {
              const next = event.target.files?.[0] ?? null;
              setFile(next);
              setValidationError(next && next.size > MAX_BYTES ? "CSV file must be 2MB or smaller." : "");
              importMutation.reset();
            }}
          />
        </label>

        {validationError && <p role="alert" className="flex items-center gap-2 text-sm text-destructive"><AlertCircle className="h-4 w-4" />{validationError}</p>}

        {result && <div className="space-y-3 rounded-md border p-3" role="status">
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-hidden="true" />
            <span className="font-medium">{result.dry_run ? "Dry run result" : "Import result"}</span>
            <span>{result.created} created</span>
            <span>{result.updated} updated</span>
            <span>{result.skipped} skipped</span>
            <span className={result.errors.length ? "font-medium text-destructive" : ""}>{result.errors.length} error(s)</span>
          </div>
          {result.errors.length > 0 && <div className="max-h-48 overflow-auto rounded border">
            <table className="w-full text-left text-xs">
              <thead className="bg-muted/60"><tr><th className="p-2">Row</th><th className="p-2">Error</th></tr></thead>
              <tbody>{result.errors.map((error, index) => <tr key={`${error.row}-${index}`} className="border-t"><td className="p-2 font-mono">{error.row}</td><td className="p-2">{error.error}</td></tr>)}</tbody>
            </table>
          </div>}
        </div>}

        {importMutation.isError && <p role="alert" className="text-sm text-destructive">{importMutation.error instanceof Error ? importMutation.error.message : "The import request failed."}</p>}
      </div>

      <DialogFooter>
        <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={importMutation.isPending}>Close</Button>
        <Button type="button" variant="outline" onClick={() => run(true)} disabled={!file || importMutation.isPending}>{importMutation.isPending ? "Checking..." : "Dry run"}</Button>
        <Button type="button" onClick={() => run(false)} disabled={!file || importMutation.isPending}>{importMutation.isPending ? "Importing..." : "Import"}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>;
}
