<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services;

use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary;

/**
 * Reads the extension settings the admin page stores through Blueprint's
 * settings helpers (rows "modmanager::<key>" in the panel's settings table).
 */
class Settings
{
    public const TABLE = 'modmanager';

    public const VERSION = '1.0.0';

    private ?array $resolved = null;

    public function __construct(private BlueprintClientLibrary $blueprint)
    {
    }

    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $raw = $this->blueprint->dbGetMany(self::TABLE);

        $directory = '/' . trim((string) ($raw['mods_directory'] ?? '/mods'), "/ \t\n\r");
        if ($directory === '/' || str_contains($directory, '..')) {
            $directory = '/mods';
        }

        $key = trim((string) ($raw['curseforge_api_key'] ?? ''));
        $pageSize = (int) ($raw['page_size'] ?? 20);

        return $this->resolved = [
            'modrinth_enabled' => $this->flag($raw['modrinth_enabled'] ?? '1'),
            'curseforge_enabled' => $this->flag($raw['curseforge_enabled'] ?? '0') && $key !== '',
            'curseforge_api_key' => $key,
            'allow_override' => $this->flag($raw['allow_override'] ?? '0'),
            'mods_directory' => $directory,
            'page_size' => max(5, min(50, $pageSize > 0 ? $pageSize : 20)),
            'contact' => trim((string) ($raw['contact'] ?? '')),
        ];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Settings that are safe to hand to the browser.
     */
    public function public(): array
    {
        $all = $this->all();

        return [
            'allow_override' => $all['allow_override'],
            'mods_directory' => $all['mods_directory'],
            'page_size' => $all['page_size'],
        ];
    }

    /**
     * Modrinth requires a descriptive User-Agent; CurseForge does not care but
     * sending the same one everywhere keeps support requests easy to trace.
     */
    public function userAgent(): string
    {
        $contact = $this->get('contact');
        $suffix = $contact !== '' ? ' (' . $contact . ')' : ' (+https://github.com/Fredrik03/Pterodactyl-blueprint-docker-image)';

        return 'Fredrik03/pterodactyl-blueprint-modmanager/' . self::VERSION . $suffix;
    }

    private function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
