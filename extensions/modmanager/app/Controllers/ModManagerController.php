<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests\ContextRequest;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests\InstallRequest;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests\ReadRequest;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests\RemoveRequest;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests\SearchRequest;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests\ToggleRequest;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests\VersionsRequest;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\GameVersions;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\InstallRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\LoaderDetector;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\ProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Providers\ProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Settings;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary;

class ModManagerController extends ClientApiController
{
    /** Seconds a detection result is reused before Wings is asked again. */
    private const DETECTION_TTL = 120;

    /** Settings "table" holding the per-server version/loader choice (key = server uuid). */
    private const CONTEXT_TABLE = 'modmanager_ctx';

    /** Only these hosts may be handed to Wings as download sources. */
    private const ALLOWED_HOSTS = [
        'cdn.modrinth.com',
        'cdn-raw.modrinth.com',
        'edge.forgecdn.net',
        'mediafilez.forgecdn.net',
        'media.forgecdn.net',
        'files.forgecdn.net',
    ];

    public function __construct(
        private Settings $settings,
        private LoaderDetector $detector,
        private ProviderRegistry $providers,
        private InstallRegistry $registry,
        private DaemonFileRepository $files,
        private GameVersions $gameVersions,
        private BlueprintClientLibrary $blueprint,
    ) {
        parent::__construct();
    }

    /**
     * Everything the page needs on load: which providers are on, what loader
     * and Minecraft version were detected, and whether users may override them.
     */
    public function status(ReadRequest $request, Server $server): array
    {
        $detection = $this->detection($server, $request->boolean('refresh'));

        return [
            'object' => 'modmanager_status',
            'attributes' => [
                'extension_version' => Settings::VERSION,
                'settings' => $this->settings->public(),
                'providers' => $this->providers->enabled(),
                'loaders' => LoaderDetector::LOADERS,
                'detection' => $detection,
                'saved' => $this->savedContext($server),
                'game_versions' => $this->gameVersions->byLoader(),
            ],
        ];
    }

    /**
     * Remember which Minecraft version (and, when overrides are allowed, which
     * loader) this server should be treated as. Shared by everyone with access
     * to the server, so one person picking it is enough.
     */
    public function saveContext(ContextRequest $request, Server $server): array
    {
        $loader = $request->input('loader');
        $gameVersion = $request->input('game_version');

        if (is_string($loader) && $loader !== '' && !$this->settings->get('allow_override')) {
            throw new DisplayException('Changing the detected mod loader has been disabled by the administrator.');
        }

        $context = [
            'loader' => is_string($loader) && in_array($loader, LoaderDetector::LOADERS, true) ? $loader : null,
            'game_version' => is_string($gameVersion) && $gameVersion !== '' ? $gameVersion : null,
        ];

        if ($context['loader'] === null && $context['game_version'] === null) {
            $this->blueprint->dbForget(self::CONTEXT_TABLE, $server->uuid);
        } else {
            $this->blueprint->dbSet(self::CONTEXT_TABLE, $server->uuid, $context);
        }

        Activity::event('server:modmanager.context')->property($context)->log();

        return ['object' => 'modmanager_context', 'attributes' => $context];
    }

    public function search(SearchRequest $request, Server $server): array
    {
        $context = $this->context($request, $server);
        $provider = $this->providers->get((string) $request->input('provider'));

        $result = $provider->search(
            trim((string) $request->input('query', '')),
            $context['loader'],
            $context['game_version'],
            max(1, (int) $request->input('page', 1)),
            (int) $this->settings->get('page_size'),
            (string) $request->input('sort', 'relevance'),
        );

        $installed = $this->installedProjects($server);
        foreach ($result['results'] as &$project) {
            $project['installed'] = isset($installed[$provider->id() . ':' . $project['id']]);
        }
        unset($project);

        return [
            'object' => 'modmanager_search',
            'attributes' => $result + ['context' => $context],
        ];
    }

    public function versions(VersionsRequest $request, Server $server): array
    {
        $context = $this->context($request, $server);
        $provider = $this->providers->get((string) $request->input('provider'));
        $projectId = (string) $request->input('project');

        $versions = $provider->versions($projectId, $context['loader'], $context['game_version']);

        // Resolve dependency names so the dialog can say "requires Fabric API"
        // instead of showing an opaque id. Bounded so one dialog never fans out
        // into dozens of upstream calls.
        $installed = $this->installedProjects($server);
        $names = [];
        $lookups = 0;
        foreach ($versions as &$version) {
            foreach ($version['dependencies'] as &$dependency) {
                $id = $dependency['project_id'];
                if ($id === null) {
                    continue;
                }
                if (!array_key_exists($id, $names) && $lookups < 12 && in_array($dependency['type'], ['required', 'optional'], true)) {
                    $lookups++;
                    try {
                        $names[$id] = $provider->project($id);
                    } catch (\Throwable) {
                        $names[$id] = null;
                    }
                }
                $dependency['project_name'] = $names[$id]['name'] ?? null;
                $dependency['project_slug'] = $names[$id]['slug'] ?? null;
                $dependency['installed'] = isset($installed[$provider->id() . ':' . $id]);
            }
            unset($dependency);
        }
        unset($version);

        return [
            'object' => 'modmanager_versions',
            'attributes' => [
                'project_id' => $projectId,
                'versions' => $versions,
                'context' => $context,
            ],
        ];
    }

