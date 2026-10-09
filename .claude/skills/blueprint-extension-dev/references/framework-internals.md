# Blueprint framework internals (verified against beta-2026-08 source, panel v1.15.1)

Facts below were read from the Blueprint installer/runtime source and the panel source, not from the docs.
They fill gaps the official docs leave open.

## Toolchain the extension code runs inside

| Layer | Version | Notes |
|---|---|---|
| Panel | v1.15.1 | Laravel 12, PHP 8.2/8.3, Guzzle 7 |
| Frontend | React 16.14, TypeScript ~5.1, react-router-dom v5, easy-peasy 4, twin.macro + styled-components, Tailwind 3 | Node >= 22 to build |
| Blueprint | beta-2026-08 | Bash CLI, patches the panel in place |

Tailwind's `content` glob is `./resources/scripts/**/*.{js,ts,tsx}`, which includes
`resources/scripts/blueprint/extensions/<id>/`, so plain `className` utilities in extension
components are compiled. `css={tw`...`}` (twin.macro) also works there.

## Where files land on install

| conf.yml bind | Destination in the panel |
|---|---|
| `dashboard.components` | copied to `resources/scripts/blueprint/extensions/<id>/`, imported as `@blueprint/extensions/<id>/<Component>` |
| `requests.app` | copied to `.blueprint/extensions/<id>/app/` and symlinked to `app/BlueprintFramework/Extensions/<id>` (PSR-4: `Pterodactyl\BlueprintFramework\Extensions\<id>\...`, subfolders map to sub-namespaces) |
| `requests.routers.client` | `routes/blueprint/client/<id>.php`, mounted under `/api/client/extensions/<id>` |
| `requests.routers.application` | `routes/blueprint/application/<id>.php`, mounted under `/api/application/extensions/<id>` |
| `requests.routers.web` | `routes/blueprint/web/<id>.php`, mounted under `/extensions/<id>` |
| `database.migrations` | copied into `database/migrations/` then `php artisan migrate` runs |
| `admin.view` | `resources/views/admin/extensions/<id>/index.blade.php` |
| `admin.controller` | `app/Http/Controllers/Admin/Extensions/<id>/<id>ExtensionController.php` |
| `data.directory` | `.blueprint/extensions/<id>/private/` (`{root/data}`) |
| `data.public` | symlinked to `public/extensions/<id>` (`{webroot/public}`) |

## Client API routes: middleware and server binding

Blueprint's `RouteServiceProvider` mounts the client router file as:

```php
Route::middleware(['blueprint/api', RequireTwoFactorAuthentication::class])->group(function () {
    Route::middleware(['blueprint/client-api', 'throttle:api.client'])
        ->prefix('/api/client/extensions')
        ->scopeBindings()
        ->group(base_path('routes/blueprint/client.php'));
});
```

Each extension's file is wrapped in `Route::prefix('/<id>')`. Authentication (session or client API
key) is handled by the group. Server access is NOT: add the panel's own server middleware yourself
when a route is server-scoped, exactly like `routes/api-client.php` does:

```php
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;

Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [ServerSubject::class, AuthenticateServerAccess::class, ResourceBelongsToServer::class],
], function () {
    Route::get('/thing', [MyController::class, 'index']);
});
```

`{server}` resolves through `Server::resolveRouteBinding` (uuid or short uuid). Subuser permissions
come for free by extending `Pterodactyl\Http\Requests\Api\Client\ClientApiRequest` and declaring
`public function permission(): string { return Permission::ACTION_FILE_READ; }` on the form request;
`ClientApiRequest::authorize()` checks `$user->can($permission, $server)`.

