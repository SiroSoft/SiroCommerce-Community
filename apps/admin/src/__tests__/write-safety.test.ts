import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  confirmAction,
  parseNonNegativeInt,
  parsePositiveInt,
  validateMinorAmount,
  validateRequiredId,
} from "@/lib/write-safety";
import { productsService } from "@/services/products.service";
import { categoriesService } from "@/services/categories.service";
import { tagsService } from "@/services/tags.service";
import { usersService } from "@/services/users.service";
import { postsService } from "@/services/posts.service";
import { settingsService } from "@/services/settings.service";
import { profileService } from "@/services/profile.service";
import { campaignAutomationService } from "@/services/campaign-automation.service";
import { adminWorkbenchService } from "@/services/admin-workbench.service";
import { storefrontThemeService } from "@/services/storefront-theme.service";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));

describe("write-safety input validators", () => {
  it("accepts only positive integers for quantities", () => {
    expect(parsePositiveInt("1")).toBe(1);
    expect(parsePositiveInt(" 42 ")).toBe(42);
    expect(parsePositiveInt("0")).toBeNull();
    expect(parsePositiveInt("-3")).toBeNull();
    expect(parsePositiveInt("1.5")).toBeNull();
    expect(parsePositiveInt("abc")).toBeNull();
    expect(parsePositiveInt("")).toBeNull();
  });

  it("accepts zero for non-negative integers but rejects decimals", () => {
    expect(parseNonNegativeInt("0")).toBe(0);
    expect(parseNonNegativeInt("7")).toBe(7);
    expect(parseNonNegativeInt("-1")).toBeNull();
    expect(parseNonNegativeInt("2.5")).toBeNull();
    expect(parseNonNegativeInt("")).toBeNull();
  });

  it("validates minor-unit money as required positive integers", () => {
    expect(validateMinorAmount("500", "Unit price")).toEqual({ ok: true, value: 500 });
    expect(validateMinorAmount("", "Unit price").ok).toBe(false);
    expect(validateMinorAmount("0", "Unit price").ok).toBe(false);
    expect(validateMinorAmount("-5", "Unit price").ok).toBe(false);
    expect(validateMinorAmount("10.5", "Unit price").ok).toBe(false);
    expect(validateMinorAmount("NaN", "Unit price").ok).toBe(false);
    const missing = validateMinorAmount("", "Unit price");
    expect(missing.ok).toBe(false);
    if (!missing.ok) expect(missing.error).toMatch(/required/i);
  });

  it("requires identifiers before transport", () => {
    expect(validateRequiredId("c-1", "Organization ID")).toEqual({ ok: true, value: "c-1" });
    expect(validateRequiredId("  ", "Organization ID").ok).toBe(false);
  });

  it("routes destructive confirmations through window.confirm", () => {
    const spy = vi.spyOn(window, "confirm").mockReturnValue(true);
    expect(confirmAction("Delete?")).toBe(true);
    expect(spy).toHaveBeenCalledWith("Delete?");
    spy.mockReturnValueOnce(false);
    expect(confirmAction("Delete?")).toBe(false);
    spy.mockRestore();
  });
});

