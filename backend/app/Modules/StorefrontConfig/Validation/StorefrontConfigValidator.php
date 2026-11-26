<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Validation;

use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use App\Modules\Experimentation\Domain\ExperimentResolver;

final class StorefrontConfigValidator
{
    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>|null
     */
    public function writeConfig(array $value): ?array
    {
        $themeValue = $value['theme'] ?? null;
        $verticalValue = $value['vertical'] ?? null;
        $theme = is_array($themeValue) ? $this->stringKeyed($themeValue) : null;
        $vertical = is_array($verticalValue) ? $this->stringKeyed($verticalValue) : null;
        if ($theme === null || $vertical === null || !is_string($theme['key'] ?? null) || !is_string($vertical['key'] ?? null)) return null;
        $allowedThemes = ['editorial', 'general', 'noir', 'forest', 'seasonal', 'beauty', 'pharmacy', 'fashion', 'fnb', 'electronics', 'b2b'];
        $isValidTheme = in_array($theme['key'], $allowedThemes, true) || preg_match('/^[a-z][a-z0-9_-]{2,39}$/', $theme['key']) === 1;
        $allowedVerticals = ['general','apparel','home','food_beverage','digital','marketplace','b2b','beauty','pharmacy','fashion','fnb','electronics','grocery','cosmetics','footwear','jewelry','furniture','sports','pets','baby','books','flowers','supplements','auto'];
        if (!$isValidTheme || !in_array($vertical['key'], $allowedVerticals, true)) return null;
        return $this->publicConfig($value);
    }
    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    public function publicConfig(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $root = $this->stringKeyed($value);
        if ($root === null) return null;
        $value = $root;
        $required = ['scope', 'theme', 'vertical', 'brand', 'navigation', 'homepage', 'presentation', 'policies', 'version'];
        foreach ($required as $key) if (!array_key_exists($key, $value)) return null;
        if (!is_array($value['scope']) || !is_array($value['theme']) || !is_array($value['vertical']) || !is_array($value['homepage'])) return null;
        $theme = $this->stringKeyed($value['theme']);
        $homepage = $this->stringKeyed($value['homepage']);
        if ($theme === null || $homepage === null) return null;
        if (!is_int($value['version']) && !is_numeric($value['version'])) return null;
        $sections = $homepage['sections'] ?? null;
        if (!is_array($sections) || count($sections) > 50) return null;
        $ids = [];
        foreach ($sections as $section) {
            if (!is_array($section)) return null;
            $section = $this->stringKeyed($section);
            if ($section === null || !is_string($section['id'] ?? null) || !is_string($section['type'] ?? null)) return null;
            if (in_array($section['id'], $ids, true) || !in_array($section['type'], StorefrontConfigDefaults::allowedSections(), true)) return null;
            $ids[] = $section['id'];
            if (!is_int($section['order'] ?? null) || $section['order'] < 0 || $section['order'] > 10000) return null;
            $props = $section['props'] ?? null;
            if ($props !== null && !is_array($props)) return null;
            if (strlen($section['id']) > 128 || strlen($section['type']) > 64) return null;
            if ($section['type'] === 'product_collection') {
                $props = is_array($props) ? $this->stringKeyed($props) : [];
                $source = is_array($props) ? ($props['source'] ?? null) : null;
                if (!is_string($source) || !preg_match('/^(?:tag:[A-Za-z0-9_-]{1,64}|category:[A-Za-z0-9_-]{1,64}|featured|best_selling|recent)$/', $source)) return null;
            }
            if (in_array($section['type'], ['banner_grid', 'usp_strip', 'faq'], true) && !$this->validMultiVerticalProps($section['type'], $props)) return null;
        }
        $policies = $value['policies'] ?? [];
        if (!is_array($policies)) return null;
        foreach ($policies as $url) {
            if (!is_string($url) || strlen($url) > 512 || preg_match('~^(?:javascript|data):~i', $url) === 1 || (str_starts_with($url, '/') === false && filter_var($url, FILTER_VALIDATE_URL) === false)) return null;
        }
        if (array_key_exists('experiments', $value)) {
            if (!is_array($value['experiments']) || strlen((string) json_encode($value['experiments'])) > ExperimentResolver::MAX_CONFIG_BYTES) return null;
            if (count(ExperimentResolver::sanitize($value['experiments'])) !== count($value['experiments'])) return null;
        }
        $tokens = $theme['tokens'] ?? [];
        if (!is_array($tokens)) return null;
        $colors = $tokens['colors'] ?? [];
        if (!is_array($colors)) return null;
        foreach ($colors as $color) if (!is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) return null;
        if (!$this->validTypography($tokens['typography'] ?? null)) return null;
        $header = $this->validHeader($value['header'] ?? null);
        $footer = $this->validFooter($value['footer'] ?? null);
        if ($header === null || $footer === null) return null;
        $value['header'] = $header;
        $value['footer'] = $footer;
        $presentation = $value['presentation'] ?? [];
        if (!is_array($presentation)) return null;
        foreach (['product_card_variant' => ['editorial', 'minimal', 'spotlight'], 'product_detail_variant' => ['editorial', 'focused', 'page-gallery-left', 'split-buybox'], 'product_list_variant' => ['list-editorial', 'list-grid-wide'], 'purchase_variant' => ['standard', 'compact']] as $field => $allowed) {
            $variant = $presentation[$field] ?? null;
            if ($variant !== null && (!is_string($variant) || !in_array($variant, $allowed, true))) return null;
        }
        $purchase = $value['purchase'] ?? [];
        if (!is_array($purchase)) return null;
        $quoteMode = $purchase['quote_mode'] ?? 'required';
        if (!is_string($quoteMode) || !in_array($quoteMode, ['required', 'auto', 'off'], true)) return null;
        $value['purchase'] = ['quote_mode' => $quoteMode];
        try { $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); } catch (\JsonException) { return null; }
        if (preg_match('/(?:javascript|data|<script|<style|<\/?[a-z][^>]*>)/i', $json) === 1) return null;
        return $value;
    }

    /**
     * Multi-vertical blocks keep their props small and safe: links follow the
     * same href policy as footer links and copy is bounded so the renderer
     * never receives unbounded or executable content.
     * @param mixed $props
     */
    private function validMultiVerticalProps(string $type, mixed $props): bool
    {
        if ($props === null) return true;
        if (!is_array($props) || $props === []) return true;
        if (array_is_list($props)) {
            return match ($type) {
                'banner_grid' => $this->validBannerGrid($props),
                'usp_strip' => $this->validUspStrip($props),
                'faq' => $this->validFaq($props),
                default => true,
            };
        }
        $clean = $this->stringKeyed($props);
        if ($clean === null) return false;
        return match ($type) {
            'banner_grid' => $this->validBannerGrid($clean['banners'] ?? $clean['items'] ?? null),
            'usp_strip' => $this->validUspStrip($clean['items'] ?? null),
            'faq' => $this->validFaq($clean['items'] ?? null),
            default => true,
        };
    }

    /** @param mixed $value */
    private function validBannerGrid(mixed $value): bool
    {
        if ($value === null) return true;
        if (!is_array($value) || count($value) < 2 || count($value) > 4) return false;
        foreach ($value as $banner) {
            if (!is_array($banner)) return false;
            $banner = $this->stringKeyed($banner);
            if ($banner === null) return false;
            $title = $banner['title'] ?? null;
            $href = $banner['href'] ?? null;
            if (!is_string($title) || trim($title) === '' || strlen($title) > 120) return false;
            if (!is_string($href) || !$this->validHref($href)) return false;
            $subtitle = $banner['subtitle'] ?? null;
            if ($subtitle !== null && (!is_string($subtitle) || strlen($subtitle) > 200)) return false;
            $image = $banner['image'] ?? null;
            if ($image !== null && (!is_string($image) || !$this->validHref($image))) return false;
            $cta = $banner['cta'] ?? null;
            if ($cta === null) continue;
            if (!is_array($cta)) return false;
            $cta = $this->stringKeyed($cta);
            if ($cta === null) return false;
            $label = $cta['label'] ?? null;
            $target = $cta['href'] ?? null;
            if (!is_string($label) || $label === '' || strlen($label) > 80) return false;
            if (!is_string($target) || !$this->validHref($target)) return false;
        }
        return true;
    }

    /** @param mixed $value */
    private function validUspStrip(mixed $value): bool
    {
        if ($value === null) return true;
        if (!is_array($value) || count($value) < 3 || count($value) > 4) return false;
        foreach ($value as $item) {
            if (!is_array($item)) return false;
            $item = $this->stringKeyed($item);
            if ($item === null) return false;
            $title = $item['title'] ?? null;
            $copy = $item['copy'] ?? null;
            $icon = $item['icon'] ?? null;
            if (!is_string($title) || trim($title) === '' || strlen($title) > 80) return false;
            if (!is_string($copy) || trim($copy) === '' || strlen($copy) > 240) return false;
            if ($icon !== null && (!is_string($icon) || strlen($icon) > 40)) return false;
        }
        return true;
    }

    /** @param mixed $value */
    private function validFaq(mixed $value): bool
    {
        if ($value === null) return true;
        if (!is_array($value) || count($value) < 1 || count($value) > 20) return false;
        foreach ($value as $item) {
            if (!is_array($item)) return false;
            $item = $this->stringKeyed($item);
            if ($item === null) return false;
            $question = $item['question'] ?? null;
            $answer = $item['answer'] ?? null;
            if (!is_string($question) || trim($question) === '' || strlen($question) > 200) return false;
            if (!is_string($answer) || trim($answer) === '' || strlen($answer) > 2000) return false;
        }
        return true;
    }

    /**
     * Typography that actually renders: font stacks become CSS variables, so
     * they must be plain strings (no urls, no expressions, no markup).
     * @param mixed $value
     */
    private function validTypography(mixed $value): bool
    {
        // Absent typography inherits the theme default downstream; a present
        // block must be complete because it renders straight into CSS.
        if ($value === null) return true;
        if (!is_array($value)) return false;
        foreach (['display', 'body'] as $label) {
            if (!isset($value[$label]) || !is_string($value[$label]) || strlen($value[$label]) > 40 || preg_match('/[<>]/', $value[$label]) === 1) return false;
        }
        if (($value['density'] ?? null) !== null && !in_array($value['density'], ['compact', 'comfortable', 'spacious'], true)) return false;
        foreach (['display_stack', 'body_stack'] as $stack) {
            $fonts = $value[$stack] ?? null;
            if ($fonts === null) continue;
            if (!is_string($fonts) || strlen($fonts) > 200 || preg_match('/[<>;{}]/', $fonts) === 1 || preg_match('/\b(url|expression|javascript|data)\b/i', $fonts) === 1) return false;
        }
        $size = $value['base_size'] ?? null;
        if ($size !== null && (!is_int($size) || $size < 12 || $size > 22)) return false;
        return true;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function validHeader(mixed $value): ?array
    {
        $defaults = ['announcement' => true, 'layout' => 'standard', 'sticky' => false];
        if ($value === null || $value === []) return $defaults;
        if (!is_array($value)) return null;
        foreach (array_keys($value) as $key) if (!in_array($key, ['announcement', 'layout', 'sticky'], true)) return null;
        $layout = $value['layout'] ?? $defaults['layout'];
        if (!is_string($layout) || !in_array($layout, ['standard', 'minimal', 'centered', 'split'], true)) return null;
        return ['announcement' => ($value['announcement'] ?? $defaults['announcement']) === true, 'layout' => $layout, 'sticky' => ($value['sticky'] ?? $defaults['sticky']) === true];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function validFooter(mixed $value): ?array
    {
        $defaults = ['layout' => 'standard', 'show_help' => true, 'show_policies' => true, 'columns' => []];
        if ($value === null || $value === []) return $defaults;
        if (!is_array($value)) return null;
        foreach (array_keys($value) as $key) if (!in_array($key, ['layout', 'show_help', 'show_policies', 'columns'], true)) return null;
        $layout = $value['layout'] ?? $defaults['layout'];
        if (!is_string($layout) || !in_array($layout, ['standard', 'minimal', 'mega'], true)) return null;
        $columns = $value['columns'] ?? [];
        if (!is_array($columns) || count($columns) > 6) return null;
        $clean = [];
        foreach ($columns as $column) {
            if (!is_array($column)) return null;
            $title = $column['title'] ?? null;
            $links = $column['links'] ?? null;
            if (!is_string($title) || strlen($title) > 80 || !is_array($links) || count($links) > 8) return null;
            $cleanLinks = [];
            foreach ($links as $link) {
                if (!is_array($link) || !is_string($link['label'] ?? null) || !is_string($link['href'] ?? null)) return null;
                if (strlen($link['label']) > 80 || !$this->validHref($link['href'])) return null;
                $cleanLinks[] = ['label' => $link['label'], 'href' => $link['href']];
            }
            $clean[] = ['title' => $title, 'links' => $cleanLinks];
        }
        return ['layout' => $layout, 'show_help' => ($value['show_help'] ?? true) === true, 'show_policies' => ($value['show_policies'] ?? true) === true, 'columns' => $clean];
    }

    private function validHref(string $href): bool
    {
        if (strlen($href) > 512 || preg_match('~^(?:javascript|data):~i', $href) === 1) return false;
        return str_starts_with($href, '/') || filter_var($href, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param array<mixed, mixed> $value
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
