#!/bin/sh
set -eu

REQUEST=/bootstrap-input/request.json
VALIDATOR=/bootstrap/validate-bootstrap-adoption.php
RUNTIME=/storage/runtime
UPLOADS=/storage/uploads
RESULT=/bootstrap-results

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

: "${MALLBASE_BOOTSTRAP_OPERATION_ID:?}"
[ -f "$REQUEST" ] && [ ! -L "$REQUEST" ] || fail BOOTSTRAP_ADOPT_NORMALIZE_REQUEST_INVALID
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail BOOTSTRAP_ADOPT_NORMALIZE_VALIDATOR_INVALID
[ -d "$RUNTIME" ] && [ ! -L "$RUNTIME" ] || fail BOOTSTRAP_ADOPT_RUNTIME_INVALID
[ -d "$UPLOADS" ] && [ ! -L "$UPLOADS" ] || fail BOOTSTRAP_ADOPT_UPLOADS_INVALID
[ -d "$RESULT/normalization" ] && [ ! -L "$RESULT/normalization" ] \
    || fail BOOTSTRAP_ADOPT_RESULT_INVALID

# This bounded no-follow walk is a fail-fast platform check. The PHP helper
# performs the authoritative pre/post lstat, device, link and content checks.
find -P "$RUNTIME" -xdev -print0 >/dev/null || fail BOOTSTRAP_ADOPT_RUNTIME_WALK_FAILED
find -P "$UPLOADS" -xdev -print0 >/dev/null || fail BOOTSTRAP_ADOPT_UPLOADS_WALK_FAILED

exec php "$VALIDATOR" normalize "$REQUEST" "$RUNTIME" "$UPLOADS" "$RESULT" \
    "$MALLBASE_BOOTSTRAP_OPERATION_ID"
