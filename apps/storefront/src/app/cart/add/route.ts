import { NextResponse } from "next/server";
import { addVariantToCart, publicOrigin } from "@/lib/cart-server";

export const dynamic = "force-dynamic";

/**
 * POST-only bag mutation. The browser submits a variant id and quantity; the
 * commerce API resolves price, name and currency, then redirects to a clean
 * `/cart` URL so no cart state ever lives in the query string.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const origin = await publicOrigin();
  const bag = new URL("/cart", origin);
  const form = await request.formData().catch(() => undefined);
  const productId = String(form?.get("product_id") ?? "").trim();
  const rawQuantity = Number(form?.get("quantity") ?? 1);
  const quantity = Number.isSafeInteger(rawQuantity) && rawQuantity > 0 ? Math.min(99, rawQuantity) : 1;
  const slotStart = String(form?.get("slot_start") ?? "").trim() || undefined;
  // Reject missing/placeholder variants before any commerce mutation.
  if (!productId || productId.length > 120 || /^(undefined|null|nan)$/i.test(productId)) return NextResponse.redirect(bag, 303);
  if (slotStart && slotStart.length > 120) return NextResponse.redirect(bag, 303);
  try {
    await addVariantToCart(productId, quantity, slotStart);
  } catch (error) {
    console.error("cart_add_failed", error instanceof Error ? error.message : error);
    bag.searchParams.set("add", "failed");
    return NextResponse.redirect(bag, 303);
  }
  bag.searchParams.set("added", "1");
  return NextResponse.redirect(bag, 303);
}