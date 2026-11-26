import { ProductDetail } from "@/modules/products/product-detail";

export const dynamicParams = true;

export default async function ProductDetailRoute({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <ProductDetail id={Number(id)} />;
}
