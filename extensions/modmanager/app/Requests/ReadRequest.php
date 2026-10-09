<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Permission;

/**
 * Anyone who may read the server's files may browse mods and see what is
 * installed. Installing, toggling and removing require the matching file
 * permissions, see the other request classes.
 */
class ReadRequest extends ClientApiRequest
{
    public const LOADER_RULE = 'nullable|string|in:forge,neoforge,fabric,quilt';

    public const GAME_VERSION_RULE = 'nullable|string|max:16|regex:/^1\.\d{1,2}(\.\d{1,2})?$/';

    public const ID_RULE = 'required|string|max:64|regex:/^[A-Za-z0-9_\-]+$/';

    public const FILENAME_RULE = 'required|string|max:191|regex:/^[^\/\\\\\x00]+\.jar(\.disabled)?$/i|not_regex:/\.\./';

    public function permission(): string
    {
        return Permission::ACTION_FILE_READ;
    }

    public function rules(): array
    {
        return [
            'loader' => self::LOADER_RULE,
            'game_version' => self::GAME_VERSION_RULE,
            'refresh' => 'nullable|boolean',
        ];
    }
}
