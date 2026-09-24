#!/bin/sh
#
# ISPConfig Connect for WHMCS - install the servers-page hook file.
#
# WHMCS does not load modules/servers/<module>/hooks.php (live-proven on WHMCS
# 9.0.6), but it does load every includes/hooks/*.php on each page. This copies
# the hook file that ships with the module into that directory.
#
#   ./install-hook.sh /path/to/whmcs              install or update
#   ./install-hook.sh --status    /path/to/whmcs  report what is there
#   ./install-hook.sh --uninstall /path/to/whmcs  remove it again
#
# Idempotent: running it twice changes nothing the second time. Safe: it
# refuses to write over, or delete, any file that does not carry the marker
# below - i.e. any file this installer did not write.
#
# Exit codes: 0 success (including "nothing to do"), 1 refused/failed,
# 2 wrong usage.
#
# License: GPL-3.0-or-later

set -eu

MARKER='@ispconfigconnect-hook-file@'
HOOK_NAME='ispconfigconnect.php'

SELF=$(basename -- "$0")
SOURCE_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
SOURCE="$SOURCE_DIR/$HOOK_NAME"

usage() {
    cat <<USAGE
Usage: $SELF [--status|--uninstall] <whmcs-root>

  <whmcs-root>   the directory holding WHMCS's configuration.php
                 (the hook is installed as <whmcs-root>/includes/hooks/$HOOK_NAME)

  --status, -s   report whether the hook is installed and up to date
  --uninstall,-u remove the hook file this installer wrote
  --help, -h     this message
USAGE
}

fail() {
    printf '%s: %s\n' "$SELF" "$1" >&2
    exit "${2:-1}"
}

# Wrap a value in single quotes so it can be pasted into a shell verbatim,
# escaping any embedded single quote. Used for the printed undo command and for
# the privilege-drop fallback below.
shquote() {
    printf "'"
    printf '%s' "$1" | sed "s/'/'\\\\''/g"
    printf "'"
}

# Re-run this whole installer as another user, so every file operation happens
# with that user's privileges. exec()s on success and never returns; returns
# non-zero only when no privilege-drop tool is available. Runs the script fresh
# through /bin/sh (no reliance on the file's execute bit) with the same target.
reexec_as() {
    _user="$1"
    _uid="$2"
    _gid="$3"
    _script="$SOURCE_DIR/$SELF"

    if command -v runuser >/dev/null 2>&1; then
        exec runuser -u "$_user" -- /bin/sh "$_script" -- "$whmcs_root"
    fi
    if command -v setpriv >/dev/null 2>&1; then
        exec setpriv --reuid="$_uid" --regid="$_uid" --clear-groups -- \
            /bin/sh "$_script" -- "$whmcs_root"
    fi
    if command -v su >/dev/null 2>&1; then
        exec su -s /bin/sh -c "exec /bin/sh $(shquote "$_script") -- $(shquote "$whmcs_root")" "$_user"
    fi

    return 1
}

# Ours iff it carries the marker. This is the whole ownership test: a file
# without it belongs to somebody else and is never touched.
is_ours() {
    grep -qF -- "$MARKER" "$1" 2>/dev/null
}

