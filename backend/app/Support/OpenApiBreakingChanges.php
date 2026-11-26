<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Compares two OpenAPI documents for changes that can break existing clients.
 * This intentionally ignores descriptions and other non-runtime metadata.
 */
final class OpenApiBreakingChanges
{
    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return list<string>
     */
    public static function detect(array $baseline, array $current): array
    {
        /** @var list<string> $changes */
        $changes = [];
        $baselinePaths = is_array($baseline['paths'] ?? null) ? $baseline['paths'] : [];
        $currentPaths = is_array($current['paths'] ?? null) ? $current['paths'] : [];

        foreach ($baselinePaths as $path => $baselineOperations) {
            if (!array_key_exists($path, $currentPaths)) {
                $changes[] = "removed path {$path}";
                continue;
            }
            if (!is_array($baselineOperations) || !is_array($currentPaths[$path])) {
                continue;
            }
            foreach ($baselineOperations as $method => $baselineOperation) {
                if (!is_string($method) || !self::isOperation($method, $baselineOperation)) {
                    continue;
                }
                $label = strtoupper((string) $method) . ' ' . $path;
                $currentOperation = self::map($currentPaths[$path][$method] ?? null);
                if ($currentOperation === null) {
                    $changes[] = "removed operation {$label}";
                    continue;
                }
                self::compareOperation($changes, $label, self::map($baselineOperation) ?? [], $currentOperation);
            }
        }

        $baselineComponents = is_array($baseline['components'] ?? null) ? $baseline['components'] : [];
        $currentComponents = is_array($current['components'] ?? null) ? $current['components'] : [];
        $baselineSchemas = is_array($baselineComponents['schemas'] ?? null) ? $baselineComponents['schemas'] : [];
        $currentSchemas = is_array($currentComponents['schemas'] ?? null) ? $currentComponents['schemas'] : [];
        foreach ($baselineSchemas as $name => $schema) {
            if (!array_key_exists($name, $currentSchemas)) {
                $changes[] = "removed schema {$name}";
                continue;
            }
            $baselineSchema = self::map($schema);
            $currentSchema = self::map($currentSchemas[$name]);
            if ($baselineSchema !== null && $currentSchema !== null) {
                self::compareSchema($changes, "schema {$name}", $baselineSchema, $currentSchema);
            }
        }

        return array_values(array_unique($changes));
    }

    /**
     * @param list<string> $changes
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     */
    private static function compareOperation(array &$changes, string $label, array $baseline, array $current): void
    {
        if (($baseline['operationId'] ?? null) !== ($current['operationId'] ?? null)) {
            $changes[] = "changed operationId for {$label}";
        }
        if (($baseline['security'] ?? null) !== ($current['security'] ?? null)
            && self::securityRequired($current) && !self::securityRequired($baseline)) {
            $changes[] = "strengthened security for {$label}";
        }

        $baselineParameters = self::parameters($baseline);
        $currentParameters = self::parameters($current);
        foreach ($baselineParameters as $key => $parameter) {
            if (!array_key_exists($key, $currentParameters)) {
                $changes[] = "removed parameter {$key} from {$label}";
                continue;
            }
            if ($parameter['required'] === false && $currentParameters[$key]['required'] === true) {
                $changes[] = "made parameter {$key} required in {$label}";
            }
        }
        if (!self::requestBodyRequired($baseline['requestBody'] ?? null)
            && self::requestBodyRequired($current['requestBody'] ?? null)) {
            $changes[] = "made request body required for {$label}";
        }

        $baselineResponses = is_array($baseline['responses'] ?? null) ? $baseline['responses'] : [];
        $currentResponses = is_array($current['responses'] ?? null) ? $current['responses'] : [];
        foreach ($baselineResponses as $status => $_response) {
            if (!array_key_exists($status, $currentResponses)) {
                $changes[] = "removed response {$status} from {$label}";
            }
        }
    }

    /**
     * @param list<string> $changes
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     */
    private static function compareSchema(array &$changes, string $label, array $baseline, array $current): void
    {
        if (($baseline['type'] ?? null) !== ($current['type'] ?? null) && isset($baseline['type'], $current['type'])) {
            $changes[] = "changed {$label} type";
        }
        $baselineRequired = is_array($baseline['required'] ?? null) ? $baseline['required'] : [];
        $currentRequired = is_array($current['required'] ?? null) ? $current['required'] : [];
        foreach ($currentRequired as $property) {
            if (!in_array($property, $baselineRequired, true)) {
                if (is_scalar($property)) {
                    $changes[] = "added required property {$label}." . (string) $property;
                }
            }
        }
        $baselineProperties = is_array($baseline['properties'] ?? null) ? $baseline['properties'] : [];
        $currentProperties = is_array($current['properties'] ?? null) ? $current['properties'] : [];
        foreach ($baselineProperties as $property => $baselineProperty) {
            if (!is_string($property) || !array_key_exists($property, $currentProperties)) {
                $changes[] = "removed property {$label}." . (string) $property;
                continue;
            }
            $currentProperty = $currentProperties[$property];
            if (is_array($baselineProperty) && is_array($currentProperty)
                && isset($baselineProperty['type'], $currentProperty['type'])
                && $baselineProperty['type'] !== $currentProperty['type']) {
                $changes[] = "changed property {$label}." . $property . ' type';
            }
        }
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, array{required: bool}>
     */
    private static function parameters(array $operation): array
    {
        $parameters = [];
        $rawParameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        foreach ($rawParameters as $parameter) {
            if (is_array($parameter) && isset($parameter['name'], $parameter['in'])
                && is_scalar($parameter['name']) && is_scalar($parameter['in'])) {
                $parameters[(string) $parameter['in'] . ':' . (string) $parameter['name']] = [
                    'required' => ($parameter['required'] ?? false) === true,
                ];
            }
        }
        return $parameters;
    }

    /** @param array<string, mixed> $operation */
    private static function securityRequired(array $operation): bool
    {
        return array_key_exists('security', $operation) && $operation['security'] !== [];
    }

    private static function requestBodyRequired(mixed $requestBody): bool
    {
        return is_array($requestBody) && ($requestBody['required'] ?? false) === true;
    }

    private static function isOperation(string|int $method, mixed $operation): bool
    {
        return is_string($method) && in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'], true) && self::map($operation) !== null;
    }

    /** @return array<string, mixed>|null */
    private static function map(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $map[$key] = $item;
            }
        }
        return $map;
    }
}
