#!/bin/sh
set -eu

RUNTIME_ROOT=/app/runtime
TARGET_UID=${MALLBASE_TARGET_UID-10000}
TARGET_GID=${MALLBASE_TARGET_GID-}

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

case "$TARGET_UID:$TARGET_GID" in
    *[!0-9:]*|:*|*:|*:*:*) fail RUNTIME_INIT_IDENTITY_INVALID ;;
esac
[ "$(id -u)" = "$TARGET_UID" ] || fail RUNTIME_INIT_CALLER_INVALID
[ -d "$RUNTIME_ROOT" ] && [ ! -L "$RUNTIME_ROOT" ] || fail RUNTIME_INIT_ROOT_INVALID
[ "$(stat -c %u "$RUNTIME_ROOT")" = "$TARGET_UID" ] || fail RUNTIME_INIT_ROOT_OWNER_INVALID

chgrp "$TARGET_GID" "$RUNTIME_ROOT" || fail RUNTIME_INIT_ROOT_GROUP_FAILED
chmod 2770 "$RUNTIME_ROOT" || fail RUNTIME_INIT_ROOT_MODE_FAILED

for persistent in install storage backup; do
    path=$RUNTIME_ROOT/$persistent
    marker=$path/.mallbase-layout-marker.json
    [ -d "$path" ] && [ ! -L "$path" ] || fail RUNTIME_INIT_PERSISTENT_ROOT_INVALID
    [ -f "$marker" ] && [ ! -L "$marker" ] && [ "$(stat -c %h "$marker")" = 1 ] \
        || fail RUNTIME_INIT_PERSISTENT_MARKER_INVALID
    [ -r "$marker" ] && [ -w "$path" ] || fail RUNTIME_INIT_PERSISTENT_ACCESS_INVALID
done

find -P "$RUNTIME_ROOT" -xdev -mindepth 1 -maxdepth 1 -print | while IFS= read -r path; do
    name=${path##*/}
    case "$name" in
        install|storage|backup|log|logs|cache|temp|tmp|swoole|phpunit-cache|pid) ;;
        *) fail RUNTIME_INIT_TOP_LEVEL_UNCLASSIFIED ;;
    esac
done

for ephemeral in log cache temp swoole phpunit-cache; do
    path=$RUNTIME_ROOT/$ephemeral
    if [ -e "$path" ]; then
        [ -d "$path" ] && [ ! -L "$path" ] || fail RUNTIME_INIT_EPHEMERAL_ROOT_INVALID
        [ "$(stat -c %u "$path")" = "$TARGET_UID" ] || fail RUNTIME_INIT_EPHEMERAL_OWNER_INVALID
    else
        mkdir "$path" || fail RUNTIME_INIT_EPHEMERAL_CREATE_FAILED
    fi
    chgrp "$TARGET_GID" "$path" || fail RUNTIME_INIT_EPHEMERAL_GROUP_FAILED
    chmod 2770 "$path" || fail RUNTIME_INIT_EPHEMERAL_MODE_FAILED
done

printf '%s\n' RUNTIME_INIT_OK
