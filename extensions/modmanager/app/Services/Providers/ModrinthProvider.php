<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Providers;

class ModrinthProvider extends AbstractProvider
{
    public const BASE = 'https://api.modrinth.com/v2';

    private const LOADER_TAGS = ['forge', 'neoforge', 'fabric', 'quilt', 'liteloader', 'rift', 'modloader', 'bukkit', 'spigot', 'paper', 'purpur', 'folia', 'sponge', 'bungeecord', 'velocity', 'waterfall', 'datapack', 'minecraft'];

    public function id(): string
    {
        return 'modrinth';
    }

    public function label(): string
    {
        return 'Modrinth';
    }

    protected function headers(): array
    {
        return [];
    }

    public function search(string $query, string $loader, ?string $gameVersion, int $page, int $pageSize, string $sort): array
    {
        $facets = [
            ['project_type:mod'],
            ['categories:' . $loader],
            // Client-only mods crash or do nothing on a server; hide them.
            ['server_side:required', 'server_side:optional'],
        ];
        if ($gameVersion !== null) {
            $facets[] = ['versions:' . $gameVersion];
        }

        $index = match ($sort) {
            'downloads' => 'downloads',
            'newest' => 'newest',
            'updated' => 'updated',
            'follows' => 'follows',
            default => 'relevance',
        };

        $params = [
            'query' => $query,
            'facets' => json_encode($facets),
            'index' => $index,
            'limit' => $pageSize,
            'offset' => ($page - 1) * $pageSize,
        ];

        $data = $this->fetch('search:' . json_encode($params), 300, self::BASE . '/search', $params) ?? [];

        $results = [];
        foreach ($data['hits'] ?? [] as $hit) {
            if (is_array($hit) && isset($hit['project_id'])) {
                $results[] = $this->projectFromHit($hit);
            }
        }

        return [
            'results' => $results,
            'total' => (int) ($data['total_hits'] ?? count($results)),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function project(string $projectId): ?array
    {
        $data = $this->fetch('project:' . $projectId, 900, self::BASE . '/project/' . rawurlencode($projectId));
        if ($data === null || !isset($data['id'])) {
            return null;
        }

        return [
            'provider' => $this->id(),
            'id' => $this->str($data['id']),
            'slug' => $this->str($data['slug'] ?? null),
            'name' => $this->str($data['title'] ?? null, $this->str($data['id'])),
            'summary' => $this->str($data['description'] ?? null, ''),
            'icon_url' => $this->str($data['icon_url'] ?? null),
            'downloads' => (int) ($data['downloads'] ?? 0),
            'author' => null,
            'url' => 'https://modrinth.com/mod/' . rawurlencode($this->str($data['slug'] ?? null, $this->str($data['id']))),
            'categories' => array_values(array_diff($this->strings($data['categories'] ?? []), self::LOADER_TAGS)),
            'game_versions' => $this->strings($data['game_versions'] ?? []),
            'client_side' => $this->str($data['client_side'] ?? null),
            'server_side' => $this->str($data['server_side'] ?? null),
            'updated_at' => $this->str($data['updated'] ?? null),
        ];
    }

    public function versions(string $projectId, string $loader, ?string $gameVersion): array
    {
        $params = [
            'loaders' => json_encode([$loader]),
            'include_changelog' => 'false',
        ];
        if ($gameVersion !== null) {
            $params['game_versions'] = json_encode([$gameVersion]);
        }

        $data = $this->fetch(
            'versions:' . $projectId . ':' . json_encode($params),
            300,
            self::BASE . '/project/' . rawurlencode($projectId) . '/version',
            $params
        ) ?? [];

        $versions = [];
        foreach ($data as $item) {
            if (is_array($item) && isset($item['id'])) {
                $versions[] = $this->versionFromData($item);
            }
        }

        usort($versions, fn ($a, $b) => strcmp((string) $b['published_at'], (string) $a['published_at']));

        return $versions;
    }

    public function version(string $projectId, string $versionId): ?array
    {
        $data = $this->fetch('version:' . $versionId, 300, self::BASE . '/version/' . rawurlencode($versionId));
        if ($data === null || !isset($data['id'])) {
            return null;
        }

        // Accept both the project id and its slug as "project" identifiers.
        $belongs = ($data['project_id'] ?? null) === $projectId;
        if (!$belongs) {
            $project = $this->project($projectId);
            $belongs = $project !== null && $project['id'] === ($data['project_id'] ?? null);
        }
        if (!$belongs) {
            return null;
        }

        return $this->versionFromData($data);
    }

    private function projectFromHit(array $hit): array
    {
        $slug = $this->str($hit['slug'] ?? null, $this->str($hit['project_id']));

        return [
            'provider' => $this->id(),
            'id' => $this->str($hit['project_id']),
            'slug' => $slug,
            'name' => $this->str($hit['title'] ?? null, $slug),
            'summary' => $this->str($hit['description'] ?? null, ''),
            'icon_url' => $this->str($hit['icon_url'] ?? null),
            'downloads' => (int) ($hit['downloads'] ?? 0),
            'author' => $this->str($hit['author'] ?? null),
            'url' => 'https://modrinth.com/mod/' . rawurlencode($slug),
            'categories' => array_values(array_diff($this->strings($hit['categories'] ?? []), self::LOADER_TAGS)),
            'game_versions' => $this->strings($hit['versions'] ?? []),
            'client_side' => $this->str($hit['client_side'] ?? null),
            'server_side' => $this->str($hit['server_side'] ?? null),
            'updated_at' => $this->str($hit['date_modified'] ?? null),
        ];
    }

    private function versionFromData(array $data): array
    {
        $files = array_values(array_filter($data['files'] ?? [], 'is_array'));
        $primary = null;
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                $primary = $file;
                break;
            }
        }
        if ($primary === null) {
            foreach ($files as $file) {
                if (str_ends_with(strtolower((string) ($file['filename'] ?? '')), '.jar')) {
                    $primary = $file;
                    break;
                }
            }
        }
        $primary = $primary ?? ($files[0] ?? null);

        $dependencies = [];
        foreach ($data['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }
            $dependencies[] = [
                'project_id' => $this->str($dependency['project_id'] ?? null),
                'version_id' => $this->str($dependency['version_id'] ?? null),
                'type' => $this->str($dependency['dependency_type'] ?? null, 'required'),
            ];
        }

        return [
            'provider' => $this->id(),
            'id' => $this->str($data['id']),
            'project_id' => $this->str($data['project_id'] ?? null, ''),
            'name' => $this->str($data['name'] ?? null, $this->str($data['version_number'] ?? null, '')),
            'version_number' => $this->str($data['version_number'] ?? null, ''),
            'type' => in_array($data['version_type'] ?? null, ['release', 'beta', 'alpha'], true) ? $data['version_type'] : 'release',
            'published_at' => $this->str($data['date_published'] ?? null),
            'game_versions' => $this->strings($data['game_versions'] ?? []),
            'loaders' => array_map('strtolower', $this->strings($data['loaders'] ?? [])),
            'file' => $primary === null ? null : [
                'url' => $this->str($primary['url'] ?? null, ''),
                'filename' => $this->str($primary['filename'] ?? null, ''),
                'size' => isset($primary['size']) ? (int) $primary['size'] : null,
                'sha1' => $this->str($primary['hashes']['sha1'] ?? null),
                'sha512' => $this->str($primary['hashes']['sha512'] ?? null),
            ],
            'unavailable_reason' => $primary === null ? 'This version has no downloadable file.' : null,
            'page_url' => null,
            'dependencies' => $dependencies,
        ];
    }
}
