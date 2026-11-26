<?php
declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Role;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Audit;
use Siro\Core\Database;

final class AdminWorkbenchService
{
    /** @return array<string,mixed> */
    public function bootstrap(CommerceScope $scope, int $userId, string $role): array
    {
        return ['capabilities' => Role::capabilities($role), 'roles' => Role::all(), 'permission_sets' => $this->permissionSets(), 'saved_views' => $this->views($scope, $userId), 'approval_workflows' => $this->workflows($scope)];
    }

    /** @param array<string,mixed> $data */
    public function saveView(CommerceScope $scope, int $userId, array $data): array
    {
        $name = trim(Arr::requiredStr($data, 'name')); $resource = trim(Arr::requiredStr($data, 'resource'));
        if ($name === '' || strlen($name) > 128 || $resource === '' || !is_array($data['filters'] ?? null)) throw new \InvalidArgumentException('A view name, resource and filters are required');
        $now = date('Y-m-d H:i:s'); $id = Ids::new();
        Database::connection()->prepare('INSERT INTO admin_saved_views (id,user_id,store_id,channel_id,resource,name,filters_json,columns_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$userId,$scope->storeId,$scope->channelId,$resource,$name,json_encode($data['filters'], JSON_THROW_ON_ERROR),json_encode(is_array($data['columns'] ?? null) ? $data['columns'] : [], JSON_THROW_ON_ERROR),$now,$now]);
        Audit::log('commerce.admin.saved_view.created', ['id' => $id, 'resource' => $resource, 'scope' => $scope->key(), 'actor_id' => $userId]);
        return $this->view($id, $scope, $userId) ?? throw new \RuntimeException('Saved view was not persisted');
    }

    public function deleteView(string $id, CommerceScope $scope, int $userId): void
    {
        $statement = Database::connection()->prepare('DELETE FROM admin_saved_views WHERE id=? AND user_id=? AND store_id=? AND channel_id=?');
        $statement->execute([$id,$userId,$scope->storeId,$scope->channelId]);
        if ($statement->rowCount() !== 1) throw new \InvalidArgumentException('Saved view not found');
        Audit::log('commerce.admin.saved_view.deleted', ['id' => $id, 'scope' => $scope->key(), 'actor_id' => $userId]);
    }

    /** @param array<string,mixed> $data */
    public function createWorkflow(CommerceScope $scope, int $userId, array $data): array
    {
        $name = trim(Arr::requiredStr($data, 'name')); $resource = trim(Arr::requiredStr($data, 'resource')); $action = trim(Arr::requiredStr($data, 'action'));
        $steps = $data['steps'] ?? [];
        if ($name === '' || $resource === '' || $action === '' || !is_array($steps) || count($steps) < 1 || count($steps) > 5) throw new \InvalidArgumentException('Workflow requires one to five approval steps');
        foreach ($steps as $step) if (!is_string($step) || !in_array($step, Role::all(), true)) throw new \InvalidArgumentException('Approval steps must contain valid roles');
        $id = Ids::new(); $now = date('Y-m-d H:i:s');
        Database::connection()->prepare('INSERT INTO admin_approval_workflows (id,store_id,channel_id,name,resource,action,steps_json,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$scope->storeId,$scope->channelId,$name,$resource,$action,json_encode(array_values($steps), JSON_THROW_ON_ERROR),1,$now,$now]);
        Audit::log('commerce.admin.approval_workflow.created', ['id' => $id, 'scope' => $scope->key(), 'actor_id' => $userId]);
        return $this->workflow($id, $scope) ?? throw new \RuntimeException('Workflow was not persisted');
    }

    /** @param array<string,mixed> $data */
    public function requestApproval(CommerceScope $scope, int $userId, array $data): array
    {
        $workflowId = Arr::requiredStr($data, 'workflow_id'); $resourceId = Arr::requiredStr($data, 'resource_id'); $workflow = $this->workflow($workflowId, $scope);
        if ($workflow === null) throw new \InvalidArgumentException('Approval workflow not found');
        $id = Ids::new(); $now = date('Y-m-d H:i:s');
        Database::connection()->prepare('INSERT INTO admin_approval_requests (id,workflow_id,store_id,channel_id,resource,resource_id,payload_json,state,requested_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$workflowId,$scope->storeId,$scope->channelId,$workflow['resource'],$resourceId,json_encode(is_array($data['payload'] ?? null) ? $data['payload'] : [], JSON_THROW_ON_ERROR),'pending',$userId,$now,$now]);
        Audit::log('commerce.admin.approval.requested', ['id' => $id, 'resource_id' => $resourceId, 'scope' => $scope->key(), 'actor_id' => $userId]);
        return $this->approval($id, $scope) ?? throw new \RuntimeException('Approval request was not persisted');
    }

    public function decideApproval(string $id, CommerceScope $scope, int $userId, string $role, string $decision): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) throw new \InvalidArgumentException('Unsupported approval decision');
        $row = $this->approval($id, $scope); if ($row === null) throw new \InvalidArgumentException('Approval request not found');
        if ($row['state'] !== 'pending') throw new \InvalidArgumentException('Approval request is already decided');
        $workflow = $this->workflow((string)$row['workflow_id'], $scope); $steps = is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [];
        if (!in_array($role, $steps, true)) throw new \RuntimeException('Role is not an approver for this workflow');
        Database::connection()->prepare('UPDATE admin_approval_requests SET state=?,approved_by=?,updated_at=? WHERE id=? AND state=?')->execute([$decision,$userId,date('Y-m-d H:i:s'),$id,'pending']);
        Audit::log('commerce.admin.approval.' . $decision, ['id' => $id, 'scope' => $scope->key(), 'actor_id' => $userId]);
        return $this->approval($id, $scope) ?? throw new \RuntimeException('Approval request was not persisted');
    }

    /** @return array<string,mixed> */
    public function permissionSets(): array
    {
        $out = []; foreach (Role::all() as $role) $out[$role] = Role::capabilities($role);
        foreach (Database::connection()->query('SELECT role,permissions_json FROM admin_permission_sets')->fetchAll() ?: [] as $row) if (is_array($row)) $out[(string)$row['role']] = json_decode((string)$row['permissions_json'], true) ?: [];
        return $out;
    }

    public static function allowsConfigured(string $role, string $capability): ?bool
    {
        if ($role === Role::OWNER) return true;
        try {
            $q = Database::connection()->prepare('SELECT permissions_json FROM admin_permission_sets WHERE role=?'); $q->execute([$role]); $permissions = $q->fetchColumn();
            if ($permissions === false) return null;
            $decoded = json_decode((string)$permissions, true);
            return is_array($decoded) && (in_array('*', $decoded, true) || in_array($capability, $decoded, true));
        } catch (\Throwable) { return null; }
    }

    /** @param array<string,mixed> $permissions */
    public function savePermissionSet(int $actorId, string $role, array $permissions): array
    {
        if (!in_array($role, Role::all(), true) || $role === Role::OWNER) throw new \InvalidArgumentException('Only non-owner roles can be configured');
        $allowed = array_values(array_intersect(array_unique(array_map('strval', $permissions)), array_keys(array_flip(Role::capabilities(Role::ADMIN))) ));
        $now = date('Y-m-d H:i:s'); $db = Database::connection(); $update = $db->prepare('UPDATE admin_permission_sets SET permissions_json=?,updated_at=? WHERE role=?'); $update->execute([json_encode($allowed, JSON_THROW_ON_ERROR),$now,$role]);
        if ($update->rowCount() === 0) $db->prepare('INSERT INTO admin_permission_sets (role,permissions_json,updated_at) VALUES (?,?,?)')->execute([$role,json_encode($allowed, JSON_THROW_ON_ERROR),$now]);
        Audit::log('commerce.admin.permission_set.updated', ['role' => $role, 'permissions' => $allowed, 'actor_id' => $actorId]); return [$role => $allowed];
    }

    /** @return array<int,array<string,mixed>> */
    private function views(CommerceScope $scope, int $userId): array { $q=Database::connection()->prepare('SELECT * FROM admin_saved_views WHERE user_id=? AND store_id=? AND channel_id=? ORDER BY name'); $q->execute([$userId,$scope->storeId,$scope->channelId]); return array_map(fn($r)=>$this->decode($r), $q->fetchAll() ?: []); }
    /** @return array<int,array<string,mixed>> */
    private function workflows(CommerceScope $scope): array { $q=Database::connection()->prepare('SELECT * FROM admin_approval_workflows WHERE store_id=? AND channel_id=? AND active=1 ORDER BY name'); $q->execute([$scope->storeId,$scope->channelId]); return array_map(fn($r)=>$this->decode($r), $q->fetchAll() ?: []); }
    private function view(string $id, CommerceScope $scope, int $userId): ?array { $q=Database::connection()->prepare('SELECT * FROM admin_saved_views WHERE id=? AND user_id=? AND store_id=? AND channel_id=?'); $q->execute([$id,$userId,$scope->storeId,$scope->channelId]); $r=$q->fetch(); return is_array($r)?$this->decode($r):null; }
    private function workflow(string $id, CommerceScope $scope): ?array { $q=Database::connection()->prepare('SELECT * FROM admin_approval_workflows WHERE id=? AND store_id=? AND channel_id=?'); $q->execute([$id,$scope->storeId,$scope->channelId]); $r=$q->fetch(); return is_array($r)?$this->decode($r):null; }
    private function approval(string $id, CommerceScope $scope): ?array { $q=Database::connection()->prepare('SELECT * FROM admin_approval_requests WHERE id=? AND store_id=? AND channel_id=?'); $q->execute([$id,$scope->storeId,$scope->channelId]); $r=$q->fetch(); return is_array($r)?$this->decode($r):null; }
    private function decode(mixed $row): array { if (!is_array($row)) return []; foreach (['filters_json'=>'filters','columns_json'=>'columns','steps_json'=>'steps','payload_json'=>'payload'] as $from=>$to) if (array_key_exists($from,$row)) { $row[$to]=json_decode((string)$row[$from],true) ?: []; unset($row[$from]); } return $row; }
}