describe("catalog, identity, and settings mutations send idempotency keys", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    apiMock.post.mockResolvedValue({ data: { data: { id: 1 } } });
    apiMock.put.mockResolvedValue({ data: { data: { id: 1 } } });
    apiMock.delete.mockResolvedValue({ data: { data: {} } });
  });

  it("creates, updates, deletes, and transitions products with keys", async () => {
    const product = { id: 1, name: "Lamp", sku: "L-1", price: 10 };
    apiMock.post.mockResolvedValue({ data: { data: product } });
    apiMock.put.mockResolvedValue({ data: { data: product } });
    await productsService.create({ name: "Lamp", sku: "L-1", price: 10 } as never, "p-create");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products", expect.anything(), { idempotencyKey: "p-create" });
    await productsService.update(1, { name: "Lamp" } as never, "p-update");
    expect(apiMock.put).toHaveBeenCalledWith("/api/products/1", expect.anything(), { idempotencyKey: "p-update" });
    await productsService.delete(1, "p-delete");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/products/1", { idempotencyKey: "p-delete" });
    await productsService.transition(1, "archived", undefined, "p-lifecycle");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/1/lifecycle", { state: "archived", version: undefined }, { idempotencyKey: "p-lifecycle" });
  });

  it("sends keys for categories, tags, users, and posts", async () => {
    await categoriesService.create({ name: "Apparel" } as never, "c-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/categories", { name: "Apparel" }, { idempotencyKey: "c-1" });
    await categoriesService.update(2, { name: "Apparel" } as never, "c-2");
    expect(apiMock.put).toHaveBeenCalledWith("/api/categories/2", { name: "Apparel" }, { idempotencyKey: "c-2" });
    await categoriesService.delete(2, "c-3");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/categories/2", { idempotencyKey: "c-3" });
    await tagsService.create({ name: "Summer" } as never, "t-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/tags", { name: "Summer" }, { idempotencyKey: "t-1" });
    await tagsService.update(3, { name: "Summer" } as never, "t-2");
    expect(apiMock.put).toHaveBeenCalledWith("/api/tags/3", { name: "Summer" }, { idempotencyKey: "t-2" });
    await tagsService.delete(3, "t-3");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/tags/3", { idempotencyKey: "t-3" });
    await usersService.create({ name: "Ann", email: "a@x.test" } as never, "u-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/users", expect.anything(), { idempotencyKey: "u-1" });
    await usersService.update(4, { name: "Ann" } as never, "u-2");
    expect(apiMock.put).toHaveBeenCalledWith("/api/users/4", expect.anything(), { idempotencyKey: "u-2" });
    await usersService.delete(4, "u-3");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/users/4", { idempotencyKey: "u-3" });
    await postsService.create({ title: "Hello" } as never, "po-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/posts", { title: "Hello" }, { idempotencyKey: "po-1" });
    await postsService.update(5, { title: "Hello" } as never, "po-2");
    expect(apiMock.put).toHaveBeenCalledWith("/api/posts/5", { title: "Hello" }, { idempotencyKey: "po-2" });
    await postsService.delete(5, "po-3");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/posts/5", { idempotencyKey: "po-3" });
  });

  it("sends keys for settings, profile, password, and campaign writes", async () => {
    await settingsService.update({ app_name: "Shop" } as never, "s-1");
    expect(apiMock.put).toHaveBeenCalledWith("/api/settings", { app_name: "Shop" }, { idempotencyKey: "s-1" });
    await profileService.update({ name: "Ann" } as never, "pr-1");
    expect(apiMock.put).toHaveBeenCalledWith("/api/profile", { name: "Ann" }, { idempotencyKey: "pr-1" });
    await profileService.changePassword({ current_password: "a", new_password: "b1234567", new_password_confirmation: "b1234567" } as never, "pw-1");
    expect(apiMock.put).toHaveBeenCalledWith("/api/profile/password", expect.anything(), { idempotencyKey: "pw-1" });
    await campaignAutomationService.update({ enabled: true, max_messages_per_day: 10, quiet_hours_start: 21, quiet_hours_end: 8, allowed_channels: ["email"] }, "cam-1");
    expect(apiMock.put).toHaveBeenCalledWith("/api/v2/admin/campaign-automation", expect.anything(), { idempotencyKey: "cam-1" });
  });

  it("sends keys for workbench views, permissions, and workflows", async () => {
    await adminWorkbenchService.saveView({ name: "V", resource: "orders", filters: {} }, "w-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/workbench/views", expect.anything(), { idempotencyKey: "w-1" });
    await adminWorkbenchService.deleteView("v-1", "w-2");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/v2/admin/workbench/views/v-1", { idempotencyKey: "w-2" });
    await adminWorkbenchService.savePermissions("viewer", ["orders"], "w-3");
    expect(apiMock.put).toHaveBeenCalledWith("/api/v2/admin/workbench/permissions/viewer", { permissions: ["orders"] }, { idempotencyKey: "w-3" });
    await adminWorkbenchService.createWorkflow({ name: "W", resource: "orders", action: "refund", steps: ["owner"] }, "w-4");
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/workbench/workflows", expect.anything(), { idempotencyKey: "w-4" });
  });

  it("sends keys for storefront draft saves and previews", async () => {
    await storefrontThemeService.saveDraft({ expected_version: 2 } as never, "th-1");
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/storefront/config", { expected_version: 2 }, { idempotencyKey: "th-1" });
    await storefrontThemeService.preview({ expected_version: 2 } as never, "th-2");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/storefront/config/preview", { expected_version: 2 }, { idempotencyKey: "th-2" });
  });
});
