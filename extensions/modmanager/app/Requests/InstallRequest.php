<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Permission;

class InstallRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_CREATE;
    }

    public function rules(): array
    {
        return [
            'provider' => 'required|string|in:modrinth,curseforge',
            'project' => ReadRequest::ID_RULE,
            'version' => ReadRequest::ID_RULE,
            'loader' => ReadRequest::LOADER_RULE,
            'game_version' => ReadRequest::GAME_VERSION_RULE,
            // When updating, the file to delete once the new one is downloading.
            'replace' => 'nullable|string|max:191|regex:/^[^\/\\\\\x00]+\.jar(\.disabled)?$/i|not_regex:/\.\./',
        ];
    }
}
