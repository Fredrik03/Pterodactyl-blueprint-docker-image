<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\ProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Settings;

abstract class AbstractProvider implements ModProvider
{
    public function __construct(protected Settings $settings)
    {
    }

    /**
     * Extra request headers (API keys live here, never in the browser).
     */
    abstract protected function headers(): array;

    /**
     * GET JSON with a short cache so paging back and forth, reopening a version
     * dialog, or several users browsing the same server do not hammer the APIs.
     * Returns null on 404 so callers can distinguish "not found" from failures.
     */
    protected function fetch(string $cacheKey, int $ttl, string $url, array $query = []): ?array
    {
        $key = 'modmanager:' . $this->id() . ':' . md5($cacheKey);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->request($url, $query);
        if ($result !== null) {
            Cache::put($key, $result, $ttl);
        }

        return $result;
    }

    protected function request(string $url, array $query = []): ?array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->withUserAgent($this->settings->userAgent())
                ->acceptJson()
                ->connectTimeout(8)
                ->timeout(20)
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new ProviderException(sprintf('%s could not be reached from the panel: %s', $this->label(), $exception->getMessage()));
        }

        $status = $response->status();
        if ($status === 404) {
            return null;
        }
        if ($status === 401 || $status === 403) {
            throw new ProviderException(sprintf('%s rejected the request (HTTP %d). Check the API key in the Mod Manager admin page.', $this->label(), $status));
        }
        if ($status === 429) {
            throw new ProviderException(sprintf('%s is rate limiting this panel. Wait a minute and try again.', $this->label()));
        }
        if (!$response->successful()) {
            throw new ProviderException(sprintf('%s responded with HTTP %d.', $this->label(), $status));
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new ProviderException(sprintf('%s returned an unreadable response.', $this->label()));
        }

        return $json;
    }

    protected function str(mixed $value, ?string $default = null): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @return string[]
     */
    protected function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v) => is_string($v) ? $v : null, $value)));
    }
}