    /**
     * Every jar in the mods directory, merged with what this extension knows
     * about it. Files uploaded by hand still show up, flagged as unmanaged.
     */
    public function installed(ReadRequest $request, Server $server): array
    {
        $directory = (string) $this->settings->get('mods_directory');

        $exists = true;
        $error = null;
        $listing = [];
        try {
            $listing = $this->files->setServer($server)->getDirectory($directory);
        } catch (DaemonConnectionException $exception) {
            $exists = false;
            if (!$this->isMissingPath($exception)) {
                $error = $exception->getMessage();
            }
        }

        $present = [];
        $onDisk = [];
        foreach ($listing as $item) {
            if (!is_array($item) || !($item['file'] ?? true)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if (!preg_match('/\.jar(\.disabled)?$/i', $name)) {
                continue;
            }
            $present[] = $name;
            $onDisk[$name] = [
                'size' => (int) ($item['size'] ?? 0),
                'modified_at' => is_string($item['modified'] ?? null) ? $item['modified'] : null,
            ];
        }

        if ($exists && $error === null) {
            $this->registry->purgeMissing($server, $present);
        }
        $records = $this->registry->forServer($server);

        $mods = [];
        foreach ($onDisk as $name => $meta) {
            $record = $records[$name] ?? null;
            $mods[] = [
                'filename' => $name,
                'size' => $meta['size'],
                'modified_at' => $meta['modified_at'],
                'enabled' => !preg_match('/\.disabled$/i', $name),
                'managed' => $record !== null,
                'pending' => false,
                'record' => $record,
            ];
        }
        // Records without a file yet are downloads Wings has not started writing.
        foreach ($records as $name => $record) {
            if (!isset($onDisk[$name])) {
                $mods[] = [
                    'filename' => $name,
                    'size' => 0,
                    'modified_at' => null,
                    'enabled' => !preg_match('/\.disabled$/i', $name),
                    'managed' => true,
                    'pending' => true,
                    'record' => $record,
                ];
            }
        }

        usort($mods, fn ($a, $b) => strcasecmp($this->displayName($a), $this->displayName($b)));

        return [
            'object' => 'modmanager_installed',
            'attributes' => [
                'directory' => $directory,
                'exists' => $exists,
                'mods' => $mods,
                'daemon_error' => $error,
            ],
        ];
    }

    /**
     * Compare every tracked mod with the newest compatible upload on its provider.
     */
    public function updates(ReadRequest $request, Server $server): array
    {
        $context = $this->context($request, $server);
        $records = $this->registry->forServer($server);

        $updates = [];
        $errors = [];
        $checked = 0;
        foreach ($records as $filename => $record) {
            if ($checked >= 60) {
                $errors[] = 'Only the first 60 tracked mods were checked.';
                break;
            }

            try {
                $provider = $this->providers->get($record['provider']);
                $versions = $provider->versions($record['project_id'], $context['loader'], $context['game_version']);
            } catch (ProviderException $exception) {
                $errors[] = $record['project_name'] . ': ' . $exception->getMessage();
                continue;
            }
            $checked++;

            $latest = null;
            foreach ($versions as $version) {
                if ($version['file'] !== null) {
                    $latest = $version;
                    break;
                }
            }

            if ($latest !== null && $latest['id'] !== $record['version_id']) {
                $updates[] = ['filename' => $filename, 'current' => $record, 'latest' => $latest];
            }
        }

        return [
            'object' => 'modmanager_updates',
            'attributes' => [
                'updates' => $updates,
                'checked' => $checked,
                'errors' => array_values(array_unique($errors)),
                'context' => $context,
            ],
        ];
    }

    /**
     * Ask Wings to download a mod file straight into the mods directory. The
     * download URL is re-resolved from the provider here so the browser can
     * never point the panel at an arbitrary host.
     */
    public function install(InstallRequest $request, Server $server): JsonResponse
    {
        $context = $this->context($request, $server);
        $provider = $this->providers->get((string) $request->input('provider'));
        $projectId = (string) $request->input('project');
        $versionId = (string) $request->input('version');

        $version = $provider->version($projectId, $versionId);
        if ($version === null) {
            throw new DisplayException('That version could not be found on ' . $provider->label() . '.');
        }
        if ($version['loaders'] !== [] && !in_array($context['loader'], $version['loaders'], true)) {
            throw new DisplayException(sprintf(
                'This file is built for %s, but this server runs %s.',
                implode('/', $version['loaders']),
                $context['loader']
            ));
        }
        if ($version['game_versions'] !== [] && !in_array($context['game_version'], $version['game_versions'], true)) {
            throw new DisplayException(sprintf(
                'This file is not published for Minecraft %s (%s). Pick a file that lists %s, or change the Minecraft version at the top of the Mods tab.',
                $context['game_version'],
                $context['loader'],
                $context['game_version']
            ));
        }

        $file = $version['file'];
        if ($file === null || $file['url'] === '') {
            throw new DisplayException($version['unavailable_reason'] ?? 'This version has no downloadable file.');
        }

        $url = $this->validatedDownloadUrl($file['url']);
        $filename = $this->safeFilename($file['filename'] !== '' ? $file['filename'] : basename((string) parse_url($url, PHP_URL_PATH)));
        $directory = (string) $this->settings->get('mods_directory');

        $this->ensureDirectory($server, $directory);

        try {
            $this->files->setServer($server)->pull($url, $directory, [
                'filename' => $filename,
                'use_header' => false,
                'foreground' => false,
            ]);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException('Wings refused the download: ' . $exception->getMessage());
        }

        $project = null;
        try {
            $project = $provider->project($version['project_id'] !== '' ? $version['project_id'] : $projectId);
        } catch (\Throwable) {
            // Cosmetic data only; the install already started.
        }

        $record = $this->registry->record($server, [
            'provider' => $provider->id(),
            'project_id' => $project['id'] ?? $projectId,
            'project_slug' => $project['slug'] ?? null,
            'project_name' => $project['name'] ?? $projectId,
            'version_id' => $version['id'],
            'version_name' => $version['name'],
            'version_number' => $version['version_number'],
            'filename' => $filename,
            'loader' => $context['loader'],
            'game_version' => $context['game_version'],
            'icon_url' => $project['icon_url'] ?? null,
            'sha1' => $file['sha1'],
            'size' => $file['size'],
        ], $request->user()?->id);

        $warning = null;
        $replace = $request->input('replace');
        if (is_string($replace) && $replace !== '' && $replace !== $filename) {
            try {
                $this->files->setServer($server)->deleteFiles($directory, [$replace]);
                $this->registry->forget($server, $replace);
            } catch (DaemonConnectionException $exception) {
                $warning = 'The new file is downloading, but the old file could not be removed: ' . $exception->getMessage();
            }
        }

        Activity::event('server:modmanager.install')
            ->property([
                'provider' => $provider->id(),
                'project' => $record['project_name'],
                'version' => $record['version_number'],
                'file' => $filename,
            ])
            ->log();

        return new JsonResponse([
            'object' => 'modmanager_install',
            'attributes' => $record + ['status' => 'downloading', 'warning' => $warning],
        ], 202);
    }

    /**
     * Disable a mod by renaming it to .jar.disabled (the loaders ignore it), or
     * enable it again. Nothing is downloaded or deleted.
     */
    public function toggle(ToggleRequest $request, Server $server): array
    {
        $filename = (string) $request->input('filename');
        $directory = (string) $this->settings->get('mods_directory');

        $wasDisabled = (bool) preg_match('/\.disabled$/i', $filename);
        $target = $wasDisabled ? (string) preg_replace('/\.disabled$/i', '', $filename) : $filename . '.disabled';

        try {
            $this->files->setServer($server)->renameFiles($directory, [['from' => $filename, 'to' => $target]]);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException('Wings could not rename the file: ' . $exception->getMessage());
        }

        $this->registry->rename($server, $filename, $target);

        Activity::event($wasDisabled ? 'server:modmanager.enable' : 'server:modmanager.disable')
            ->property('file', $filename)
            ->log();

        return [
            'object' => 'modmanager_mod',
            'attributes' => ['filename' => $target, 'enabled' => $wasDisabled],
        ];
    }

    public function remove(RemoveRequest $request, Server $server): JsonResponse
    {
        $filename = (string) $request->input('filename');
        $directory = (string) $this->settings->get('mods_directory');

        try {
            $this->files->setServer($server)->deleteFiles($directory, [$filename]);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException('Wings could not delete the file: ' . $exception->getMessage());
        }

        $this->registry->forget($server, $filename);

        Activity::event('server:modmanager.remove')->property('file', $filename)->log();

        return new JsonResponse([], 204);
    }

    // ---------------------------------------------------------------------

    private function detection(Server $server, bool $refresh): array
    {
        $key = 'modmanager:detect:' . $server->uuid;
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::DETECTION_TTL, fn () => $this->detector->detect(
            $server,
            (string) $this->settings->get('mods_directory')
        ));
    }

