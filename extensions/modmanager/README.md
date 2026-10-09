# Mod Manager (Blueprint extension)

Adds a **Mods** tab to every server that runs a mod loader. Users can search Modrinth and
CurseForge, install a mod (plus its required dependencies) straight into the `mods` folder,
see what is installed, disable, update or remove mods, all without opening the file manager.

Identifier: `modmanager`. Target: Blueprint `beta-2026-08`, Pterodactyl `v1.15.1`.

## How it decides whether a server is modded

On every page load the panel asks Wings for the server's root directory listing (and
`libraries/net`) and looks for:

| Loader | Evidence |
|---|---|
| Forge | `forge-<mc>-<ver>.jar`, or `libraries/net/minecraftforge/forge/<mc>-<ver>` with `user_jvm_args.txt` |
| NeoForge | `neoforge-*.jar`, or `libraries/net/neoforged/neoforge/<ver>` |
| Fabric | `fabric-server-launch.jar`, `fabric-server-launcher.properties`, `fabric-server-mc.<mc>-loader.*.jar`, `.fabric/`, `libraries/net/fabricmc` |
| Quilt | `quilt-server-launch.jar`, `.quilt/` |

The startup command, egg variables (`SERVER_JARFILE`, `MINECRAFT_VERSION`, ...) and the egg name
are a fallback. Vanilla, Paper, Spigot and proxies are rejected and the tab shows why. An admin can
allow users to override the loader from the admin page; by default they cannot.

## Minecraft version is mandatory

Every search, version list, install and update check is scoped to one loader **and** one exact
Minecraft version. The version comes from a dropdown of real releases for that loader (Modrinth's
version list, with a built-in fallback); detection preselects it when the server reveals it, and
the choice is saved per server (`POST /context`, needs `file.create`) so everyone sees the same
one. There is no "any version" mode: a file that is not published for the selected version and
loader cannot be installed, and the install endpoint re-checks this server-side.

Modrinth and CurseForge tag files by Minecraft version plus loader type, not by loader build
number (for example Forge 47.2.0), so version plus loader is the compatibility key both sites
support.

## Install

```bash
# build the package on your machine
./extensions/package.sh            # -> extensions/modmanager.blueprint

# copy it next to the panel and install
docker cp extensions/modmanager.blueprint Pterodactyl-Panel:/app/
docker exec -it Pterodactyl-Panel blueprint -i modmanager
```

Blueprint prints "Extension uses a custom installation script, proceed with caution" during
install. That script is `data/install.sh`; it only runs the extension's own database migration,
because Blueprint skips migrations inside Docker and would otherwise leave the Mods tab broken
until the next container restart. The extension also creates its table on first use, so a failed
script is not fatal.

Then open **Admin > Extensions > Mod Manager**:

- Modrinth works out of the box.
- CurseForge needs an API key from https://console.curseforge.com/ (free). Paste it and tick
  "Enable CurseForge". Some authors block third-party downloads; those files show a link to
  CurseForge instead of an install button.
- Optionally allow users to override the detected loader, and change the mods directory.

Under **Admin > Extensions** you can also restrict which eggs show the tab at all.

## Permissions

Subuser permissions are reused, nothing new to configure:

| Action | Permission |
|---|---|
| See the tab, browse, list installed | `file.read` |
| Install / update | `file.create` |
| Enable / disable | `file.update` |
| Remove | `file.delete` |

## API (client, session or client API key)

All routes live under `/api/client/extensions/modmanager/servers/{server}`:

| Method | Path | Purpose |
|---|---|---|
| GET | `/status` | detection result, enabled providers, settings |
| GET | `/search?provider=&query=&page=&sort=` | search mods for the detected loader/version |
| GET | `/versions?provider=&project=` | compatible files for a project |
| GET | `/installed` | jars in the mods folder merged with tracked installs |
| GET | `/updates` | tracked mods with a newer compatible file |
| POST | `/context` `{game_version, loader?}` | remember the Minecraft version (and loader, if overrides are allowed) for this server |
| POST | `/install` `{provider, project, version, replace?}` | ask Wings to download the file |
| POST | `/toggle` `{filename}` | rename to/from `.jar.disabled` |
| POST | `/remove` `{filename}` | delete the file |

Optional `loader` and `game_version` query parameters override the saved/detected values where allowed; a Minecraft version is required for search, versions, updates and install.

## Development

```bash
docker cp extensions/modmanager/. Pterodactyl-Panel:/app/.blueprint/dev/
docker exec -it Pterodactyl-Panel blueprint -build
```

Developer mode must be enabled under Admin > Extensions > Blueprint first.
