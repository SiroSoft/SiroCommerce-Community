import type { components } from "@/types/api";

type User = components["schemas"]["User"];
const fields = ["id", "name", "email", "role", "status", "created_at"] as const;

const csvCell = (value: unknown) => `"${String(value ?? "").replace(/"/g, '""')}"`;

export function usersToCsv(users: readonly User[]) {
  return [fields.join(","), ...users.map((user) => [user.id ?? "", csvCell(user.name), csvCell(user.email), user.role ?? "", user.status ?? "", user.created_at ?? ""].join(","))].join("\n");
}
