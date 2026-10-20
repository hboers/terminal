#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

babeljs "$ROOT/vendor/xterm/xterm.js" \
  --config-file "$ROOT/vendor/chrome77.babel.json" --no-babelrc \
  --out-file "$TMP_DIR/xterm.chrome77.js"
babeljs "$ROOT/vendor/addon-fit/addon-fit.js" \
  --config-file "$ROOT/vendor/chrome77.babel.json" --no-babelrc \
  --out-file "$TMP_DIR/addon-fit.chrome77.js"

mv "$TMP_DIR/xterm.chrome77.js" "$ROOT/vendor/xterm/xterm.chrome77.js"
mv "$TMP_DIR/addon-fit.chrome77.js" "$ROOT/vendor/addon-fit/addon-fit.chrome77.js"
