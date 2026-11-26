"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { cmsService, type CmsBlockWrite, type CmsPageWrite } from "@/services/cms.service";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";
import { scopedQueryKey } from "@/services/query-keys";
import { toast } from "@/hooks/use-toast";

const RESOURCE = "cms-pages";

export function useCmsPages() {
  const query = useQuery({
    queryKey: scopedQueryKey(RESOURCE, "list"),
    queryFn: cmsService.list,
    retry: isRetryableQueryError,
    retryDelay,
  });
  return {
    pages: query.data ?? [],
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useCmsPagePreview(id?: string) {
  return useQuery({
    queryKey: scopedQueryKey(RESOURCE, "preview", id),
    queryFn: () => cmsService.preview(id as string),
    enabled: Boolean(id),
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useCmsPageRevisions(id?: string) {
  return useQuery({
    queryKey: scopedQueryKey(RESOURCE, "revisions", id),
    queryFn: () => cmsService.revisions("page", id as string),
    enabled: Boolean(id),
    retry: false,
  });
}

function useCmsMutation<T, R>(mutationFn: (value: T) => Promise<R>, successMessage: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: [RESOURCE] });
      toast({ title: successMessage });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, RESOURCE, error);
      toast({ title: "Error", description: "The CMS operation failed.", variant: "destructive" });
    },
  });
}

export function useCreateCmsPage() {
  return useCmsMutation((data: CmsPageWrite) => cmsService.create(data), "Page created");
}

export function useUpdateCmsPage(id: string) {
  return useCmsMutation((data: Partial<CmsPageWrite>) => cmsService.update(id, data), "Page updated");
}

export function useAddCmsBlock(pageId: string) {
  return useCmsMutation((data: CmsBlockWrite) => cmsService.addBlock(pageId, data), "Block created");
}

export function useUpdateCmsBlock() {
  return useCmsMutation((value: { id: string; data: CmsBlockWrite }) => cmsService.updateBlock(value.id, value.data), "Block updated");
}

export function usePublishCmsPage() {
  return useCmsMutation((value: { id: string }) => cmsService.publish(value.id, idempotencyKey()), "Page published");
}

export function useRestoreCmsRevision() {
  return useCmsMutation((value: { id: string; revision: string }) => cmsService.restore(value.id, value.revision, idempotencyKey()), "Revision restored");
}
