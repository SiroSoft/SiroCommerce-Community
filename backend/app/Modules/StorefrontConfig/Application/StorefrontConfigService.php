<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Application;

use App\Modules\StorefrontConfig\Contracts\StorefrontConfigStore;
use App\Modules\StorefrontConfig\Contracts\AdminStorefrontConfigStore;
use App\Modules\StorefrontConfig\Domain\StorefrontConfig;
use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use App\Modules\StorefrontConfig\Validation\StorefrontConfigValidator;
use App\Support\CommerceScope;
use Siro\Core\Cache;
use App\Support\CommerceScopeException;

final class StorefrontConfigService
{
    public function __construct(
        private readonly StorefrontConfigStore $store,
        private readonly StorefrontConfigValidator $validator = new StorefrontConfigValidator(),
    ) {}

    public function published(CommerceScope $scope): StorefrontConfig
    {
        $key = 'storefront:config:' . $scope->key();
        try {
            $found = Cache::remember($key, 60, fn (): ?array => $this->store->published($scope));
        } catch (\Throwable) {
            $found = null;
        }
        if (is_array($found) && is_array($found['config'] ?? null)) {
            $valid = $this->validator->publicConfig($found['config']);
            if ($valid !== null) {
                $valid['scope'] = $scope->metadata();
                $valid = $this->applyFlagshipFallbacks($valid);
                return new StorefrontConfig($valid, max(1, $this->version($found['version'] ?? 1)), false);
            }
        }
        return new StorefrontConfig(StorefrontConfigDefaults::config($scope->storeId, $scope->channelId, $scope->locale, $scope->currency), 1, true);
    }

