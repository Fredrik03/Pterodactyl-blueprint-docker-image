<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Services;

use Pterodactyl\Exceptions\DisplayException;

/**
 * Raised when Modrinth or CurseForge cannot be reached or rejects a request.
 * DisplayException makes the panel return the message to the browser as a
 * normal API error instead of a generic 500.
 */
class ProviderException extends DisplayException
{
}
