<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Providers\ModrinthProvider;

/**
 * The list of Minecraft release versions users can pick from, per loader.
 * Comes from Modrinth's public tag endpoint (no key needed) with a built-in
 * fallback so the page still works when Modrinth is down.
 */
class GameVersions
{
    private const CACHE_KEY = 'modmanager:game_versions';

    /** First Minecraft version each loader exists for. */
    private const MINIMUM = ['forge' => '1.1', 'neoforge' => '1.20.1', 'fabric' => '1.14', 'quilt' => '1.18'];

    private const FALLBACK = [
        '1.21.8', '1.21.7', '1.21.6', '1.21.5', '1.21.4', '1.21.3', '1.21.2', '1.21.1', '1.21',
        '1.20.6', '1.20.5', '1.20.4', '1.20.3', '1.20.2', '1.20.1', '1.20',
        '1.19.4', '1.19.3', '1.19.2', '1.19.1', '1.19', '1.18.2', '1.18.1', '1.18', '1.17.1', '1.17',
        '1.16.5', '1.16.4', '1.16.3', '1.16.2', '1.16.1', '1.16', '1.15.2', '1.15.1', '1.15',
        '1.14.4', '1.14.3', '1.14.2', '1.14.1', '1.14', '1.13.2', '1.12.2', '1.12.1', '1.12',
        '1.11.2', '1.10.2', '1.9.4', '1.8.9', '1.7.10',
    ];

    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return array<string, string[]> loader => release versions, newest first
     */
    public function byLoader(): array
    {
        $all = $this->releases();

        $result = [];
        foreach (LoaderDetector::LOADERS as $loader) {
            $result[$loader] = array_values(array_filter(
                $all,
                fn (string $version) => version_compare($version, self::MINIMUM[$loader], '>=')
            ));
        }

        return $result;
    }

    /**
     * @return string[] newest first
     */
    public function releases(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::withUserAgent($this->settings->userAgent())
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get(ModrinthProvider::BASE . '/tag/game_version');

            if ($response->successful()) {
                $list = [];
                foreach ($response->json() ?? [] as $entry) {
                    if (!is_array($entry) || ($entry['version_type'] ?? '') !== 'release') {
                        continue;
                    }
                    $version = $entry['version'] ?? null;
                    if (is_string($version) && preg_match('/^1\.\d{1,2}(\.\d{1,2})?$/', $version)) {
                        $list[] = $version;
                    }
                }
                if (count($list) >= 10) {
                    Cache::put(self::CACHE_KEY, $list, 6 * 3600);

                    return $list;
                }
            }
        } catch (ConnectionException) {
            // fall through to the static list
        }

        return self::FALLBACK;
    }
}
