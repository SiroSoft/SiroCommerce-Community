import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const cmsServiceMock = vi.hoisted(() => ({
  list: vi.fn(), create: vi.fn(), update: vi.fn(), preview: vi.fn(), addBlock: vi.fn(),
  updateBlock: vi.fn(), publish: vi.fn(), restore: vi.fn(), revisions: vi.fn(),
}));
const promotionRulesServiceMock = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() }));
const dashboardServiceMock = vi.hoisted(() => ({ getStats: vi.fn() }));
const settingsServiceMock = vi.hoisted(() => ({ get: vi.fn(), update: vi.fn() }));
const profileServiceMock = vi.hoisted(() => ({ get: vi.fn(), update: vi.fn(), changePassword: vi.fn() }));
const storefrontThemeServiceMock = vi.hoisted(() => ({
  getConfig: vi.fn(), themes: vi.fn(), presets: vi.fn(), revisions: vi.fn(), saveDraft: vi.fn(),
  preview: vi.fn(), applyPreset: vi.fn(), publish: vi.fn(), restore: vi.fn(),
}));
vi.mock("@/services/cms.service", () => ({ cmsService: cmsServiceMock }));
vi.mock("@/services/promotion-rules.service", () => ({ promotionRulesService: promotionRulesServiceMock }));
vi.mock("@/services/dashboard.service", () => ({ dashboardService: dashboardServiceMock }));
vi.mock("@/services/settings.service", () => ({ settingsService: settingsServiceMock }));
vi.mock("@/services/profile.service", () => ({ profileService: profileServiceMock }));
vi.mock("@/services/storefront-theme.service", () => ({ storefrontThemeService: storefrontThemeServiceMock }));

import {
  useAddCmsBlock, useCmsPagePreview, useCmsPageRevisions, useCmsPages, useCreateCmsPage,
  usePublishCmsPage, useRestoreCmsRevision, useUpdateCmsBlock, useUpdateCmsPage,
} from "@/hooks/use-cms-pages";
import {
  useCreatePromotionRule, useDeletePromotionRule, usePromotionRules, useUpdatePromotionRule,
} from "@/hooks/use-promotion-rules";
import { useDashboard } from "@/hooks/use-dashboard";
import { useSettings, useUpdateSettings } from "@/hooks/use-settings";
import { useChangePassword, useUpdateProfile } from "@/hooks/use-profile";
import {
  useApplyStorefrontPreset, usePreviewStorefrontTheme, usePublishStorefrontTheme,
  useRestoreStorefrontRevision, useSaveStorefrontTheme, useStorefrontRevisions,
  useStorefrontTheme, useStorefrontThemeOptions,
} from "@/hooks/use-storefront-theme";
import { useAuthStore } from "@/store/auth.store";

