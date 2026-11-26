import { OrderList } from "@/modules/merchant/order-ui";
import { CapabilityGate } from "@/components/capability-gate";
export default function OrdersPage() { return <CapabilityGate capability="orders"><OrderList /></CapabilityGate>; }
