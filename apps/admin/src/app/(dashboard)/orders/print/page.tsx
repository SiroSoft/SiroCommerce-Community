"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { ordersService } from "@/services/orders.service";
import { formatCurrency, formatDate } from "@/lib/utils";

type PrintOrder = {
  id?: number | string;
  order_number?: string;
  customer_email?: string;
  user_name?: string;
  status?: string;
  total?: number;
  subtotal?: number;
  discount?: number;
  shipping?: number;
  tax?: number;
  shipping_address?: string;
  created_at?: string;
  items?: Array<{ id?: number | string; product_name?: string; name?: string; product_id?: number | string; quantity?: number; subtotal?: number; unit_price?: number }>;
  payments?: Array<{ provider?: string; state?: string; amount?: number }>;
};

function orderTitle(order: PrintOrder): string {
  return `Order #${order.order_number ?? order.id ?? "unknown"}`;
}

function PackingSlip({ order }: { order: PrintOrder }) {
  return (
    <section className="print-doc" aria-label={`Packing slip ${orderTitle(order)}`}>
      <header className="print-doc-head">
        <div>
          <h2>Packing slip</h2>
          <p>{orderTitle(order)} · {formatDate(order.created_at)}</p>
        </div>
        <div className="print-doc-meta">
          <p>{order.customer_email || order.user_name || "Customer unavailable"}</p>
          {order.shipping_address && <p>{order.shipping_address}</p>}
        </div>
      </header>
      <table className="print-table">
        <thead>
          <tr>
            <th scope="col">Item</th>
            <th scope="col">Qty</th>
            <th scope="col" aria-label="Packed">☐</th>
          </tr>
        </thead>
        <tbody>
          {(order.items ?? []).map((item, index) => (
            <tr key={String(item.id ?? `${item.product_id}-${index}`)}>
              <td>{item.product_name || item.name || `Product #${item.product_id}`}</td>
              <td>{item.quantity ?? 0}</td>
              <td aria-hidden="true">☐</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function Invoice({ order }: { order: PrintOrder }) {
  const payment = order.payments?.[0];
  return (
    <section className="print-doc" aria-label={`Invoice ${orderTitle(order)}`}>
      <header className="print-doc-head">
        <div>
          <h2>Invoice</h2>
          <p>{orderTitle(order)} · {formatDate(order.created_at)}</p>
        </div>
        <div className="print-doc-meta">
          <p>{order.customer_email || order.user_name || "Customer unavailable"}</p>
          <p>Status: {order.status ?? "unknown"}</p>
          {payment && <p>Paid via {payment.provider ?? "unknown"}: {formatCurrency(payment.amount ?? 0)}</p>}
        </div>
      </header>
      <table className="print-table">
        <thead>
          <tr>
            <th scope="col">Item</th>
            <th scope="col">Qty</th>
            <th scope="col">Amount</th>
          </tr>
        </thead>
        <tbody>
          {(order.items ?? []).map((item, index) => (
            <tr key={String(item.id ?? `${item.product_id}-${index}`)}>
              <td>{item.product_name || item.name || `Product #${item.product_id}`}</td>
              <td>{item.quantity ?? 0}</td>
              <td>{formatCurrency(item.subtotal ?? item.unit_price ?? 0)}</td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <td colSpan={2}>Total</td>
            <td>{formatCurrency(order.total ?? 0)}</td>
          </tr>
        </tfoot>
      </table>
    </section>
  );
}

function PrintView() {
  const params = useSearchParams();
  const type = params.get("type") === "invoice" ? "invoice" : "packing";
  const ids = (params.get("ids") ?? "").split(",").map((value) => value.trim()).filter(Boolean).slice(0, 50);
  const [orders, setOrders] = useState<PrintOrder[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  useEffect(() => {
    let cancelled = false;
    if (ids.length === 0) {
      setState("ready");
      return;
    }
    setState("loading");
    void Promise.all(ids.map((id) => ordersService.get(Number(id)).catch(() => null))).then((results) => {
      if (cancelled) return;
      setOrders(results.filter((order): order is Exclude<typeof order, null> => order !== null).map((order) => order as unknown as PrintOrder));
      setState("ready");
    });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.toString()]);

  return (
    <div className="print-area space-y-6">
      <div className="no-print flex flex-wrap items-center gap-3">
        <Link href="/orders" className="text-sm text-muted-foreground hover:text-foreground">← Back to orders</Link>
        <h1 className="text-lg font-semibold">
          {type === "invoice" ? "Invoices" : "Packing slips"} ({orders.length})
        </h1>
        <Button size="sm" onClick={() => window.print()} disabled={state !== "ready" || orders.length === 0}>
          Print
        </Button>
      </div>
      {state === "loading" && <p role="status">Loading documents…</p>}
      {state === "ready" && orders.length === 0 && <p className="text-sm text-muted-foreground">No orders selected. Select orders in the list first.</p>}
      {orders.map((order) => (type === "invoice" ? <Invoice key={String(order.id)} order={order} /> : <PackingSlip key={String(order.id)} order={order} />))}
    </div>
  );
}

export default function OrderPrintPage() {
  return (
    <Suspense fallback={<p role="status">Loading documents…</p>}>
      <PrintView />
    </Suspense>
  );
}