    /**
     * The loader and game version every provider call is scoped to.
     * Precedence: explicit request parameter, then the choice saved for this
     * server, then detection. A game version is mandatory: files are only ever
     * listed or installed for one exact Minecraft version plus one loader.
     *
     * @return array{loader: string, game_version: string}
     */
    private function context(Request $request, Server $server): array
    {
        $detection = $this->detection($server, false);
        $saved = $this->savedContext($server);
        $allowOverride = (bool) $this->settings->get('allow_override');

        $loader = $detection['loader'];
        if ($allowOverride && $saved['loader'] !== null) {
            $loader = $saved['loader'];
        }

        $requested = $request->input('loader');
        if (is_string($requested) && in_array($requested, LoaderDetector::LOADERS, true) && $requested !== $loader) {
            if (!$allowOverride) {
                throw new DisplayException('Changing the detected mod loader has been disabled by the administrator.');
            }
            $loader = $requested;
        }

        if ($loader === null) {
            throw new DisplayException('No mod loader was detected on this server. Mod Manager only works on Forge, NeoForge, Fabric or Quilt servers.');
        }

        $gameVersion = $request->input('game_version');
        if (!is_string($gameVersion) || !preg_match('/^1\.\d{1,2}(\.\d{1,2})?$/', $gameVersion)) {
            $gameVersion = $saved['game_version'] ?? $detection['game_version'];
        }
        if ($gameVersion === null || $gameVersion === '') {
            throw new DisplayException('Pick the Minecraft version this server runs at the top of the Mods tab first.');
        }

        return ['loader' => $loader, 'game_version' => $gameVersion];
    }

