"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback } from "react";
import { ArrowLeft } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { UserForm } from "@/modules/users/components/user-form";
import { useCreateUser } from "@/hooks/use-users";
import { useI18n } from "@/providers/i18n-provider";
import type { components } from "@/types/api";
import type { CreateUserFormData, UpdateUserFormData } from "@/modules/users/schemas/user.schema";

export default function NewUserPage() {
  const { t } = useI18n();
  const router = useRouter();
  const createMutation = useCreateUser();

  const handleSubmit = useCallback(
    (data: CreateUserFormData | UpdateUserFormData) => {
      createMutation.mutate(data as components["schemas"]["CreateUserRequest"], {
        onSuccess: () => router.push("/users"),
      });
    },
    [createMutation, router],
  );

  return (
    <CapabilityGate capability="users">
      <div className="space-y-4">
        <PageHeader title={t("users.create")} description={t("users.title")} />
        <Card className="max-w-2xl">
          <CardHeader>
            <CardTitle>{t("users.create")}</CardTitle>
            <CardDescription>{t("users.title")}</CardDescription>
          </CardHeader>
          <CardContent>
            <UserForm
              onSubmit={handleSubmit}
              isPending={createMutation.isPending}
              onCancel={() => router.push("/users")}
            />
          </CardContent>
        </Card>
        <Link href="/users" className="inline-flex items-center gap-2 text-sm text-primary hover:underline">
          <ArrowLeft className="h-4 w-4" />
          {t("common.back")}
        </Link>
      </div>
    </CapabilityGate>
  );
}
