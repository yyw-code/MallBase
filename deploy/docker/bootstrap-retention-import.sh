#!/bin/sh
set -eu

REQUEST=/bootstrap-input/import.json
VALIDATOR=/bootstrap/validate-bootstrap-adoption.php
RETENTION=/bootstrap-retention
RESULT=/bootstrap-results

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

: "${MALLBASE_BOOTSTRAP_OPERATION_ID:?}"
[ -f "$REQUEST" ] && [ ! -L "$REQUEST" ] || fail BOOTSTRAP_ADOPT_IMPORT_REQUEST_INVALID
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail BOOTSTRAP_ADOPT_IMPORT_VALIDATOR_INVALID
for root in /storage/runtime /storage/uploads /storage/cert /storage/demo /storage/public-storage /storage/env "$RETENTION" "$RESULT/import"; do
    [ -d "$root" ] && [ ! -L "$root" ] || fail BOOTSTRAP_ADOPT_IMPORT_ROOT_INVALID
done

exec php "$VALIDATOR" import "$REQUEST" "$RETENTION" /storage/runtime /storage/uploads \
    /storage/cert /storage/demo /storage/public-storage /storage/env "$RESULT" \
    "$MALLBASE_BOOTSTRAP_OPERATION_ID"