function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return { client, wrapper };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("cms hooks", () => {
  it("lists pages and loads preview/revisions only with an id", async () => {
    cmsServiceMock.list.mockResolvedValueOnce([{ id: "p1", slug: "about", title: "About" }]);
    const { wrapper } = setup();
    const list = renderHook(() => useCmsPages(), { wrapper });
    await waitFor(() => expect(list.result.current.pages).toEqual([{ id: "p1", slug: "about", title: "About" }]));

    const disabledPreview = renderHook(() => useCmsPagePreview(undefined), { wrapper });
    await waitFor(() => expect(disabledPreview.result.current.isLoading).toBe(false));
    expect(cmsServiceMock.preview).not.toHaveBeenCalled();

    cmsServiceMock.preview.mockResolvedValueOnce({ id: "p1", blocks: [] });
    const preview = renderHook(() => useCmsPagePreview("p1"), { wrapper });
    await waitFor(() => expect(preview.result.current.data).toEqual({ id: "p1", blocks: [] }));

    const disabledRevisions = renderHook(() => useCmsPageRevisions(undefined), { wrapper });
    await waitFor(() => expect(disabledRevisions.result.current.isLoading).toBe(false));
    cmsServiceMock.revisions.mockResolvedValueOnce([{ id: "r1" }]);
    const revisions = renderHook(() => useCmsPageRevisions("p1"), { wrapper });
    await waitFor(() => expect(revisions.result.current.data).toEqual([{ id: "r1" }]));
    expect(cmsServiceMock.revisions).toHaveBeenCalledWith("page", "p1");

    cmsServiceMock.list.mockResolvedValueOnce(null);
    const emptyClient = setup();
    const empty = renderHook(() => useCmsPages(), { wrapper: emptyClient.wrapper });
    await waitFor(() => expect(empty.result.current.isLoading).toBe(false));
    expect(empty.result.current.pages).toEqual([]);
  });

  it("invalidates the cms resource after every mutation and forwards idempotency keys", async () => {
    cmsServiceMock.create.mockResolvedValue({ id: "p1" });
    cmsServiceMock.update.mockResolvedValue({ id: "p1" });
    cmsServiceMock.addBlock.mockResolvedValue({ id: "b1" });
    cmsServiceMock.updateBlock.mockResolvedValue({ id: "b1" });
    cmsServiceMock.publish.mockResolvedValue({ id: "p1" });
    cmsServiceMock.restore.mockResolvedValue({ id: "p1" });
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const create = renderHook(() => useCreateCmsPage(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ slug: "about", title: "About" }); });
    expect(cmsServiceMock.create).toHaveBeenCalledWith({ slug: "about", title: "About" });

    const update = renderHook(() => useUpdateCmsPage("p1"), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ title: "New" }); });
    expect(cmsServiceMock.update).toHaveBeenCalledWith("p1", { title: "New" });

    const addBlock = renderHook(() => useAddCmsBlock("p1"), { wrapper });
    await act(async () => { await addBlock.result.current.mutateAsync({ type: "text", content: "hi" }); });
    expect(cmsServiceMock.addBlock).toHaveBeenCalledWith("p1", { type: "text", content: "hi" });

    const updateBlock = renderHook(() => useUpdateCmsBlock(), { wrapper });
    await act(async () => { await updateBlock.result.current.mutateAsync({ id: "b1", data: { sort: 2 } }); });
    expect(cmsServiceMock.updateBlock).toHaveBeenCalledWith("b1", { sort: 2 });

    const publish = renderHook(() => usePublishCmsPage(), { wrapper });
    await act(async () => { await publish.result.current.mutateAsync({ id: "p1" }); });
    expect(cmsServiceMock.publish).toHaveBeenCalledWith("p1", expect.any(String));

    const restore = renderHook(() => useRestoreCmsRevision(), { wrapper });
    await act(async () => { await restore.result.current.mutateAsync({ id: "p1", revision: "r2" }); });
    expect(cmsServiceMock.restore).toHaveBeenCalledWith("p1", "r2", expect.any(String));

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["cms-pages"] });
  });

  it("invalidates cms on a conflict from a mutation", async () => {
    cmsServiceMock.create.mockRejectedValueOnce(Object.assign(new Error("conflict"), { status: 409 }));
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const create = renderHook(() => useCreateCmsPage(), { wrapper });
    await act(async () => {
      await expect(create.result.current.mutateAsync({ slug: "about", title: "About" })).rejects.toThrow("conflict");
    });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["cms-pages"] });
  });
});

describe("promotion rule hooks", () => {
  it("lists rules and invalidates on create update delete", async () => {
    promotionRulesServiceMock.list.mockResolvedValueOnce([{ id: "r1", name: "Ten" }]);
    promotionRulesServiceMock.create.mockResolvedValue({ id: "r2" });
    promotionRulesServiceMock.update.mockResolvedValue({ id: "r2" });
    promotionRulesServiceMock.remove.mockResolvedValue(undefined);
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const list = renderHook(() => usePromotionRules(), { wrapper });
    await waitFor(() => expect(list.result.current.rules).toEqual([{ id: "r1", name: "Ten" }]));

    const create = renderHook(() => useCreatePromotionRule(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ name: "Ten", kind: "cart_percent", value: 10 }); });
    expect(promotionRulesServiceMock.create).toHaveBeenCalledWith({ name: "Ten", kind: "cart_percent", value: 10 });
    const update = renderHook(() => useUpdatePromotionRule(), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ id: "r2", data: { state: "disabled" } }); });
    expect(promotionRulesServiceMock.update).toHaveBeenCalledWith("r2", { state: "disabled" });
    const remove = renderHook(() => useDeletePromotionRule(), { wrapper });
    await act(async () => { await remove.result.current.mutateAsync("r2"); });
    expect(promotionRulesServiceMock.remove).toHaveBeenCalledWith("r2");
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["promotion-rules"] });

    promotionRulesServiceMock.list.mockResolvedValueOnce({});
    const empty = renderHook(() => usePromotionRules(), { wrapper: setup().wrapper });
    await waitFor(() => expect(empty.result.current.isLoading).toBe(false));
    expect(empty.result.current.rules).toEqual([]);
  });
});