Controllers should extend `Pterodactyl\Http\Controllers\Api\Client\ClientApiController` and call
`parent::__construct()` in their constructor (Blueprint's own `ExtensionRouteController` does this).

Throw `Pterodactyl\Exceptions\DisplayException` for user-facing errors; the panel renders it as a
JSON error the frontend's `httpErrorToHuman()` understands.

## Components.yml routes: the undocumented `Permission` key

The installer reads `Name`, `Path`, `Type`, `Component`, `AdminOnly` and also `Permission` per route.
The value is inserted verbatim into TypeScript, so quote it so it survives as a string literal:

```yaml
Navigation:
  Routes:
    - { Name: 'Mods', Path: '/mods', Type: 'server', Component: 'ModsPage', Permission: "'file.read'", AdminOnly: 'false' }
```

Generated entry: `{ path: '/mods', permission: 'file.read', name: 'Mods', component: X, adminOnly: false, identifier: '<id>' }`.
Blueprint's `ServerRouter` wraps routes with a permission in `<Can action={permission} matchAny>` and `PermissionRoute`.
Empty `Permission` becomes `null` (no gating).

Component imports are generated as `@blueprint/extensions/<id>/<Component>`; relative imports between
your own component files work because the whole components folder is copied.

## Egg filtering of routes

Admins choose which eggs show an extension's server routes under Admin > Extensions > (extension).
The setting is stored as `blueprint::extensionconfig_<id>_eggs` (JSON array of egg ids, `["-1"]` = all)
and read by the frontend from `/api/client/extensions/blueprint/eggs?id=<id>`. Blueprint adds
`server.BlueprintFramework.eggId` to the frontend `Server` object for this. Extensions cannot
pre-select eggs; do runtime detection inside the page if behaviour must depend on the server.

## Settings storage

`$blueprint->dbGet('<table>', '<record>')` / `dbSet()` read and write rows in the panel's `settings`
table with key `<table>::<record>` and a PHP-serialized value. Use the same library on both the admin
side (`BlueprintAdminLibrary`) and client side (`BlueprintClientLibrary`); both extend
`BlueprintBaseLibrary`, so values round-trip.

## Known bug: the `{appcontext}` placeholder mangles backslashes (beta-2026-08)

The installer replaces `{appcontext}` with
`sed -e "s~{appcontext}~Pterodactyl\\BlueprintFramework\\Extensions\\<id>~g"`. The backslashes reach
sed unescaped, so `\B`, `\E` and `\m` are treated as escape sequences and the output becomes
`PterodactylBlueprintFrameworkxtensions<id>`. Every class in `requests.app` and every `use` in the
router then points at a namespace that does not exist, and the panel answers 500
(`BindingResolutionException`, or "Class ... does not exist" from `route:list`).

Workaround: never write `{appcontext}`. Spell the namespace out,
`Pterodactyl\BlueprintFramework\Extensions\<identifier>`, in `namespace` and `use` lines. The
identifier is fixed per extension, so nothing is lost. `{identifier}` and `{viewcontext}` contain no
backslashes and are safe.

## Migrations are not run inside Docker

`install.sh` copies `database.migrations` files into `database/migrations/` but only runs
`php artisan migrate --force` when `DOCKER != "y"` (Docker is detected by `/.dockerenv`, which this
image creates on purpose). In a container the table does not exist until the next boot, when the
panel entrypoint runs `migrate --seed --force`, so anything touching the table 500s until then.

Mitigations used by the modmanager extension: ship a `data.directory` `install.sh` that runs
`php artisan migrate --force --path=database/migrations/<file>` (install scripts do run in Docker),
make the migration idempotent with `Schema::hasTable()`, and create the table lazily from the code
that needs it. Keep the migration idempotent whenever the code can create the table itself, because
a later boot still runs the pending migration file.

## Placeholders are applied to every file, including TSX

Blueprint runs `sed` over the whole extension before install. Tokens such as `{name}`, `{version}`,
`{author}`, `{identifier}`, `{target}`, `{mode}`, `{random}`, `{root}`, `{webroot}`, `{fs}`,
`{engine}`, `{timestamp}` are replaced wherever they appear. A JSX expression like `<p>{name}</p>`
silently becomes the extension's display name. Avoid bare identifiers that collide with placeholder
names in JSX; `{project.name}` is safe, `{name}` is not. Escape with `!{name}` if you need the literal.

## Blueprint reports SUCCESS even when the frontend build fails

`blueprint -i` runs `yarn run build:production` (with `NODE_OPTIONS=--openssl-legacy-provider`
exported by `blueprint.sh`; set it yourself when running the build by hand) but never checks the
exit code. If webpack fails, the old assets are already deleted, `public/assets/manifest.json`
points at a bundle that does not exist, every page is blank, and the console shows
`SyntaxError: Unexpected token '<'` for `bundle.<hash>.js`. After every install check that
`public/assets/bundle.*.js` exists; if not, run the build manually to read the error.

The most likely cause is a placeholder collision: `tsc` passes because the source is fine, but the
installed copy has `{version}` rewritten to `1.0.0`. `extensions/package.sh` in this repository
refuses to package when a component contains such a token.

## Packaging

`<id>.blueprint` is a plain zip of the extension root (conf.yml at the top level). `blueprint -export`
runs an optional `export.sh` then `zip -r`. Building the archive manually with `zip -r <id>.blueprint .`
from inside the extension folder produces an installable package.

## Developer loop

1. Admin > Extensions > Blueprint > set `developer` to true (once).
2. Put the extension files in `<panel>/.blueprint/dev/` (or `blueprint -init` for a template).
3. `blueprint -build` installs them like a package; `blueprint -watch` rebuilds on change.
4. `blueprint -export` packages; `blueprint -wipe` clears the dev folder.

In this repository's Docker image the panel lives in `/app` inside the container and
`/srv/pterodactyl/extensions` on the host is mirrored for `.blueprint` files:

```bash
docker cp ./extensions/<id> Pterodactyl-Panel:/app/.blueprint/dev   # dev build
docker exec -it Pterodactyl-Panel blueprint -build
# or install a package
docker exec -it Pterodactyl-Panel blueprint -i /srv/pterodactyl/extensions/<id>.blueprint
```

Frontend changes trigger a `yarn build` of the whole panel (several minutes).

## Wings file pulls (for extensions that download files into a server)

`DaemonFileRepository::pull($url, $directory, ['filename' => ..., 'use_header' => bool, 'foreground' => bool])`
posts to Wings `/api/servers/{uuid}/files/pull`. Wings refuses private/loopback destinations, allows at
most 3 simultaneous pulls per server, requires a `Content-Length`, and streams straight into the final
path (the file grows while downloading). Background mode returns 202 immediately; foreground blocks
until done, which can exceed the panel's 15 s Guzzle timeout. Poll the directory listing to know when
a background pull finished.

## Useful panel frontend imports (v1.15.1)

- `@/api/http` (axios instance), `httpErrorToHuman`
- `@/state/server` → `ServerContext.useStoreState((s) => s.server.data!.uuid)`
- `@/plugins/useFlash` (`clearFlashes`, `clearAndAddHttpError`, `addFlash`), `@/components/FlashMessageRender`
- `@/components/elements/ServerContentBlock`, `PageContentBlock`, `TitledGreyBox`, `Spinner`, `Input`, `Select`, `Switch`, `Can`
- `@/components/elements/button/index` → `Button`, `Button.Text`, `Button.Danger`
- `@/components/elements/dialog` → `Dialog`, `Dialog.Confirm`
- `@/components/elements/alert` → `Alert` (`type: 'warning' | 'danger'`)
- `@blueprint/ui` → `UiBadge`, `UiAlert`, `UiDivider`
- `@heroicons/react/outline` and `@fortawesome/react-fontawesome` are available
