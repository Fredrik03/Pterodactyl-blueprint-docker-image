---
name: blueprint-extension-dev
description: Build, modify, package and install Blueprint Framework extensions for Pterodactyl panel 1.x (conf.yml, Components.yml, React components, client/application/web API routes, admin pages and controllers, migrations, placeholders, blueprint CLI). Use this whenever the user mentions Blueprint, a .blueprint package, extending the Pterodactyl dashboard or admin area, adding a server tab or page to Pterodactyl, or an extension that talks to Wings file APIs, even if they do not say "Blueprint" explicitly. Not for Pterodactyl 2.0's native extension SDK.
---

# Blueprint extension development

Blueprint is the community extension framework for Pterodactyl 1.x. An extension is a folder with a
`conf.yml` at its root that binds files to panel features. The Blueprint CLI copies, symlinks and
patches those files into the panel and rebuilds the frontend.

This skill pairs the official docs with facts verified from the framework source that the docs do
not state (`references/framework-internals.md`). The docs themselves are AGPL-licensed upstream
material, so they are not stored in git: run `references/fetch-docs.sh` once to download them into
`references/docs/` (git-ignored), or read them online at https://blueprint.zip/docs. Read the
internals file before writing any server-scoped API route, any `Components.yml` route, or any JSX,
because each of those has a trap the docs do not mention.

## Workflow

1. **Decide the surface** you need and read only the matching doc (local copy in
   `references/docs/<name>.md` after running `fetch-docs.sh`, or the URL):
   - Metadata and bindings → `confyml` (https://blueprint.zip/docs/configs/confyml)
   - Dashboard React injection points and new pages → `componentsyml` (https://blueprint.zip/docs/configs/componentsyml)
   - Backend API endpoints and controllers → `routing` (https://blueprint.zip/docs/concepts/routing) and the "Client API routes" section of `references/framework-internals.md`
   - Admin page with settings → `admincontroller`, `adminconfiguration`, `adminpage` (https://blueprint.zip/guides/dev/...)
   - Custom tables → `migrations` (https://blueprint.zip/guides/dev/migrations)
   - Reading/writing settings or checking other extensions → `methods` (https://blueprint.zip/docs/lib/methods)
   - Storing files → `filesystem` (https://blueprint.zip/docs/concepts/filesystem)
   - `{identifier}`-style tokens → `placeholders` (https://blueprint.zip/docs/concepts/placeholders)
   - Install/remove/export hooks → `scripts`, `flags` (https://blueprint.zip/docs/concepts/...)
   - CLI and packaging → `commands` (https://blueprint.zip/docs/cli/commands), `packaging` (https://blueprint.zip/guides/dev/packaging)
2. **Lay out the folder** so each `conf.yml` bind points at a real path. Identifier is `a-z` only and
   becomes part of namespaces, routes and table names, so pick it once.
3. **Write the backend first** (routes file, controllers under `requests.app`, requests extending
   `ClientApiRequest` with a `permission()`), then the components, then the admin page.
4. **Check placeholder collisions** in TSX before building: bare `{name}`, `{version}`, `{author}`,
   `{mode}`, `{target}`, `{random}` in JSX are rewritten by the installer.
5. **Build in a panel**, not locally: put the folder in `<panel>/.blueprint/dev` and run
   `blueprint -build` (developer mode must be on in Admin > Extensions > Blueprint). Frontend builds
   take minutes; the output shows webpack errors. `php artisan route:list | grep <id>` confirms routes.
6. **Package** with `blueprint -export` or `zip -r <id>.blueprint .` from the extension root, then
   verify a clean `blueprint -i <id>` on a panel without the dev build.

## Conventions that avoid the common failures

- Namespaces: write `Pterodactyl\BlueprintFramework\Extensions\<identifier>` (plus `\Sub` for
  subfolders) literally in `requests.app` classes and in the router's `use` lines. Do not use the
  `{appcontext}` placeholder: in beta-2026-08 the installer strips its backslashes and every
  request 500s (see the "Known bug" section of `references/framework-internals.md`). The admin controller uses
  `Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}` and the class name
  `{identifier}ExtensionController`.
- Server-scoped client routes need the panel's `ServerSubject`, `AuthenticateServerAccess` and
  `ResourceBelongsToServer` middleware added by hand; Blueprint only provides authentication.
- Give `Components.yml` routes a `Permission` (quoted as `"'file.read'"`) when the page acts on a
  server, so subusers without rights never see the tab.
- Store settings with `$blueprint->dbSet('<identifier>', 'key', value)` on the admin side and read
  them with the client library's `dbGet()`; both hit the same `settings` row.
- Never call third-party APIs from the React side. Proxy them through a client route so API keys
  stay on the server and responses can be cached.
- Use the panel's own elements (`ServerContentBlock`, `TitledGreyBox`, `Button`, `Dialog`, `Input`,
  `Select`, `Spinner`, `Can`) so the page matches the panel and themes.
- Migrations: `YYYY_MM_DD_HHMMSS_name.php`, table names prefixed with the identifier, `down()` drops them.

## Repository specifics

This repository builds a Docker image with the panel at `/app`. Extension sources live in
`extensions/<id>/`. Test inside the running container:

```bash
docker cp extensions/<id>/. Pterodactyl-Panel:/app/.blueprint/dev/
docker exec -it Pterodactyl-Panel blueprint -build
```

See `references/framework-internals.md` for the full list of where files land, how routes are
mounted, egg filtering, Wings pull semantics and frontend import paths.
