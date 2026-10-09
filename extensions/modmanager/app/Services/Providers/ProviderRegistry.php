<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Providers;

use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\ProviderException;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Services\Settings;

class ProviderRegistry
{
    public function __construct(
        private Settings $settings,
        private ModrinthProvider $modrinth,
        private CurseForgeProvider $curseforge,
    ) {
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    public function enabled(): array
    {
        $list = [];
        if ($this->settings->get('modrinth_enabled')) {
            $list[] = ['id' => $this->modrinth->id(), 'label' => $this->modrinth->label()];
        }
        if ($this->settings->get('curseforge_enabled')) {
            $list[] = ['id' => $this->curseforge->id(), 'label' => $this->curseforge->label()];
        }

        return $list;
    }

    public function get(string $id): ModProvider
    {
        $provider = match ($id) {
            'modrinth' => $this->modrinth,
            'curseforge' => $this->curseforge,
            default => throw new ProviderException('Unknown mod provider "' . $id . '".'),
        };

        foreach ($this->enabled() as $enabled) {
            if ($enabled['id'] === $provider->id()) {
                return $provider;
            }
        }

        throw new ProviderException($provider->label() . ' is disabled by the administrator.');
    }
}
