<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Providers;

class CurseForgeProvider extends AbstractProvider
{
    public const BASE = 'https://api.curseforge.com/v1';

    /** Minecraft on CurseForge. */
    public const GAME_ID = 432;

    /** The "Mods" class (section) for Minecraft. */
    public const CLASS_ID = 6;

    private const LOADER_TYPES = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];

    private const RELEASE_TYPES = [1 => 'release', 2 => 'beta', 3 => 'alpha'];

    private const RELATION_TYPES = [1 => 'embedded', 2 => 'optional', 3 => 'required', 4 => 'tool', 5 => 'incompatible', 6 => 'include'];

    public function id(): string
    {
        return 'curseforge';
    }

    public function label(): string
    {
        return 'CurseForge';
    }

    protected function headers(): array
    {
        return ['x-api-key' => (string) $this->settings->get('curseforge_api_key')];
    }

    public function search(string $query, string $loader, ?string $gameVersion, int $page, int $pageSize, string $sort): array
    {
        $params = [
            'gameId' => self::GAME_ID,
            'classId' => self::CLASS_ID,
            'searchFilter' => $query,
            'modLoaderType' => self::LOADER_TYPES[$loader] ?? 0,
            'sortField' => match ($sort) {
                'downloads' => 6,
                'newest' => 11,
                'updated' => 3,
                default => 2, // popularity
            },
            'sortOrder' => 'desc',
            'pageSize' => $pageSize,
            'index' => ($page - 1) * $pageSize,
        ];
        if ($gameVersion !== null) {
            $params['gameVersion'] = $gameVersion;
        }

        $data = $this->fetch('search:' . json_encode($params), 300, self::BASE . '/mods/search', $params) ?? [];

        $results = [];
        foreach ($data['data'] ?? [] as $mod) {
            if (is_array($mod) && isset($mod['id'])) {
                $results[] = $this->projectFromMod($mod);
            }
        }

        return [
            'results' => $results,
            'total' => (int) ($data['pagination']['totalCount'] ?? count($results)),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function project(string $projectId): ?array
    {
        $data = $this->fetch('project:' . $projectId, 900, self::BASE . '/mods/' . rawurlencode($projectId));
        $mod = $data['data'] ?? null;
        if (!is_array($mod) || !isset($mod['id'])) {
            return null;
        }

        return $this->projectFromMod($mod);
    }

    public function versions(string $projectId, string $loader, ?string $gameVersion): array
    {
        $params = [
            'modLoaderType' => self::LOADER_TYPES[$loader] ?? 0,
            'pageSize' => 50,
        ];
        if ($gameVersion !== null) {
            $params['gameVersion'] = $gameVersion;
        }

        $data = $this->fetch(
            'files:' . $projectId . ':' . json_encode($params),
            300,
            self::BASE . '/mods/' . rawurlencode($projectId) . '/files',
            $params
        ) ?? [];

        $slug = $this->slugFor($projectId);

        $versions = [];
        foreach ($data['data'] ?? [] as $file) {
            if (is_array($file) && isset($file['id'])) {
                $versions[] = $this->versionFromFile($projectId, $file, $slug);
            }
        }

        usort($versions, fn ($a, $b) => strcmp((string) $b['published_at'], (string) $a['published_at']));

        return $versions;
    }

    public function version(string $projectId, string $versionId): ?array
    {
        $data = $this->fetch(
            'file:' . $projectId . ':' . $versionId,
            300,
            self::BASE . '/mods/' . rawurlencode($projectId) . '/files/' . rawurlencode($versionId)
        );
        $file = $data['data'] ?? null;
        if (!is_array($file) || !isset($file['id'])) {
            return null;
        }
        if ((string) ($file['modId'] ?? '') !== $projectId) {
            return null;
        }

        return $this->versionFromFile($projectId, $file, $this->slugFor($projectId));
    }

    private function slugFor(string $projectId): ?string
    {
        try {
            return $this->project($projectId)['slug'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function projectFromMod(array $mod): array
    {
        $slug = $this->str($mod['slug'] ?? null);
        $authors = [];
        foreach ($mod['authors'] ?? [] as $author) {
            if (is_array($author) && isset($author['name'])) {
                $authors[] = (string) $author['name'];
            }
        }
        $categories = [];
        foreach ($mod['categories'] ?? [] as $category) {
            if (is_array($category) && isset($category['name'])) {
                $categories[] = (string) $category['name'];
            }
        }

        return [
            'provider' => $this->id(),
            'id' => (string) $mod['id'],
            'slug' => $slug,
            'name' => $this->str($mod['name'] ?? null, (string) $mod['id']),
            'summary' => $this->str($mod['summary'] ?? null, ''),
            'icon_url' => $this->str($mod['logo']['thumbnailUrl'] ?? ($mod['logo']['url'] ?? null)),
            'downloads' => (int) ($mod['downloadCount'] ?? 0),
            'author' => $authors[0] ?? null,
            'url' => $this->str($mod['links']['websiteUrl'] ?? null, 'https://www.curseforge.com/minecraft/mc-mods/' . ($slug ?? (string) $mod['id'])),
            'categories' => $categories,
            'game_versions' => [],
            'client_side' => null,
            'server_side' => null,
            'updated_at' => $this->str($mod['dateModified'] ?? null),
        ];
    }

    private function versionFromFile(string $projectId, array $file, ?string $slug): array
    {
        $gameVersions = [];
        $loaders = [];
        foreach ($this->strings($file['gameVersions'] ?? []) as $tag) {
            $lower = strtolower($tag);
            if (isset(self::LOADER_TYPES[$lower])) {
                $loaders[] = $lower;
            } elseif (preg_match('/^\d+\.\d+/', $tag)) {
                $gameVersions[] = $tag;
            }
        }

        $sha1 = null;
        foreach ($file['hashes'] ?? [] as $hash) {
            if (is_array($hash) && (int) ($hash['algo'] ?? 0) === 1) {
                $sha1 = $this->str($hash['value'] ?? null);
            }
        }

        $dependencies = [];
        foreach ($file['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency) || !isset($dependency['modId'])) {
                continue;
            }
            $dependencies[] = [
                'project_id' => (string) $dependency['modId'],
                'version_id' => null,
                'type' => self::RELATION_TYPES[(int) ($dependency['relationType'] ?? 0)] ?? 'optional',
            ];
        }

        $url = $this->str($file['downloadUrl'] ?? null);
        $fileName = $this->str($file['fileName'] ?? null, '');
        $pageUrl = $slug !== null
            ? sprintf('https://www.curseforge.com/minecraft/mc-mods/%s/files/%d', $slug, (int) $file['id'])
            : null;

        return [
            'provider' => $this->id(),
            'id' => (string) $file['id'],
            'project_id' => $projectId,
            'name' => $this->str($file['displayName'] ?? null, $fileName),
            'version_number' => $fileName,
            'type' => self::RELEASE_TYPES[(int) ($file['releaseType'] ?? 1)] ?? 'release',
            'published_at' => $this->str($file['fileDate'] ?? null),
            'game_versions' => $gameVersions,
            'loaders' => $loaders,
            'file' => $url === null ? null : [
                'url' => $url,
                'filename' => $fileName,
                'size' => isset($file['fileLength']) ? (int) $file['fileLength'] : null,
                'sha1' => $sha1,
                'sha512' => null,
            ],
            'unavailable_reason' => $url === null
                ? 'The author has disabled third-party downloads for this file. Download it from CurseForge and upload it through the file manager.'
                : null,
            'page_url' => $pageUrl,
            'dependencies' => $dependencies,
        ];
    }
}
