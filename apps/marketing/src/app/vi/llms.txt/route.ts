import { llmsText } from "@/lib/llms";

export const dynamic = "force-static";

export function GET() {
  return new Response(llmsText("vi"), { headers: { "content-type": "text/plain; charset=utf-8" } });
}