describe("dashboard settings and profile hooks", () => {
  it("loads dashboard stats", async () => {
    dashboardServiceMock.getStats.mockResolvedValueOnce({ total_orders: 4 });
    const { wrapper } = setup();
    const { result } = renderHook(() => useDashboard(), { wrapper });
    await waitFor(() => expect(result.current.data).toEqual({ total_orders: 4 }));
  });

  it("loads settings and updates them with idempotency", async () => {
    settingsServiceMock.get.mockResolvedValueOnce({ store_name: "Siro" });
    settingsServiceMock.update.mockResolvedValue({ store_name: "Next" });
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const query = renderHook(() => useSettings(), { wrapper });
    await waitFor(() => expect(query.result.current.data).toEqual({ store_name: "Siro" }));

    const update = renderHook(() => useUpdateSettings(), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ store_name: "Next" } as never); });
    expect(settingsServiceMock.update).toHaveBeenCalledWith({ store_name: "Next" }, expect.any(String));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["settings"] });
  });

  it("updates the profile store and changes the password", async () => {
    profileServiceMock.update.mockResolvedValueOnce({ id: 7, name: "New name" });
    profileServiceMock.changePassword.mockResolvedValueOnce(undefined);
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const update = renderHook(() => useUpdateProfile(), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ name: "New name" } as never); });
    expect(profileServiceMock.update).toHaveBeenCalledWith({ name: "New name" }, expect.any(String));
    expect(useAuthStore.getState().user).toMatchObject({ id: 7, name: "New name" });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: expect.arrayContaining(["auth"]) });

    const password = renderHook(() => useChangePassword(), { wrapper });
    await act(async () => {
      await password.result.current.mutateAsync({
        current_password: "old", password: "new", password_confirmation: "new",
      } as never);
    });
    expect(profileServiceMock.changePassword).toHaveBeenCalledWith(expect.any(Object), expect.any(String));
  });
});

describe("storefront theme hooks", () => {
  it("loads config, combined options and revisions", async () => {
    storefrontThemeServiceMock.getConfig.mockResolvedValueOnce({ theme: "default" });
    storefrontThemeServiceMock.themes.mockResolvedValueOnce([{ key: "default" }]);
    storefrontThemeServiceMock.presets.mockResolvedValueOnce([{ key: "minimal" }]);
    storefrontThemeServiceMock.revisions.mockResolvedValueOnce([{ id: "rev-1" }]);
    const { wrapper } = setup();

    const config = renderHook(() => useStorefrontTheme(), { wrapper });
    await waitFor(() => expect(config.result.current.data).toEqual({ theme: "default" }));

    const options = renderHook(() => useStorefrontThemeOptions(), { wrapper });
    await waitFor(() => expect(options.result.current.data).toEqual([[{ key: "default" }], [{ key: "minimal" }]]));

    const revisions = renderHook(() => useStorefrontRevisions(), { wrapper });
    await waitFor(() => expect(revisions.result.current.data).toEqual([{ id: "rev-1" }]));
  });

  it("invalidates the theme key for every theme mutation", async () => {
    storefrontThemeServiceMock.saveDraft.mockResolvedValue({});
    storefrontThemeServiceMock.preview.mockResolvedValue({});
    storefrontThemeServiceMock.applyPreset.mockResolvedValue({});
    storefrontThemeServiceMock.publish.mockResolvedValue({});
    storefrontThemeServiceMock.restore.mockResolvedValue({});
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const save = renderHook(() => useSaveStorefrontTheme(), { wrapper });
    await act(async () => { await save.result.current.mutateAsync({ theme: "x" } as never); });
    expect(storefrontThemeServiceMock.saveDraft).toHaveBeenCalledWith({ theme: "x" }, expect.any(String));

    const preview = renderHook(() => usePreviewStorefrontTheme(), { wrapper });
    await act(async () => { await preview.result.current.mutateAsync({ theme: "x" } as never); });
    expect(storefrontThemeServiceMock.preview).toHaveBeenCalledWith({ theme: "x" }, expect.any(String));

    const preset = renderHook(() => useApplyStorefrontPreset(), { wrapper });
    await act(async () => { await preset.result.current.mutateAsync({ expected_version: 2, vertical_key: "fashion", key: "minimal" }); });
    expect(storefrontThemeServiceMock.applyPreset).toHaveBeenCalledWith(2, "fashion", "minimal");

    const publish = renderHook(() => usePublishStorefrontTheme(), { wrapper });
    await act(async () => { await publish.result.current.mutateAsync({ expected_version: 2, key: "pub" }); });
    expect(storefrontThemeServiceMock.publish).toHaveBeenCalledWith(2, "pub");

    const restore = renderHook(() => useRestoreStorefrontRevision(), { wrapper });
    await act(async () => { await restore.result.current.mutateAsync({ revision: "rev-1", expected_version: 2, key: "pub" }); });
    expect(storefrontThemeServiceMock.restore).toHaveBeenCalledWith("rev-1", 2, "pub");

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["storefront-theme"] });
  });
});
