<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests;

class VersionsRequest extends ReadRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'provider' => 'required|string|in:modrinth,curseforge',
            'project' => self::ID_RULE,
        ];
    }
}
