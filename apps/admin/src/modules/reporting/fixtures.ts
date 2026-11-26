export type ExportStatus = "queued" | "running" | "completed" | "failed";

export const exportStatusFixtures: ReadonlyArray<{
  id: string;
  resource: string;
  status: ExportStatus;
  updatedAt: string;
}> = [
  { id: "fixture-001", resource: "Catalog products", status: "completed", updatedAt: "2026-09-23T09:00:00Z" },
  { id: "fixture-002", resource: "Content posts", status: "queued", updatedAt: "2026-09-23T09:05:00Z" },
];
