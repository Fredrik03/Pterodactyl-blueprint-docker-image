#!/usr/bin/env bash
# Download the Blueprint Framework docs (AGPL-3.0, https://github.com/BlueprintFramework/web) into
# references/docs/ for offline use. The copies are git-ignored; read them, do not redistribute them.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="https://raw.githubusercontent.com/BlueprintFramework/web/main/apps/frontend/content"
mkdir -p "$HERE/docs"
for f in docs/configs/confyml docs/configs/componentsyml docs/configs/consoleyml docs/concepts/routing docs/concepts/placeholders docs/concepts/filesystem docs/concepts/scripts docs/concepts/flags docs/lib/methods docs/cli/commands guides/dev/quickstart guides/dev/admincontroller guides/dev/adminconfiguration guides/dev/adminpage guides/dev/packaging guides/dev/docker guides/dev/migrations guides/dev/dashboardwrapper; do
  curl -sfL "$BASE/$f.md" -o "$HERE/docs/$(basename "$f").md" && echo "ok $f" || echo "missing $f"
done
