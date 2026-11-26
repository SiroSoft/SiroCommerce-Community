<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Modules\StorefrontConfig\Application\StorefrontConfigService;
use App\Modules\StorefrontConfig\Application\ThemeLicenseGate;
use App\Modules\StorefrontConfig\Application\ThemePackageArchiveInstaller;
use App\Modules\StorefrontConfig\Infrastructure\StorefrontConfigStoreAdapter;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Modules\StorefrontConfig\Domain\StorefrontConfigConflict;
use App\Modules\StorefrontConfig\Domain\StorefrontConfigReplay;
use App\Modules\StorefrontConfig\Domain\StorefrontVerticalPresetCatalog;
use App\Modules\StorefrontConfig\Domain\ThemePackage;

final class StorefrontConfigController extends Controller
{
    public function __construct(private readonly ?StorefrontConfigService $service = null) {}

    public function show(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 400, [], 'SCOPE_ERROR');
        }
        $config = ($this->service ?? new StorefrontConfigService(new StorefrontConfigStoreAdapter()))->published($scope);
        try {
            $etag = '"' . hash('sha256', json_encode($config->config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        } catch (\JsonException) {
            return $this->error('Storefront configuration is unavailable', 503, [], 'INTERNAL_ERROR');
        }
        $headers = ['Cache-Control' => 'public, max-age=60', 'ETag' => $etag, 'X-Commerce-Scope' => $scope->key(), 'X-Storefront-Config-Version' => (string) $config->version];
        if ((string) $request->header('If-None-Match', '') === $etag) return Response::json([], 304)->header('Cache-Control', $headers['Cache-Control'])->header('ETag', $etag)->header('X-Commerce-Scope', $scope->key())->header('X-Storefront-Config-Version', (string) $config->version);
        return $this->success($config->config, 'Storefront configuration', 200, ['schema_version' => 1])->header('Cache-Control', $headers['Cache-Control'])->header('ETag', $etag)->header('X-Commerce-Scope', $scope->key())->header('X-Storefront-Config-Version', (string) $config->version);
    }

    public function publicPresets(Request $request): Response { unset($request); $items = []; foreach (StorefrontVerticalPresetCatalog::all() as $key => $preset) { $items[] = ['key' => $key, 'name' => $preset['name'], 'description' => $preset['description'], 'preview' => '/media/theme-previews/' . $key . '.jpg', 'layout_archetype' => StorefrontVerticalPresetCatalog::archetype($key), 'product_images' => array_map(static fn (string $suffix): string => '/media/theme-previews/' . $key . $suffix . '.jpg', ['', '-2', '-3']), 'theme' => $preset['theme'], 'brand' => $preset['brand'], 'header' => $preset['header'], 'footer' => $preset['footer'], 'purchase' => $preset['purchase'], 'policies' => $preset['policies'], 'presentation' => $preset['presentation'], 'sections' => $preset['sections']]; } return $this->success($items, 'Theme presets')->header('Cache-Control', 'public, max-age=300'); }
    public function adminShow(Request $request): Response { return $this->run($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u): mixed => $s->draft($scope,$u), 'Storefront configuration'); }
    public function update(Request $request): Response { return $this->mutate($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u,string $key,array $d): mixed => $s->update($scope,$d,$u,$key), 'Storefront configuration updated'); }
    public function preview(Request $request): Response { return $this->run($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u): mixed => $s->preview($scope,$this->requestData($request),$u), 'Storefront configuration preview', true); }
    public function publish(Request $request): Response { return $this->mutate($request, function (StorefrontConfigService $s, CommerceScope $scope, array $u, string $key, array $d): mixed { $draft = $s->draft($scope, $u); $config = is_array($draft) ? $draft['config'] : []; $theme = is_array($config['theme'] ?? null) ? $config['theme'] : []; $themeKey = is_string($theme['key'] ?? null) ? $theme['key'] : ''; (new ThemeLicenseGate())->assertEntitled(ThemePackage::licenseForThemeKey($themeKey)); return $s->publish($scope,$d,$u,$key); }, 'Storefront configuration published'); }
    public function restore(Request $request): Response { return $this->mutate($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u,string $key,array $d): mixed => $s->restore($scope,$this->routeValue($request,'revision'),$d,$u,$key), 'Storefront configuration restored'); }
    public function revisions(Request $request): Response { return $this->run($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u): mixed => $s->revisions($scope,$u), 'Storefront revisions'); }
    public function diff(Request $request): Response { return $this->run($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u): mixed => $s->diff($scope,$this->queryValue($request,'from'),$this->queryValue($request,'to'),$u), 'Storefront revision diff'); }
    public function themes(): Response { return $this->success((new StorefrontConfigService(new StorefrontConfigStoreAdapter()))->themes(), 'Storefront themes'); }
    public function presets(): Response { return $this->success((new StorefrontConfigService(new StorefrontConfigStoreAdapter()))->presets(), 'Vertical presets'); }
    public function applyPreset(Request $request): Response { return $this->mutate($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u,string $key,array $d): mixed => $s->applyPreset($scope,$d,$u,$key), 'Storefront preset applied'); }
    public function export(Request $request): Response { return $this->run($request, fn(StorefrontConfigService $s, CommerceScope $scope, array $u): mixed => ['exported_at' => date('c'), 'scope' => $scope->metadata(), 'config' => $s->draft($scope, $u)['config'] ?? $s->published($scope)->config], 'Theme exported'); }
    public function import(Request $request): Response { return $this->mutate($request, function (StorefrontConfigService $s, CommerceScope $scope, array $u, string $key, array $d): mixed { $config = $d['config'] ?? null; if (!is_array($config)) throw new \InvalidArgumentException('config object is required'); $draft = $s->draft($scope, $u); $current = is_array($draft) ? (int) $draft['version'] : 1; $input = $this->importInput($config); $input['expected_version'] = isset($d['expected_version']) && is_numeric($d['expected_version']) ? (int) $d['expected_version'] : $current; return $s->update($scope, $input, $u, $key); }, 'Theme imported as draft'); }
    public function importTheme(Request $request): Response { return $this->mutate($request, function (StorefrontConfigService $s, CommerceScope $scope, array $u, string $key, array $d): mixed { $package = $d['theme'] ?? null; if (!is_array($package)) throw new \InvalidArgumentException('theme package object is required'); $keyed = []; foreach ($package as $name => $value) if (is_string($name)) $keyed[$name] = $value; $input = ThemePackage::input($keyed); (new ThemeLicenseGate())->assertEntitled(ThemePackage::license($keyed)); $draft = $s->draft($scope, $u); $current = is_array($draft) ? (int) $draft['version'] : 1; $input['expected_version'] = isset($d['expected_version']) && is_numeric($d['expected_version']) ? (int) $d['expected_version'] : $current; return $s->update($scope, $input, $u, $key); }, 'Theme package imported as draft'); }
    public function exportTheme(Request $request): Response { return $this->run($request, function (StorefrontConfigService $s, CommerceScope $scope, array $u): mixed { $draft = $s->draft($scope, $u); $config = is_array($draft) ? $draft['config'] : $s->published($scope)->config; return ThemePackage::export($config); }, 'Theme package exported'); }
    public function importThemeArchive(Request $request): Response { return $this->mutate($request, function (StorefrontConfigService $s, CommerceScope $scope, array $u, string $key, array $d) use ($request): mixed { $file = $request->file('package'); if ($file === null || !$file->isValid()) throw new \InvalidArgumentException('A theme archive is required'); if (strtolower($file->extension()) !== 'zip') throw new \InvalidArgumentException('Theme archive must be a .zip file'); $installer = new ThemePackageArchiveInstaller(); $report = $installer->verify($file->getPathname()); (new ThemeLicenseGate())->assertEntitled($report['license']); $archive = $installer->install($file->getPathname()); $input = ThemePackage::input($archive['package']); $draft = $s->draft($scope, $u); $current = is_array($draft) ? (int) $draft['version'] : 1; $input['expected_version'] = isset($d['expected_version']) && is_numeric($d['expected_version']) ? (int) $d['expected_version'] : $current; $saved = $s->update($scope, $input, $u, $key); return ['draft' => $saved, 'assets' => $archive['assets'], 'theme_id' => $archive['theme_id']]; }, 'Theme archive imported as draft'); }
    public function exportThemeArchive(Request $request): Response { try { $scope = $this->scope($request); $draft = $this->service()->draft($scope, $this->principal($request)); $config = is_array($draft) ? $draft['config'] : $this->service()->published($scope)->config; $result = (new \App\Modules\StorefrontConfig\Application\ThemePackageArchiveExporter())->export(ThemePackage::export($config)); return Response::raw($result['content'], 'application/zip')->header('Content-Disposition', 'attachment; filename="siro-theme-' . date('Ymd-His') . '.zip"')->header('X-Export-Files', (string) $result['files']); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403, [], 'FORBIDDEN'); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, [], 'VALIDATION_ERROR'); } }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function importInput(array $config): array
    {
        $stringKeyed = [];
        foreach ($config as $key => $value) if (is_string($key)) $stringKeyed[$key] = $value;
        $theme = is_array($stringKeyed['theme'] ?? null) ? $stringKeyed['theme'] : [];
        $themeKey = is_string($theme['key'] ?? null) && $theme['key'] !== '' ? $theme['key'] : 'editorial';
        $input = ['theme_key' => $themeKey, 'vertical_key' => 'general'];
        $vertical = is_array($stringKeyed['vertical'] ?? null) ? $stringKeyed['vertical'] : [];
        if (is_string($vertical['key'] ?? null) && $vertical['key'] !== '') $input['vertical_key'] = $vertical['key'];
        if (is_string($theme['extends'] ?? null) && $theme['extends'] !== '') $input['theme_extends'] = $theme['extends'];
        foreach (['brand', 'tokens', 'navigation', 'header', 'footer', 'homepage', 'presentation', 'purchase', 'policies', 'experiments'] as $field) {
            if (array_key_exists($field, $stringKeyed)) $input[$field] = $stringKeyed[$field];
        }
        return $input;
    }

    private function service(): StorefrontConfigService { return $this->service ?? new StorefrontConfigService(new StorefrontConfigStoreAdapter()); }
    private function scope(Request $request): CommerceScope { return CommerceScope::fromRequest($request); }
    /** @return array<string,mixed> */ private function requestData(Request $request): array { return $request->inputAll(); }
    private function routeValue(Request $request,string $name): string { $value=$request->param($name); return is_string($value)?$value:''; }
    private function queryValue(Request $request,string $name): string { return $request->queryString($name,''); }
    /** @return array<string,mixed> */ private function principal(Request $request): array { $value=$request->user(); if(!is_array($value))return []; $result=[];foreach($value as $key=>$item)$result[(string)$key]=$item;return $result; }
    /** @param \Closure(StorefrontConfigService,CommerceScope,array<string,mixed>):mixed $callback */
    private function run(Request $request, \Closure $callback, string $message, bool $private=false): Response { try { $result=$callback($this->service(),$this->scope($request),$this->principal($request)); $response=$this->success($result,$message); return $private?$response->header('Cache-Control','private, no-store'):$response; } catch(CommerceScopeException $e){return $this->error($e->getMessage(),400,[],'SCOPE_ERROR');} catch(StorefrontConfigConflict $e){return $this->error($e->getMessage(),409,$e->context,'CONFLICT');} catch(\InvalidArgumentException $e){return $this->error($e->getMessage(),422,[],'VALIDATION_ERROR');} }
    /** @param \Closure(StorefrontConfigService,CommerceScope,array<string,mixed>,string,array<string,mixed>):mixed $callback */
    private function mutate(Request $request, \Closure $callback, string $message): Response { $key=(string)$request->header('Idempotency-Key',''); if($key===''||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/',$key)!==1)return $this->error('A valid Idempotency-Key is required',422); try{$result=$callback($this->service(),$this->scope($request),$this->principal($request),$key,$this->requestData($request));return $this->success($result,$message);}catch(CommerceScopeException $e){return $this->error($e->getMessage(),403,[],'FORBIDDEN');}catch(StorefrontConfigConflict $e){return $this->error($e->getMessage(),409,$e->context,'CONFLICT');}catch(StorefrontConfigReplay){return $this->error('Idempotency key was already used',409,[],'CONFLICT');}catch(\InvalidArgumentException $e){return $this->error($e->getMessage(),422,[],'VALIDATION_ERROR');} }
}
