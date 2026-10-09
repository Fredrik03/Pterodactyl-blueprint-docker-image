<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Providers;

/**
 * A mod source. Both implementations return the same plain-array shapes so the
 * controller and the frontend never need to know which site a mod came from.
 *
 * Project:  provider, id, slug, name, summary, icon_url, downloads, author, url,
 *           categories[], game_versions[], client_side, server_side, updated_at
 * Version:  provider, id, project_id, name, version_number, type (release|beta|alpha),
 *           published_at, game_versions[], loaders[], file{url,filename,size,sha1,sha512}|null,
 *           unavailable_reason|null, page_url|null, dependencies[{project_id,version_id,type}]
 */
interface ModProvider
{
    public function id(): string;

    public function label(): string;

    /**
     * @return array{results: array[], total: int, page: int, page_size: int}
     */
    public function search(string $query, string $loader, ?string $gameVersion, int $page, int $pageSize, string $sort): array;

    public function project(string $projectId): ?array;

    /**
     * Versions compatible with the loader (and game version when known), newest first.
     *
     * @return array[]
     */
    public function versions(string $projectId, string $loader, ?string $gameVersion): array;

    public function version(string $projectId, string $versionId): ?array;
}