    /** @return AdminStorefrontConfigStore */
    private function adminStore(): AdminStorefrontConfigStore { if (!$this->store instanceof AdminStorefrontConfigStore) throw new \RuntimeException('Storefront admin storage is unavailable'); return $this->store; }
    /**
     * @param array<string,mixed> $actor
     * @return array{ id: mixed, status: mixed, version: int, config: array<string,mixed> }|null
     */
    public function draft(CommerceScope $scope, array $actor): ?array { $this->authorize($scope, $actor); return $this->adminStore()->draft($scope); }
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function update(CommerceScope $scope, array $input, array $actor, string $key): array { $this->authorize($scope,$actor); $expected=$this->expected($input); $config=$this->writeConfig($scope,$input); return $this->adminStore()->saveDraft($scope,$config,$expected,$this->actor($actor),$key); }
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function preview(CommerceScope $scope, array $input, array $actor): array { $this->authorize($scope,$actor); $config=$this->writeConfig($scope,$input); return ['scope'=>$scope->metadata(),'status'=>'preview','version'=>$this->expected($input),'config'=>$config]; }
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function publish(CommerceScope $scope, array $input, array $actor, string $key): array { $this->authorize($scope,$actor); return $this->adminStore()->publish($scope,$this->expected($input),$this->actor($actor),$key); }
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function restore(CommerceScope $scope, string $revision, array $input, array $actor, string $key): array { $this->authorize($scope,$actor); return $this->adminStore()->restore($scope,$revision,$this->expected($input),$this->actor($actor),$key); }
    /**
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public function revisions(CommerceScope $scope,array $actor): array { $this->authorize($scope,$actor); return $this->adminStore()->revisions($scope); }
    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function diff(CommerceScope $scope,string $from,string $to,array $actor): array { $this->authorize($scope,$actor); return $this->adminStore()->diff($scope,$from,$to); }
    /** @return list<array<string,mixed>> */ public function themes():array{return $this->adminStore()->themes();}
    /** @return list<array<string,mixed>> */ public function presets():array{return $this->adminStore()->presets();}
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function applyPreset(CommerceScope $scope,array $input,array $actor,string $key):array
    {
        $this->authorize($scope,$actor);
        $preset=$input['preset_key']??$input['vertical_key']??null;
        if(!is_string($preset)||$preset==='')throw new \InvalidArgumentException('Unknown vertical preset');
        $found=null;
        foreach($this->presets() as $row)if(($row['key']??null)===$preset){$found=$row;break;}
        if($found===null)throw new \InvalidArgumentException('Unknown vertical preset');
        $base=$this->adminStore()->draft($scope);
        $baseConfig=$this->keyed($base['config']??null);
        $fallback=StorefrontConfigDefaults::config($scope->storeId,$scope->channelId,$scope->locale,$scope->currency);
        $fallbackTheme=$this->keyed($fallback['theme']??null);
        $currentTheme=$this->keyed($baseConfig['theme']??null);
        $themeDefaults=$this->keyed($found['theme_defaults']??null);
        $presetTheme=$this->keyed($themeDefaults['theme']??null);
        if($presetTheme===[])$presetTheme=$themeDefaults;
        $themeKey=is_string($presetTheme['key']??null)&&$presetTheme['key']!==''?$presetTheme['key']:(is_string($currentTheme['key']??null)?$currentTheme['key']:'editorial');
        $tokens=$this->keyed($presetTheme['tokens']??null);
        if($tokens===[])$tokens=$this->keyed($currentTheme['tokens']??null);
        if($tokens===[])$tokens=$this->keyed($fallbackTheme['tokens']??null);
        $presentation=$this->keyed($found['presentation_defaults']??null);
        if($presentation===[])$presentation=$this->keyed($baseConfig['presentation']??null);
        if($presentation===[])$presentation=$this->keyed($fallback['presentation']??null);
        $purchaseDefaults=$this->keyed($found['purchase_defaults']??null);
        $purchase=$this->keyed($baseConfig['purchase']??null);
        if($purchase===[])$purchase=$this->keyed($fallback['purchase']??null);
        if(is_string($purchaseDefaults['quote_mode']??null))$purchase['quote_mode']=$purchaseDefaults['quote_mode'];
        if($purchase===[])$purchase=['quote_mode'=>'required'];
        $foundHomepage=$this->keyed($found['homepage_defaults']??null);
        $sections=$foundHomepage['sections']??null;
        if(!is_array($sections)||$sections===[]){$currentHomepage=$this->keyed($baseConfig['homepage']??null);$sections=is_array($currentHomepage['sections']??null)?$currentHomepage['sections']:StorefrontConfigDefaults::defaultSections();}
        $homepage=['sections'=>$sections];
        if(is_array($foundHomepage['preferred_sections']??null))$homepage['preferred_sections']=$foundHomepage['preferred_sections'];
        $presetBrand=$this->keyed($themeDefaults['brand']??null);
        $brand=$this->keyed($baseConfig['brand']??null);
        if($brand===[]||$this->isPlaceholderBrand($brand))$brand=$presetBrand!==[]?$presetBrand:$this->keyed($fallback['brand']??null);
        $navigation=$this->keyed($baseConfig['navigation']??null);if($navigation===[])$navigation=$this->keyed($fallback['navigation']??null);
        $header=$this->keyed($themeDefaults['header']??null);if($header===[])$header=$this->keyed($baseConfig['header']??null);
        $footer=$this->keyed($themeDefaults['footer']??null);if($footer===[])$footer=$this->keyed($baseConfig['footer']??null);
        $policies=$this->keyed($themeDefaults['policies']??null);if($policies===[])$policies=$this->keyed($baseConfig['policies']??null);
        $data=['expected_version'=>$this->expected($input),'theme_key'=>$themeKey,'vertical_key'=>$preset,'brand'=>$brand,'tokens'=>$tokens,'navigation'=>$navigation,'header'=>$header,'footer'=>$footer,'homepage'=>$homepage,'presentation'=>$presentation,'purchase'=>$purchase,'policies'=>$policies];
        if(is_string($presetTheme['extends']??null)&&$presetTheme['extends']!=='')$data['theme_extends']=$presetTheme['extends'];
        return $this->update($scope,$data,$actor,$key);
    }

