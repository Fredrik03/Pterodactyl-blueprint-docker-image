<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Servers\EnvironmentService;

/**
 * Works out whether a server runs a mod loader, which one, and for which
 * Minecraft version. The file system is the primary source of truth (what is
 * actually on disk is what will run); the startup command, egg variables and
 * egg name are a fallback when Wings is unreachable or the server was never
 * installed.
 */
class LoaderDetector
{
    public const LOADERS = ['forge', 'neoforge', 'fabric', 'quilt'];

    private const VERSION_PATTERN = '/^1\.\d{1,2}(?:\.\d{1,2})?$/';

    private const VERSION_VARIABLES = [
        'MINECRAFT_VERSION', 'MC_VERSION', 'MINECRAFT_VER', 'GAME_VERSION', 'VANILLA_VERSION', 'VERSION',
    ];

    public function __construct(
        private DaemonFileRepository $files,
        private EnvironmentService $environment,
    ) {
    }

    /**
     * @return array{
     *     loader: ?string, game_version: ?string, source: ?string, evidence: string[],
     *     daemon_reachable: bool, mods_directory_exists: bool, checked_at: string
     * }
     */
    public function detect(Server $server, string $modsDirectory): array
    {
        $evidence = [];
        $fileLoader = null;
        $fileVersion = null;
        $daemonReachable = true;
        $modsDirectoryExists = false;

        try {
            $root = $this->files->setServer($server)->getDirectory('/');
            $names = $this->names($root);
            $directories = $this->names(array_filter($root, fn ($item) => !($item['file'] ?? true)));
            $modsDirectoryExists = in_array(trim($modsDirectory, '/'), $directories, true);

            [$fileLoader, $fileVersion] = $this->inspectRoot($names, $directories, $evidence);

            if (in_array('libraries', $directories, true)) {
                [$libLoader, $libVersion] = $this->inspectLibraries($server, $evidence);
                $fileLoader = $fileLoader ?? $libLoader;
                $fileVersion = $fileVersion ?? $libVersion;
            }
        } catch (DaemonConnectionException $exception) {
            $daemonReachable = false;
            $evidence[] = 'Wings could not be reached (' . $exception->getMessage() . '); falling back to the startup configuration.';
        }

        [$startupLoader, $startupVersion] = $this->inspectStartup($server, $evidence);

        $loader = $fileLoader ?? $startupLoader;
        $source = $fileLoader !== null ? 'files' : ($startupLoader !== null ? 'startup' : null);
        $gameVersion = $fileVersion ?? $startupVersion;

        if ($loader === null) {
            $evidence[] = 'No Forge, NeoForge, Fabric or Quilt launcher, library folder or startup reference was found.';
        }

        return [
            'loader' => $loader,
            'game_version' => $gameVersion,
            'source' => $source,
            'evidence' => $evidence,
            'daemon_reachable' => $daemonReachable,
            'mods_directory_exists' => $modsDirectoryExists,
            'checked_at' => CarbonImmutable::now()->toAtomString(),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function inspectRoot(array $names, array $directories, array &$evidence): array
    {
        $loader = null;
        $version = null;

        foreach ($names as $name) {
            $lower = strtolower($name);

            if (preg_match('/^neoforge-([\d.]+)(?:-.*)?\.jar$/i', $name, $m)) {
                $loader = $loader ?? 'neoforge';
                $version = $version ?? $this->neoForgeToMinecraft($m[1]);
                $evidence[] = "Found NeoForge launcher jar {$name}.";
                continue;
            }
            if (preg_match('/^forge-(1\.\d+(?:\.\d+)?)-[\d.]+(?:-.*)?\.jar$/i', $name, $m)) {
                $loader = $loader ?? 'forge';
                $version = $version ?? $m[1];
                $evidence[] = "Found Forge launcher jar {$name}.";
                continue;
            }
            if (preg_match('/^fabric-server-mc\.(1\.\d+(?:\.\d+)?)-loader\./i', $name, $m)) {
                $loader = $loader ?? 'fabric';
                $version = $version ?? $m[1];
                $evidence[] = "Found Fabric launcher jar {$name}.";
                continue;
            }
            if ($lower === 'fabric-server-launch.jar' || $lower === 'fabric-server-launcher.properties') {
                $loader = $loader ?? 'fabric';
                $evidence[] = "Found {$name}.";
                continue;
            }
            if ($lower === 'quilt-server-launch.jar' || str_starts_with($lower, 'quilt-server-launcher')) {
                $loader = $loader ?? 'quilt';
                $evidence[] = "Found {$name}.";
                continue;
            }
        }

        if (in_array('.fabric', $directories, true)) {
            $loader = $loader ?? 'fabric';
            $evidence[] = 'Found the .fabric cache directory.';
        }
        if (in_array('.quilt', $directories, true)) {
            $loader = $loader ?? 'quilt';
            $evidence[] = 'Found the .quilt cache directory.';
        }
        if ($loader === null && in_array('user_jvm_args.txt', $names, true) && in_array('libraries', $directories, true)) {
            $evidence[] = 'Found user_jvm_args.txt and a libraries folder (Forge/NeoForge 1.17+ layout); checking libraries.';
        }

        return [$loader, $version];
    }

    /**
     * Forge and NeoForge 1.17+ do not ship a launcher jar; the loader lives in
     * libraries/net/minecraftforge/forge/<mc>-<forge> or libraries/net/neoforged/neoforge/<neo>.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function inspectLibraries(Server $server, array &$evidence): array
    {
        try {
            $net = $this->names($this->files->setServer($server)->getDirectory('/libraries/net'));
        } catch (DaemonConnectionException) {
            return [null, null];
        }

        if (in_array('neoforged', $net, true)) {
            $version = null;
            try {
                $dirs = $this->names($this->files->setServer($server)->getDirectory('/libraries/net/neoforged/neoforge'));
                rsort($dirs, SORT_NATURAL);
                foreach ($dirs as $dir) {
                    $version = $this->neoForgeToMinecraft($dir);
                    if ($version !== null) {
                        $evidence[] = "Found NeoForge {$dir} under libraries/net/neoforged/neoforge.";
                        break;
                    }
                }
            } catch (DaemonConnectionException) {
                $evidence[] = 'Found libraries/net/neoforged.';
            }

            return ['neoforge', $version];
        }

        if (in_array('minecraftforge', $net, true)) {
            $version = null;
            try {
                $dirs = $this->names($this->files->setServer($server)->getDirectory('/libraries/net/minecraftforge/forge'));
                rsort($dirs, SORT_NATURAL);
                foreach ($dirs as $dir) {
                    if (preg_match('/^(1\.\d+(?:\.\d+)?)-/', $dir, $m)) {
                        $version = $m[1];
                        $evidence[] = "Found Forge {$dir} under libraries/net/minecraftforge/forge.";
                        break;
                    }
                }
            } catch (DaemonConnectionException) {
                $evidence[] = 'Found libraries/net/minecraftforge.';
            }

            return ['forge', $version];
        }

        if (in_array('fabricmc', $net, true)) {
            $evidence[] = 'Found libraries/net/fabricmc.';

            return ['fabric', null];
        }

        return [null, null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function inspectStartup(Server $server, array &$evidence): array
    {
        $haystack = strtolower((string) $server->startup);

        $env = [];
        try {
            $env = $this->environment->handle($server);
        } catch (\Throwable) {
            // Variables are optional evidence; never fail detection because of them.
        }

        foreach ($env as $key => $value) {
            if (is_scalar($value)) {
                $haystack .= ' ' . strtolower((string) $value);
            }
        }

        try {
            $eggName = strtolower((string) ($server->egg?->name ?? ''));
        } catch (\Throwable) {
            $eggName = '';
        }

        $loader = null;
        foreach (['neoforge', 'forge', 'fabric', 'quilt'] as $candidate) {
            if (str_contains($haystack, $candidate)) {
                $loader = $candidate;
                $evidence[] = "The startup command or egg variables mention {$candidate}.";
                break;
            }
        }
        if ($loader === null) {
            foreach (['neoforge', 'forge', 'fabric', 'quilt'] as $candidate) {
                if (str_contains($eggName, $candidate)) {
                    $loader = $candidate;
                    $evidence[] = "The egg is named \"{$server->egg?->name}\".";
                    break;
                }
            }
        }

        $version = null;
        foreach (self::VERSION_VARIABLES as $variable) {
            $value = trim((string) ($env[$variable] ?? ''));
            if ($value !== '' && preg_match(self::VERSION_PATTERN, $value)) {
                $version = $value;
                $evidence[] = "{$variable} is set to {$value}.";
                break;
            }
        }

        return [$loader, $version];
    }

    /**
     * NeoForge 20.2+ versions encode the Minecraft version as <minor>.<patch>.<build>
     * (20.4.80 is Minecraft 1.20.4, 21.1.0 is 1.21.1). Older builds for 1.20.1 kept
     * the Forge-style 1.20.1-47.x layout.
     */
    private function neoForgeToMinecraft(string $version): ?string
    {
        if (preg_match('/^(1\.\d+(?:\.\d+)?)/', $version, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d+)\.(\d+)\./', $version, $m)) {
            return '1.' . $m[1] . ((int) $m[2] > 0 ? '.' . $m[2] : '');
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function names(array $listing): array
    {
        $names = [];
        foreach ($listing as $item) {
            if (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                $names[] = $item['name'];
            }
        }

        return $names;
    }
}