    /**
     * @return array{loader: ?string, game_version: ?string}
     */
    private function savedContext(Server $server): array
    {
        $value = $this->blueprint->dbGet(self::CONTEXT_TABLE, $server->uuid);
        if (!is_array($value)) {
            return ['loader' => null, 'game_version' => null];
        }

        $loader = $value['loader'] ?? null;
        $gameVersion = $value['game_version'] ?? null;

        return [
            'loader' => is_string($loader) && in_array($loader, LoaderDetector::LOADERS, true) ? $loader : null,
            'game_version' => is_string($gameVersion) && preg_match('/^1\.\d{1,2}(\.\d{1,2})?$/', $gameVersion) ? $gameVersion : null,
        ];
    }

    /**
     * @return array<string, true> keys of the form "provider:project_id"
     */
    private function installedProjects(Server $server): array
    {
        $keys = [];
        foreach ($this->registry->forServer($server) as $record) {
            $keys[$record['provider'] . ':' . $record['project_id']] = true;
        }

        return $keys;
    }

    private function validatedDownloadUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw new DisplayException('Refusing to download from a non-HTTPS URL.');
        }

        $allowed = in_array($host, self::ALLOWED_HOSTS, true)
            || str_ends_with($host, '.forgecdn.net')
            || str_ends_with($host, '.modrinth.com');
        if (!$allowed) {
            throw new DisplayException('Refusing to download from ' . $host . '. Only the Modrinth and CurseForge CDNs are allowed.');
        }

        return $url;
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $name));

        if ($name === '' || $name === '.' || $name === '..') {
            throw new DisplayException('The provider returned an unusable filename.');
        }
        if (!preg_match('/\.jar$/i', $name)) {
            throw new DisplayException('Only .jar files can be installed as mods (got "' . $name . '").');
        }
        if (strlen($name) > 180) {
            $name = substr($name, 0, 170) . '.jar';
        }

        return $name;
    }

    private function ensureDirectory(Server $server, string $directory): void
    {
        try {
            $this->files->setServer($server)->getDirectory($directory);

            return;
        } catch (DaemonConnectionException $exception) {
            if (!$this->isMissingPath($exception)) {
                throw new DisplayException('Wings could not read the mods directory: ' . $exception->getMessage());
            }
        }

        $parent = dirname($directory);
        try {
            $this->files->setServer($server)->createDirectory(basename($directory), $parent === '.' ? '/' : $parent);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException('Could not create the mods directory: ' . $exception->getMessage());
        }
    }

    /**
     * Wings answers a listing of a non-existent directory with 404 on some
     * versions and with a 500 "openat2 <dir>: file does not exist" on others
     * (observed on wings:latest in Oct 2026). Treat both as "not there yet".
     */
    private function isMissingPath(DaemonConnectionException $exception): bool
    {
        if ($exception->getStatusCode() === 404) {
            return true;
        }

        return (bool) preg_match('/file does not exist|no such file or directory|not found/i', $exception->getMessage());
    }

    private function displayName(array $mod): string
    {
        return (string) ($mod['record']['project_name'] ?? $mod['filename']);
    }
}
