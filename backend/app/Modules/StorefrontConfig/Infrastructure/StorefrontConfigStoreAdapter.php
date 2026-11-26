<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Infrastructure;

use App\Modules\StorefrontConfig\Contracts\AdminStorefrontConfigStore;
use App\Support\CommerceScope;
use Siro\Core\Database;
use App\Support\Ids;
use Siro\Core\Audit;
use Siro\Core\Cache;

final class StorefrontConfigStoreAdapter implements AdminStorefrontConfigStore
{
    public function published(CommerceScope $scope): ?array
    {
        $pdo = Database::connection();
        $candidates = [
            [$scope->storeId, $scope->channelId],
            [$scope->storeId, 'default'],
            ['default', 'default'],
        ];
        foreach ($candidates as [$storeId, $channelId]) {
            $statement = $this->prepare('SELECT config_json, version FROM storefront_configs WHERE store_id=? AND channel_id=? AND locale=? AND currency=? AND status=? ORDER BY version DESC LIMIT 1');
            $statement->execute([$storeId, $channelId, $scope->locale, $scope->currency, 'published']);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) continue;
            $raw = $row['config_json'] ?? '';
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $config = $this->stringKeyed($decoded);
                if ($config !== null) return ['config' => $config, 'version' => is_numeric($row['version'] ?? null) ? (int) $row['version'] : 1];
            }
        }
        return null;
    }

    public function draft(CommerceScope $scope): ?array
    {
        $q = $this->prepare('SELECT * FROM storefront_configs WHERE store_id=? AND channel_id=? AND locale=? AND currency=? LIMIT 1');
        $q->execute([$scope->storeId, $scope->channelId, $scope->locale, $scope->currency]);
        return $this->configRow($q->fetch(\PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function saveDraft(CommerceScope $scope, array $config, int $expectedVersion, string $actor, string $key): array
    {
        return $this->transactionArray(Database::transaction(function () use ($scope, $config, $expectedVersion, $actor, $key): array {
            $row = $this->locked($scope); if (($replay = $this->replayed($scope, $key)) !== null) return $replay; $this->assertVersion($row, $expectedVersion);
            $version = $this->rowInt($row, 'version') + 1; $json = $this->json($config); $now = date('Y-m-d H:i:s');
            $this->prepare('UPDATE storefront_configs SET theme_key=?, vertical_key=?, status=?, version=?, config_json=?, updated_by=?, updated_at=? WHERE id=?')->execute([$this->configKey($config, 'theme'), $this->configKey($config, 'vertical'), 'draft', $version, $json, $actor, $now, $this->rowValue($row, 'id')]);
            $this->revision($row, $version, $config, 'updated', $actor, $key); return $this->result($scope, $config, $version, 'draft');
        }));
    }

    public function publish(CommerceScope $scope, int $expectedVersion, string $actor, string $key): array
    {
        return $this->transactionArray(Database::transaction(function () use ($scope, $expectedVersion, $actor, $key): array {
            $row = $this->locked($scope); if (($replay = $this->replayed($scope, $key)) !== null) return $replay; $this->assertVersion($row, $expectedVersion);
            $config = $this->decode($this->rowValue($row, 'config_json')); $version = $this->rowInt($row, 'version') + 1; $now = date('Y-m-d H:i:s');
            $this->prepare('UPDATE storefront_configs SET status=?, version=?, published_at=?, updated_by=?, updated_at=? WHERE id=?')->execute(['published', $version, $now, $actor, $now, $this->rowValue($row, 'id')]);
            $this->revision($row, $version, $config, 'published', $actor, $key); $this->forget($scope, $this->rowInt($row, 'version')); $this->audit('published', $scope, $actor, $key, $version); return $this->result($scope, $config, $version, 'published');
        }));
    }

    public function restore(CommerceScope $scope, string $revision, int $expectedVersion, string $actor, string $key): array
    {
        return $this->transactionArray(Database::transaction(function () use ($scope, $revision, $expectedVersion, $actor, $key): array {
            $row = $this->locked($scope); if (($replay = $this->replayed($scope, $key)) !== null) return $replay; $this->assertVersion($row, $expectedVersion);
            $q = $this->prepare('SELECT snapshot_json FROM storefront_config_revisions WHERE id=? AND config_id=? AND store_id=? AND channel_id=? AND locale=? AND currency=?');
            $q->execute([$revision, $this->rowValue($row, 'id'), $scope->storeId, $scope->channelId, $scope->locale, $scope->currency]); $rev = $this->fetchRow($q); if ($rev === null) throw new \InvalidArgumentException('Storefront revision not found in commerce scope');
            $config = $this->decode($this->rowValue($rev, 'snapshot_json')); $version = $this->rowInt($row, 'version') + 1; $now = date('Y-m-d H:i:s');
            $this->prepare('UPDATE storefront_configs SET theme_key=?, vertical_key=?, status=?, version=?, config_json=?, published_at=?, updated_by=?, updated_at=? WHERE id=?')->execute([$this->configKey($config, 'theme'), $this->configKey($config, 'vertical'), 'published', $version, $this->json($config), $now, $actor, $now, $this->rowValue($row, 'id')]);
            $this->revision($row, $version, $config, 'restored', $actor, $key); $this->forget($scope, $this->rowInt($row, 'version')); $this->audit('restored', $scope, $actor, $key, $version); return $this->result($scope, $config, $version, 'published');
        }));
    }

    public function revisions(CommerceScope $scope): array
    {
        $q = $this->prepare('SELECT id, version, action, actor_id, created_at FROM storefront_config_revisions WHERE store_id=? AND channel_id=? AND locale=? AND currency=? ORDER BY version DESC'); $q->execute([$scope->storeId, $scope->channelId, $scope->locale, $scope->currency]); return $this->fetchRows($q);
    }

    public function diff(CommerceScope $scope, string $from, string $to): array
    {
        $rows = [];
        foreach ([$from, $to] as $id) { $q = $this->prepare('SELECT snapshot_json FROM storefront_config_revisions WHERE id=? AND store_id=? AND channel_id=? AND locale=? AND currency=?'); $q->execute([$id, $scope->storeId, $scope->channelId, $scope->locale, $scope->currency]); $r = $this->fetchRow($q); if ($r === null) throw new \InvalidArgumentException('Storefront revision not found in commerce scope'); $rows[] = $this->decode($this->rowValue($r, 'snapshot_json')); }
        return ['from' => $from, 'to' => $to, 'changes' => $this->changes($rows[0], $rows[1])];
    }

    /** @return list<array<string,mixed>> */
    public function themes(): array { $rows = $this->fetchRows($this->query("SELECT `key`, name, version, schema_version, status, defaults_json FROM storefront_themes WHERE status='active' ORDER BY `key`, version")); foreach ($rows as &$r) { $r['defaults'] = $this->decode($this->rowValue($r, 'defaults_json')); unset($r['defaults_json']); } return $rows; }
    /** @return list<array<string,mixed>> */
    public function presets(): array
    {
        $rows = $this->fetchRows($this->query("SELECT `key`, name, version, status, theme_defaults_json, presentation_defaults_json, homepage_defaults_json, validation_schema_json FROM storefront_vertical_presets WHERE status='active' ORDER BY `key`, version"));
        foreach ($rows as &$r) {
            $r['theme_defaults'] = $this->decode($this->rowValue($r, 'theme_defaults_json'));
            $r['presentation_defaults'] = $this->decode($this->rowValue($r, 'presentation_defaults_json'));
            $r['homepage_defaults'] = $this->decode($this->rowValue($r, 'homepage_defaults_json'));
            $schema = $this->decode($this->rowValue($r, 'validation_schema_json'));
            if (is_string($schema['description'] ?? null)) $r['description'] = $schema['description'];
            if (is_string($schema['preview'] ?? null)) $r['preview'] = $schema['preview'];
            if (is_array($schema['purchase_defaults'] ?? null)) $r['purchase_defaults'] = $schema['purchase_defaults'];
            unset($r['theme_defaults_json'], $r['presentation_defaults_json'], $r['homepage_defaults_json'], $r['validation_schema_json']);
        }
        return $rows;
    }

    /** @return array{ id: mixed, status: mixed, version: int, config: array<string,mixed> }|null */
    private function configRow(mixed $row): ?array { $value=$this->fetchArray($row); if($value===null)return null; return ['id' => $this->rowValue($value,'id'), 'status' => $this->rowValue($value,'status'), 'version' => $this->rowInt($value,'version'), 'config' => $this->decode($this->rowValue($value,'config_json'))]; }
    /** @return array<string,mixed> */
    private function locked(CommerceScope $s): array
    {
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $q=$this->prepare('SELECT * FROM storefront_configs WHERE store_id=? AND channel_id=? AND locale=? AND currency=?' . $lock);
        $q->execute([$s->storeId,$s->channelId,$s->locale,$s->currency]);
        $r=$this->fetchRow($q);
        if($r===null) throw new \RuntimeException('Storefront configuration not found');
        return $r;
    }
    /** @param array<string,mixed> $row */
    private function assertVersion(array $row, int $expected): void { $actual=$this->rowInt($row,'version'); if ($actual !== $expected) throw new \App\Modules\StorefrontConfig\Domain\StorefrontConfigConflict('Storefront configuration version is stale', ['expected_version'=>$expected,'actual_version'=>$actual]); }
    /** @return array<string,mixed>|null */ private function replayed(CommerceScope $s,string $key):?array { if($key==='')throw new \InvalidArgumentException('Idempotency-Key is required'); $q=$this->prepare('SELECT snapshot_json,version,action FROM storefront_config_revisions WHERE idempotency_key=? AND store_id=? AND channel_id=? AND locale=? AND currency=? LIMIT 1');$q->execute([$key,$s->storeId,$s->channelId,$s->locale,$s->currency]);$r=$this->fetchRow($q);if($r===null)return null;$action=$this->rowValue($r,'action');$result=$this->result($s,$this->decode($this->rowValue($r,'snapshot_json')),$this->rowInt($r,'version'),is_string($action)&&str_starts_with($action,'publish')?'published':($action==='restored'?'published':'draft'));return$result; }
    /** @param array<string,mixed> $row
     * @param array<string,mixed> $config */
    private function revision(array $row,int $version,array $config,string $action,string $actor,string $key):void { $this->prepare('INSERT INTO storefront_config_revisions (id,config_id,version,snapshot_json,action,actor_id,idempotency_key,store_id,channel_id,locale,currency,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([Ids::new(),$this->rowValue($row,'id'),$version,$this->json($config),$action,$actor,$key,$this->rowValue($row,'store_id'),$this->rowValue($row,'channel_id'),$this->rowValue($row,'locale'),$this->rowValue($row,'currency'),date('Y-m-d H:i:s')]); }
    private function forget(CommerceScope $s,int $version):void { try { Cache::forget('storefront:config:'.$s->key()); Cache::forget('storefront:config:'.$s->key().':'.$version); } catch (\Throwable) {} }
    private function audit(string $action,CommerceScope $s,string $actor,string $key,int $version):void { Audit::log('commerce.storefront_config.'.$action,['actor_id'=>$actor,'scope'=>$s->key(),'idempotency_key'=>$key,'version'=>$version]); }
    private function json(mixed $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    /** @return array<string,mixed> */ private function decode(mixed $v):array{$x=is_string($v)?json_decode($v,true):$v;if(!is_array($x))return[];$result=[];foreach($x as $key=>$value)if(is_string($key))$result[$key]=$value;return$result;}
    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function result(CommerceScope $s,array $c,int $v,string $status):array{return ['scope'=>$s->metadata(),'status'=>$status,'version'=>$v,'config'=>$c];}
    /** @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed> */ private function changes(array $a,array $b,string $path=''):array{$out=[];foreach(array_unique(array_merge(array_keys($a),array_keys($b))) as $k){$p=$path===''?(string)$k:$path.'.'.$k;if(!array_key_exists($k,$a)||!array_key_exists($k,$b)||$a[$k]!==$b[$k])$out[$p]=['from'=>$a[$k]??null,'to'=>$b[$k]??null];}return $out;}
    private function prepare(string $sql): \PDOStatement { $statement=Database::connection()->prepare($sql); if($statement===false)throw new \RuntimeException('Storefront configuration query could not be prepared'); return $statement; }
    private function query(string $sql): \PDOStatement { $statement=Database::connection()->query($sql); if($statement===false)throw new \RuntimeException('Storefront configuration query failed'); return $statement; }
    /** @return array<string,mixed>|null */ private function fetchRow(\PDOStatement $statement):?array { $row=$statement->fetch(\PDO::FETCH_ASSOC);return $this->fetchArray($row); }
    /** @return list<array<string,mixed>> */ private function fetchRows(\PDOStatement $statement):array { $rows=$statement->fetchAll(\PDO::FETCH_ASSOC);$result=[];foreach($rows as $row){$value=$this->fetchArray($row);if($value!==null)$result[]=$value;}return$result; }
    /** @return array<string,mixed>|null */ private function fetchArray(mixed $value):?array { if(!is_array($value))return null;$result=[];foreach($value as $key=>$item)if(is_string($key))$result[$key]=$item;return$result; }
    /** @param array<string,mixed> $row */ private function rowValue(array $row,string $key):mixed { if(!array_key_exists($key,$row))throw new \RuntimeException('Storefront configuration row is incomplete');return$row[$key]; }
    /** @param array<string,mixed> $row */ private function rowInt(array $row,string $key):int {$value=$this->rowValue($row,$key);if(!is_numeric($value))throw new \RuntimeException('Storefront configuration row has an invalid integer');return(int)$value;}
    /** @param array<string,mixed> $config */ private function configKey(array $config,string $group):string {$value=$config[$group]??null;if(!is_array($value)||!is_string($value['key']??null))throw new \RuntimeException('Storefront configuration is incomplete');return$value['key'];}
    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function transactionArray(mixed $value):array {if(!is_array($value))throw new \RuntimeException('Storefront configuration transaction failed');$result=[];foreach($value as $key=>$item)if(is_string($key))$result[$key]=$item;return$result;}

    /** @param array<mixed, mixed> $value
     * @return array<string, mixed>|null
     */
    private function stringKeyed(array $value): ?array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) return null;
            $result[$key] = $item;
        }
        return $result;
    }
}
