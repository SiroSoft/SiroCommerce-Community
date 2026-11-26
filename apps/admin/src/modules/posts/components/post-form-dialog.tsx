"use client";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PostForm } from "@/modules/posts/components/post-form";
import { useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { useI18n } from "@/providers/i18n-provider";
import type { components } from "@/types/api";
import type { CreatePostFormData, UpdatePostFormData } from "@/modules/posts/schemas/post.schema";

type Post = components["schemas"]["Post"];

interface PostFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  post?: Post;
  onSubmit: (data: CreatePostFormData | UpdatePostFormData) => void;
  isPending: boolean;
}

export function PostFormDialog({ open, onOpenChange, post, onSubmit, isPending }: PostFormDialogProps) {
  const { t } = useI18n();
  const [dirty, setDirty] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);
  const handleOpenChange = (nextOpen: boolean) => {
    if (!nextOpen && dirty) {
      setConfirmClose(true);
      return;
    }
    onOpenChange(nextOpen);
  };

  return (
    <>
      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent
          className="max-w-[calc(100%-2rem)] sm:max-w-lg"
          onInteractOutside={(event) => { if (confirmClose) event.preventDefault(); }}
          onEscapeKeyDown={(event) => { if (confirmClose) event.preventDefault(); }}
        >
          <DialogHeader>
            <DialogTitle>{post ? t("posts.edit") : t("posts.create")}</DialogTitle>
            <DialogDescription>
              {post ? t("posts.edit") : t("posts.create")}
            </DialogDescription>
          </DialogHeader>
          <PostForm post={post} onSubmit={onSubmit} isPending={isPending} onDirtyChange={setDirty} />
        </DialogContent>
      </Dialog>
      <ConfirmDialog
        open={confirmClose}
        title="Discard unsaved changes?"
        description={t("common.unsavedChanges")}
        confirmLabel="Discard"
        destructive
        onCancel={() => setConfirmClose(false)}
        onConfirm={() => {
          setConfirmClose(false);
          setDirty(false);
          onOpenChange(false);
        }}
      />
    </>
  );
}