# WHMCS loads EVERY includes/hooks/*.php. A second copy of our hook under
# another .php name - the `ispconfigconnect-old.php` kept across an upgrade -
# used to be a fatal; the hook file's load-once guard now makes it harmless, but
# whichever copy loads FIRST wins, so a stale one silently shadows the current
# file. Nothing else can detect that, so report it here.
strays() {
    for f in "$hook_dir"/*.php; do
        [ -f "$f" ] || continue
        [ "$f" != "$target" ] || continue
        is_ours "$f" && printf '%s\n' "$f"
    done
    return 0
}

warn_about_strays() {
    found=$(strays)
    [ -n "$found" ] || return 0
    printf '%s: WARNING - another copy of this hook is also in %s:\n' "$SELF" "$hook_dir" >&2
    # Read line by line so a path containing spaces is not split into pieces.
    printf '%s\n' "$found" | while IFS= read -r stray; do
        [ -n "$stray" ] || continue
        printf '  %s\n' "$stray" >&2
    done
    printf 'WHMCS loads every *.php there and the first copy loaded wins, so a stale\n' >&2
    printf 'one silently shadows %s. Delete the file(s) above.\n' "$HOOK_NAME" >&2
    return 1
}

action='install'
whmcs_root=''

while [ $# -gt 0 ]; do
    case "$1" in
        --status|-s) action='status' ;;
        --uninstall|-u) action='uninstall' ;;
        --help|-h) usage; exit 0 ;;
        --) shift; break ;;
        -*) usage >&2; fail "unknown option: $1" 2 ;;
        *)
            [ -z "$whmcs_root" ] || { usage >&2; fail "unexpected argument: $1" 2; }
            whmcs_root="$1"
            ;;
    esac
    shift
done

if [ -z "$whmcs_root" ] && [ $# -gt 0 ]; then
    whmcs_root="$1"
fi

[ -n "$whmcs_root" ] || { usage >&2; fail 'no WHMCS root given' 2; }
[ -d "$whmcs_root" ] || fail "not a directory: $whmcs_root"

# Absolute from here on, so no derived path can start with a dash.
whmcs_root=$(CDPATH='' cd -- "$whmcs_root" && pwd)

# Two independent signals that this really is a WHMCS root, so a typo cannot
# scatter a hook file into an unrelated tree.
[ -f "$whmcs_root/configuration.php" ] || fail "no configuration.php in $whmcs_root - is that a WHMCS root?"
hook_dir="$whmcs_root/includes/hooks"
[ -d "$hook_dir" ] || fail "no includes/hooks directory in $whmcs_root - is that a WHMCS root?"

target="$hook_dir/$HOOK_NAME"

case "$action" in
    status)
        if [ ! -e "$target" ]; then
            printf 'not installed: %s\n' "$target"
            exit 0
        fi
        if ! is_ours "$target"; then
            printf 'FOREIGN FILE (not written by this installer): %s\n' "$target"
            exit 1
        fi
        if [ -f "$SOURCE" ] && cmp -s "$SOURCE" "$target"; then
            printf 'installed and up to date: %s\n' "$target"
        else
            printf 'installed but DIFFERENT from the shipped file: %s\n' "$target"
        fi
        warn_about_strays || exit 1
        exit 0
        ;;

    uninstall)
        if [ ! -e "$target" ]; then
            printf 'nothing to remove: %s\n' "$target"
            exit 0
        fi
        is_ours "$target" || fail "refusing to remove $target - it does not carry $MARKER, so this installer did not write it"
        rm -f -- "$target"
        printf 'removed %s\n' "$target"
        exit 0
        ;;

    install)
        [ -f "$SOURCE" ] || fail "missing source file: $SOURCE"
        is_ours "$SOURCE" || fail "$SOURCE does not carry $MARKER - refusing to install a file the uninstaller could not recognise"

        # SYMLINK-HIJACK DEFENCE. When we are root but includes/hooks belongs to
        # someone else (the usual cPanel / PHP-FPM case, where it is owned by the
        # web user), writing into it as root is dangerous: that user can plant a
        # symlink in their own directory and steer a root-owned write or chown at
        # an arbitrary file. So do the ENTIRE install as the directory's owner
        # instead - every file operation then carries that user's privileges,
        # and a planted symlink can only reach files that user could already
        # touch. Re-running as non-root skips this block, so there is no loop.
        if [ "$(id -u)" = '0' ]; then
            dir_uid=$(ls -ldn -- "$hook_dir" | awk 'NR==1 {print $3; exit}')
            if [ -n "$dir_uid" ] && [ "$dir_uid" != '0' ]; then
                dir_gid=$(ls -ldn -- "$hook_dir" | awk 'NR==1 {print $4; exit}')
                dir_user=$(ls -ld -- "$hook_dir" | awk 'NR==1 {print $3; exit}')
                reexec_as "$dir_user" "$dir_uid" "$dir_gid" \
                    || fail "refusing to install as root into $hook_dir, which is owned by $dir_user: no privilege-drop tool (runuser/setpriv/su) is available - re-run this script as $dir_user"
            fi
        fi

        # A syntax error here would be a fatal on every WHMCS page, so check
        # before the file can ever be loaded. Only when php is on PATH.
        if command -v php >/dev/null 2>&1; then
            php -l "$SOURCE" >/dev/null || fail "$SOURCE does not parse - refusing to install it"
        else
            printf '%s: WARNING - no php on PATH, so the pre-install parse check was SKIPPED.\n' "$SELF" >&2
            printf '  The docs say this check always runs; confirm the file loads cleanly on the WHMCS server.\n' >&2
        fi

        if [ -e "$target" ]; then
            is_ours "$target" || fail "refusing to overwrite $target - it does not carry $MARKER, so this installer did not write it"
            if cmp -s "$SOURCE" "$target"; then
                printf 'already up to date: %s\n' "$target"
                warn_about_strays || fail "a stale marked copy remains in $hook_dir and may shadow $HOOK_NAME - delete it (see the warning above)"
                exit 0
            fi
        fi

        # Write to an UNPREDICTABLE temp name in the hook directory, then rename
        # into place. mktemp creates the file atomically (O_EXCL) under a name an
        # attacker cannot guess, so it cannot be pre-empted by a planted symlink,
        # and the write goes through that fresh regular file rather than a
        # predictable name that might already exist. The name is hidden and does
        # not end in .php, so a half-written file is never loaded by WHMCS.
        temp=$(mktemp "$hook_dir/.$HOOK_NAME.XXXXXX") || fail "could not create a temp file in $hook_dir"
        trap 'rm -f -- "$temp"' EXIT INT TERM

        # Defence in depth: mktemp never yields a symlink, but refuse to write if
        # the temp is anything but the plain file we just created.
        [ ! -L "$temp" ] || fail "refusing to install: temp file $temp is a symlink"
        cat -- "$SOURCE" > "$temp"
        chmod 0644 "$temp"

        # Refuse to rename over a symlink planted at the well-known target name -
        # that is the other half of the hijack. (When we reach here as root the
        # directory is root-owned; a non-root-owned one was handled by dropping
        # to its owner above, so the resulting file is always owned by whoever
        # owns the directory and readable by the web server.)
        [ ! -L "$target" ] || fail "refusing to install: $target is a symlink"
        mv -f -- "$temp" "$target"
        trap - EXIT INT TERM

        printf 'installed %s\n' "$target"
        printf 'Open Configuration > System Settings > Servers to see the ISPConfig block.\n'
        printf 'To undo: %s --uninstall %s\n' "$SELF" "$(shquote "$whmcs_root")"
        warn_about_strays || fail "installed, but a stale marked copy remains in $hook_dir and may shadow $HOOK_NAME - delete it (see the warning above)"
        exit 0
        ;;
esac
