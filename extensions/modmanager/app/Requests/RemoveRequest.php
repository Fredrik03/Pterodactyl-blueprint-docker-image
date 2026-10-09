<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Permission;

class RemoveRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_DELETE;
    }

    public function rules(): array
    {
        return ['filename' => ReadRequest::FILENAME_RULE];
    }
}
