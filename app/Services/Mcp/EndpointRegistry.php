<?php

namespace Pterodactyl\Services\Mcp;

class EndpointRegistry
{
    /**
     * Every row of the endpoint table, keyed by the tool name it is exposed as.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $rows = [];

    public function __construct(?string $path = null)
    {
        $path = $path ?? __DIR__ . '/endpoints.php';

        // A missing table leaves the server answering with an empty tool list rather than
        // failing every request outright, which is the same way the Panel degrades when the
        // OAuth signing keys have not been generated yet.
        foreach (is_file($path) ? (array) require $path : [] as $row) {
            if (is_array($row) && !empty($row['name'])) {
                $this->rows[$row['name']] = $row;
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->rows[$name] ?? null;
    }

    /**
     * The API a row's request is made against. This is deliberately derived from the row
     * itself and from nothing a caller can influence: it is what keeps the arguments of a
     * tool call from pointing the internal request at a different part of the Panel than
     * the tool it was invoked as, /mcp included.
     *
     * @param array<string, mixed> $row
     */
    public static function basePath(array $row): string
    {
        return ($row['api'] ?? null) === 'application' ? '/api/application' : '/api/client';
    }
}
