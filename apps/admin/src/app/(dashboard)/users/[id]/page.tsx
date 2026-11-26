"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useState } from "react";
import { ArrowLeft, Pencil, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { PageHeader } from "@/components/page-header";
import { StatusBadge } from "@/components/status-badge";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/error-state";
import { EmptyState } from "@/components/empty-state";
import { DeleteDialog } from "@/components/delete-dialog";
import { CapabilityGate } from "@/components/capability-gate";
import { UserFormDialog } from "@/modules/users/components/user-form-dialog";
import { useDeleteUser, useUpdateUser, useUser } from "@/hooks/use-users";
import { useI18n } from "@/providers/i18n-provider";
import { formatDate } from "@/lib/utils";
import type { components } from "@/types/api";
import type { CreateUserFormData, UpdateUserFormData } from "@/modules/users/schemas/user.schema";

type User = components["schemas"]["User"];

function UserDetail({ id }: { id: number }) {
  const { t } = useI18n();
  const router = useRouter();
  const { data: user, isLoading, isError, error, refetch } = useUser(id);
  const [editing, setEditing] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const updateMutation = useUpdateUser(id);
  const deleteMutation = useDeleteUser();

  const handleEditSubmit = useCallback(
    (data: CreateUserFormData | UpdateUserFormData) => {
      updateMutation.mutate(data as components["schemas"]["UpdateUserRequest"], {
        onSuccess: () => setEditing(false),
      });
    },
    [updateMutation],
  );

  const handleDelete = useCallback(() => {
    deleteMutation.mutate(id, {
      onSuccess: () => router.push("/users"),
    });
  }, [deleteMutation, id, router]);

  if (isLoading) {
    return (
      <div className="space-y-4" role="status" aria-label="Loading user">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (isError) {
    return <ErrorState message={(error as Error)?.message} onRetry={() => refetch()} />;
  }

  if (!user) {
    return <EmptyState title={t("common.noData")} description={t("users.title")} />;
  }

  const detail: User = user;

  return (
    <div className="space-y-4">
      <PageHeader title={detail.name || detail.email || t("users.title")} description={t("users.title")}>
        <Button variant="outline" onClick={() => setEditing(true)}>
          <Pencil className="mr-2 h-4 w-4" />
          {t("common.edit")}
        </Button>
        <Button variant="destructive" onClick={() => setConfirmDelete(true)}>
          <Trash2 className="mr-2 h-4 w-4" />
          {t("common.delete")}
        </Button>
      </PageHeader>

      <Card>
        <CardHeader>
          <CardTitle>{detail.name || detail.email}</CardTitle>
          <CardDescription>
            {t("users.createdAt")}: {formatDate(detail.created_at ?? "")}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-4 sm:grid-cols-2">
            <div>
              <dt className="text-xs text-muted-foreground">{t("users.name")}</dt>
              <dd className="font-medium">{detail.name}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">{t("users.email")}</dt>
              <dd className="font-medium">{detail.email}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">{t("users.role")}</dt>
              <dd>
                <StatusBadge status={detail.role} />
              </dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">{t("common.status")}</dt>
              <dd>
                <StatusBadge status={detail.status} />
              </dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <Link href="/users" className="inline-flex items-center gap-2 text-sm text-primary hover:underline">
        <ArrowLeft className="h-4 w-4" />
        {t("common.back")}
      </Link>

      {editing && (
        <UserFormDialog
          open={editing}
          onOpenChange={setEditing}
          user={detail}
          onSubmit={handleEditSubmit}
          isPending={updateMutation.isPending}
        />
      )}

      <DeleteDialog open={confirmDelete} onOpenChange={setConfirmDelete} onConfirm={handleDelete} isPending={deleteMutation.isPending} />
    </div>
  );
}

export default function UserDetailPage() {
  const params = useParams();
  const raw = Array.isArray(params.id) ? params.id[0] : params.id;
  const id = Number(raw);
  if (!Number.isInteger(id) || id <= 0) {
    return (
      <CapabilityGate capability="users">
        <EmptyState title="Invalid user" description="The requested user id is not valid." />
      </CapabilityGate>
    );
  }
  return (
    <CapabilityGate capability="users">
      <UserDetail id={id} />
    </CapabilityGate>
  );
}