    /** @param array<string,mixed> $input */ private function expected(array $input):int { if(!isset($input['expected_version'])||!is_numeric($input['expected_version']))throw new \InvalidArgumentException('expected_version is required'); return (int)$input['expected_version']; }
    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function writeConfig(CommerceScope $scope,array $input):array { $allowed=['expected_version','theme_key','theme_extends','vertical_key','brand','tokens','navigation','header','footer','homepage','presentation','purchase','policies','experiments']; foreach(array_keys($input) as $key)if(!in_array($key,$allowed,true))throw new \InvalidArgumentException('Unknown storefront config field: '.$key); $base=$this->keyed(StorefrontConfigDefaults::config($scope->storeId,$scope->channelId,$scope->locale,$scope->currency)); $theme=$this->keyed($base['theme']??null);$vertical=$this->keyed($base['vertical']??null);$themeKey=$input['theme_key']??'editorial';$verticalKey=$input['vertical_key']??'general';if(!is_string($themeKey)||!is_string($verticalKey))throw new \InvalidArgumentException('Invalid storefront configuration');$theme['key']=$themeKey;$vertical['key']=$verticalKey;$base['theme']=$theme;$base['vertical']=$vertical;if(is_string($input['theme_extends']??null)&&preg_match('/^[a-z][a-z0-9_-]{2,39}$/',$input['theme_extends'])===1){$theme['extends']=$input['theme_extends'];$base['theme']=$theme;}foreach(['brand','navigation','header','footer','homepage','presentation','purchase','policies','experiments'] as $key)if(array_key_exists($key,$input))$base[$key]=$input[$key];if(array_key_exists('tokens',$input)){$theme['tokens']=$input['tokens'];$base['theme']=$theme;}$valid=$this->validator->writeConfig($base);if($valid===null)throw new \InvalidArgumentException('Invalid storefront configuration');return $valid; }
    /** @param array<string,mixed> $actor */ private function actor(array $actor):string{$id=$actor['id']??null;if(is_numeric($id))return (string)$id;$email=$actor['email']??null;return is_string($email)?$email:'admin';}
    /** @param array<string,mixed> $actor */ private function authorize(CommerceScope $scope,array $actor):void { foreach(['store_id','channel_id'] as $name){$allowed=$actor[$name.'s']??$actor[$name]??null;if($allowed===null)continue;$values=is_array($allowed)?$allowed:[$allowed];$normalized=[];foreach($values as $value)if(is_scalar($value))$normalized[]=(string)$value;if(!in_array($name==='store_id'?$scope->storeId:$scope->channelId,$normalized,true))throw new CommerceScopeException('Commerce scope is not authorized.');} }
    /** @return array<string,mixed> */ private function keyed(mixed $value):array { if(!is_array($value))return []; $result=[];foreach($value as $key=>$item)if(is_string($key))$result[$key]=$item;return$result; }
    /** @param array<string,mixed> $brand */ private function isPlaceholderBrand(array $brand):bool { $name=$brand['name']??null; return !is_string($name)||trim($name)===''||$name==='SiroCommerce'; }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyFlagshipFallbacks(array $config): array
    {
        $homepage = is_array($config['homepage'] ?? null) ? $config['homepage'] : [];
        if (!is_array($homepage['sections'] ?? null) || count($homepage['sections']) === 0) {
            $homepage['sections'] = StorefrontConfigDefaults::defaultSections();
            $config['homepage'] = $homepage;
        }
        $navigation = is_array($config['navigation'] ?? null) ? $config['navigation'] : [];
        if (!is_array($navigation['items'] ?? null) || count($navigation['items']) === 0) {
            $navigation['menu_key'] = is_string($navigation['menu_key'] ?? null) && $navigation['menu_key'] !== '' ? $navigation['menu_key'] : 'primary';
            $navigation['items'] = StorefrontConfigDefaults::defaultNavigation();
            $config['navigation'] = $navigation;
        }
        $brand = is_array($config['brand'] ?? null) ? $config['brand'] : [];
        $logoUrl = array_key_exists('logo_url', $brand) ? $brand['logo_url'] : null;
        if (!is_string($logoUrl) || trim($logoUrl) === '') {
            $brand['logo_url'] = '/brand/siro-commerce-mark.svg';
            $config['brand'] = $brand;
        }
        return $config;
    }

    private function version(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 1;
    }
}
