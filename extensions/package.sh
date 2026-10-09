#!/usr/bin/env bash
# Package a Blueprint extension folder into <identifier>.blueprint (a zip with conf.yml at the root).
# Usage: extensions/package.sh [identifier]   (default: modmanager)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ID="${1:-modmanager}"
SRC="$HERE/$ID"
OUT="$HERE/$ID.blueprint"

if [[ ! -f "$SRC/conf.yml" ]]; then
  echo "No conf.yml in $SRC" >&2
  exit 1
fi

# Blueprint rewrites placeholder tokens such as {version} or {name} in EVERY file, including
# React code, so a JSX expression like {version} silently becomes the extension version and
# breaks the frontend build. Refuse to package when a component contains one.
PLACEHOLDERS='\{(name|version|author|target|mode|random|root|webroot|fs|engine|timestamp|is_target|appcontext)(/[a-z]+)?[!^]?\}'
if hits=$(grep -rnE "$PLACEHOLDERS" "$SRC" --include='*.tsx' --include='*.ts' 2>/dev/null); then
  echo "Refusing to package: placeholder-like tokens found (rename the variable or escape with !{...}):" >&2
  echo "$hits" >&2
  exit 1
fi

rm -f "$OUT"
(
  cd "$SRC"
  zip -qr "$OUT" . -x '*.DS_Store' -x '__MACOSX/*' -x 'README.md'
)
echo "Wrote $OUT ($(du -h "$OUT" | cut -f1))"
