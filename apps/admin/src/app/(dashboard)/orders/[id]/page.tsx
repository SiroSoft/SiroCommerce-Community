import { OrderDetail } from "@/modules/merchant/order-ui";
import { OrderDownloadPanel, OrderPaymentProofPanel, OrderRefundLinesPanel, OrderReturnPanel, OrderTimelinePanel } from "./panels";

// The runtime serves order IDs that are returned by the API; the export target
// is intentionally not supported because it cannot provide arbitrary details.
export const dynamicParams = true;

export default async function OrderDetailRoute({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const orderId = Number(id);
  return (
    <div className="space-y-5">
      <OrderDetail id={orderId} />
      <div className="grid gap-5 lg:grid-cols-2" id="order-panels">
        <OrderPaymentProofPanel orderId={orderId} />
        <OrderTimelinePanel orderId={orderId} />
        <OrderRefundLinesPanel orderId={orderId} />
        <OrderReturnPanel orderId={orderId} />
        <OrderDownloadPanel orderId={orderId} />
      </div>
    </div>
  );
}
