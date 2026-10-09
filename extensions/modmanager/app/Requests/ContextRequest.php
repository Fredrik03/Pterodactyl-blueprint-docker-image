<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Permission;

/**
 * Remembering the Minecraft version (and loader, when overrides are allowed)
 * for a server is a configuration change, so it needs the same right as installing.
 */
class ContextRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_CREATE;
    }

    public function rules(): array
    {
        return [
            'game_version' => ReadRequest::GAME_VERSION_RULE,
            'loader' => ReadRequest::LOADER_RULE,
        ];
    }
}
