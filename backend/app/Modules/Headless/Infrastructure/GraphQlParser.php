<?php

declare(strict_types=1);

namespace App\Modules\Headless\Infrastructure;

use App\Modules\Headless\Domain\HeadlessException;

/** Small, deliberately allowlist-oriented parser for the read-only contract. */
final class GraphQlParser
{
    private int $position = 0;
    /** @var array<int, string> */
    private array $tokens = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $fragments = [];
    /** @var array<string, mixed> */
    private array $variables = [];

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function parse(string $source, array $variables = []): array
    {
        if (strlen($source) > 20000) throw new HeadlessException('QUERY_TOO_LARGE', 'Query exceeds the maximum size.');
        $this->tokens = $this->tokenize($source);
        if (count($this->tokens) > 2000) throw new HeadlessException('REQUEST_LIMIT', 'Query token limit exceeded.');
        if ($this->tokens === []) throw new HeadlessException('PARSE_ERROR', 'Query is empty or invalid.');
        $this->position = 0;
        $this->variables = $variables;
        $this->fragments = [];
        $operation = 'query';
        if ($this->peek() === 'query' || $this->peek() === 'mutation') {
            $operation = (string) $this->take();
            if ($this->peek() !== null && $this->peek() !== '{' && $this->peek() !== '(') $this->take(); // optional operation name
            if ($this->peek() === '(') $this->variableDefinitions();
        }
        if ($operation === 'mutation') throw new HeadlessException('READ_ONLY', 'Mutations are not supported by this endpoint.');
        if ($this->peek() !== '{') throw new HeadlessException('PARSE_ERROR', 'Query must contain a selection set.');
        $selection = $this->selection(1);
        while ($this->peek() !== null) {
            if ($this->take() !== 'fragment') throw new HeadlessException('PARSE_ERROR', 'Unexpected tokens after query.');
            $this->fragmentDefinition();
        }
        $selection = $this->expandFragments($selection, []);
        return ['operation' => $operation, 'selection' => $selection];
    }

    /**
     * @return array<int, array<string, mixed>>
     * @phpstan-impure
     */
    private function selection(int $depth): array
    {
        if ($depth > 8 || $this->take() !== '{') throw new HeadlessException('DEPTH_LIMIT', 'Query depth exceeds the maximum of 8.');
        $fields = [];
        while (($token = $this->peek()) !== null && $token !== '}') {
            if ($token === '...') {
                $this->take();
                $fragment = $this->take();
                if ($fragment === null || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fragment)) throw new HeadlessException('PARSE_ERROR', 'Invalid fragment spread.');
                $fields[] = ['name' => '...', 'fragment' => $fragment, 'args' => [], 'children' => []];
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $token)) throw new HeadlessException('PARSE_ERROR', 'Invalid field name.');
            $name = (string) $this->take();
            $alias = null;
            if ($this->peek() === ':') { $this->take(); $alias = $name; $name = (string) $this->take(); }
            $args = [];
            if ($this->peek() === '(') $args = $this->arguments();
            $children = [];
            if ($this->peek() === '{') $children = $this->selection($depth + 1);
            $fields[] = ['name' => $name, 'alias' => $alias, 'args' => $args, 'children' => $children];
        }
        if ($this->take() !== '}') throw new HeadlessException('PARSE_ERROR', 'Unclosed selection set.');
        return $fields;
    }

    /** @return array<string, string|int> */
    private function arguments(): array
    {
        $this->take(); $args = [];
        while (($name = $this->peek()) !== null && $name !== ')') {
            $this->take();
            if ($this->take() !== ':') throw new HeadlessException('PARSE_ERROR', 'Invalid argument.');
            $value = $this->take();
            if ($value === null || $value === '{' || $value === '[') throw new HeadlessException('PARSE_ERROR', 'Invalid argument value.');
            if (str_starts_with($value, '$')) {
                $variable = substr($value, 1);
                if (!array_key_exists($variable, $this->variables)) throw new HeadlessException('VALIDATION_ERROR', 'Variable $' . $variable . ' was not provided.');
                $value = $this->variables[$variable];
                if (!is_string($value) && !is_int($value)) throw new HeadlessException('VALIDATION_ERROR', 'Variable $' . $variable . ' must be a string or integer.');
            } elseif ($value[0] === '"') $value = trim($value, '"');
            elseif (is_numeric($value)) $value = (int) $value;
            elseif ($value === 'true' || $value === 'false') $value = $value === 'true' ? 1 : 0;
            elseif ($value === 'null') $value = '';
            $args[$name] = $value;
            if ($this->peek() === ',') $this->take();
        }
        if ($this->take() !== ')') throw new HeadlessException('PARSE_ERROR', 'Unclosed argument list.');
        return $args;
    }

    private function variableDefinitions(): void
    {
        $this->take();
        while ($this->peek() !== ')') {
            $name = $this->take();
            if ($name === null || !str_starts_with($name, '$') || $this->take() !== ':') throw new HeadlessException('PARSE_ERROR', 'Invalid variable definition.');
            $type = $this->take();
            if ($type === null || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $type)) throw new HeadlessException('PARSE_ERROR', 'Invalid variable type.');
            if ($this->peek() === '!') $this->take();
            if ($this->peek() === '=') { $this->take(); $this->take(); }
            if ($this->peek() === ',') $this->take();
        }
        $this->take();
    }

    private function fragmentDefinition(): void
    {
        $name = $this->take();
        if ($name === null || $this->take() !== 'on' || $this->take() === null) throw new HeadlessException('PARSE_ERROR', 'Invalid fragment definition.');
        $this->fragments[$name] = $this->selection(1);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, string> $stack
     * @return array<int, array<string, mixed>>
     */
    private function expandFragments(array $fields, array $stack): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (($field['name'] ?? '') === '...') {
                $name = is_string($field['fragment'] ?? null) ? $field['fragment'] : '';
                if (in_array($name, $stack, true)) throw new HeadlessException('VALIDATION_ERROR', 'Cyclic fragment reference.');
                if (!isset($this->fragments[$name])) throw new HeadlessException('VALIDATION_ERROR', 'Unknown fragment: ' . $name . '.');
                foreach ($this->expandFragments($this->fragments[$name], [...$stack, $name]) as $expanded) $out[] = $expanded;
                continue;
            }
            /** @var array<int, array<string, mixed>> $children */
            $children = is_array($field['children'] ?? null) ? $field['children'] : [];
            $field['children'] = $this->expandFragments($children, $stack);
            $out[] = $field;
        }
        return $out;
    }

    /** @return array<int, string> */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($source);
        while ($offset < $length) {
            if (preg_match('/\G\s+|\G,/', $source, $match, 0, $offset)) { $offset += strlen($match[0]); continue; }
            if (!preg_match('/\G\.\.\.|\G\$?[A-Za-z_][A-Za-z0-9_]*|\G-?[0-9]+|\G"[^"]*"|\G[!():{}\[\]=]/', $source, $match, 0, $offset)) throw new HeadlessException('PARSE_ERROR', 'Invalid query syntax.');
            $tokens[] = $match[0]; $offset += strlen($match[0]);
        }
        return $tokens;
    }

    private function peek(): ?string { return $this->tokens[$this->position] ?? null; }
    /** @phpstan-impure */
    private function take(): ?string { return $this->tokens[$this->position++] ?? null; }
}
