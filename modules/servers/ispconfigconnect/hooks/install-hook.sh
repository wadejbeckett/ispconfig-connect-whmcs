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
# Run it as root or as the owner of includes/hooks, from wherever the module
# was unpacked. As root it still reads the shipped file itself but makes every
# change to includes/hooks as that directory's owner (see WHO WRITES below).
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
# the su fallback in as_writer() below.
shquote() {
    printf "'"
    printf '%s' "$1" | sed "s/'/'\\\\''/g"
    printf "'"
}

# THE WRITE - the only step of an install that changes includes/hooks - as a
# self-contained script, so that it can run as a different user from the rest
# of the installer (see WHO WRITES below). Invoked as
#
#   /bin/sh -c "$WRITE_SCRIPT" <installer name> <hook dir> <file name> < content
#
# The content arrives on STDIN, opened by the caller: the writer never has to
# be able to read the installer's own tree, which may be a private checkout
# (/root/src/...) that the owner of includes/hooks cannot even enter.
#
# - mktemp creates the temp file atomically (O_EXCL) under a name an attacker
#   cannot guess, so it cannot be pre-empted by a planted symlink, and the
#   write goes through that fresh regular file rather than a predictable name
#   that might already exist. Defence in depth: a symlink there is refused
#   anyway, although mktemp never yields one.
# - The temp name is hidden and does not end in .php, so a half-written file
#   is never loaded by WHMCS.
# - Temp and target are both "$1/...", i.e. in the same directory, so the
#   final mv is a rename() and replaces the target atomically.
# - A symlink planted at the well-known target name is refused, not renamed
#   over - that is the other half of the hijack.
# - 0644 explicitly, whatever the writer's umask, so the web server can read it.
# - Any failure or signal removes the temp again.
WRITE_SCRIPT=$(cat <<'WRITE'
umask 022
dir=$1
target=$1/$2
temp=$(mktemp "$dir/.$2.XXXXXX") || {
    printf '%s: could not create a temp file in %s\n' "$0" "$dir" >&2
    exit 1
}
trap 'rm -f -- "$temp"' EXIT
trap 'exit 1' HUP INT TERM
if [ -L "$temp" ]; then
    printf '%s: refusing to install: temp file %s is a symlink\n' "$0" "$temp" >&2
    exit 1
fi
cat > "$temp" || exit 1
chmod 0644 "$temp" || exit 1
if [ -L "$target" ]; then
    printf '%s: refusing to install: %s is a symlink\n' "$0" "$target" >&2
    exit 1
fi
mv -f -- "$temp" "$target" || exit 1
trap - EXIT
WRITE
)

# Runs "$@" as the writer chosen under WHO WRITES: directly when no drop is
# needed, otherwise as the owner of includes/hooks through the first
# privilege-drop tool available. Stdin passes through untouched, which is how
# the install hands over the file content.
#
# runuser and su need a user NAME, so an owner uid without a passwd entry (ls
# then prints the bare number) can only be reached through setpriv. setpriv is
# given the DIRECTORY's group - the one the file should carry - and clears the
# supplementary groups, so the dropped process keeps none of root's.
#
# The drop runs from /, so the new process never starts in a working directory
# it cannot enter (root's own, typically inside that private checkout); every
# path it is given is absolute.
as_writer() {
    if [ -z "$writer_uid" ]; then
        "$@"
        return
    fi
    if [ "$writer_user" != "$writer_uid" ] && command -v runuser >/dev/null 2>&1; then
        (cd / && exec runuser -u "$writer_user" -- "$@")
        return
    fi
    if command -v setpriv >/dev/null 2>&1; then
        (cd / && exec setpriv --reuid="$writer_uid" --regid="$writer_gid" --clear-groups -- "$@")
        return
    fi
    if [ "$writer_user" != "$writer_uid" ] && command -v su >/dev/null 2>&1; then
        _cmd='exec'
        for _arg in "$@"; do
            _cmd="$_cmd $(shquote "$_arg")"
        done
        (cd / && exec su -s /bin/sh -c "$_cmd" "$writer_user")
        return
    fi
    if [ "$writer_user" = "$writer_uid" ]; then
        fail "refusing to change $hook_dir as root - it is owned by uid $writer_uid, which has no account name, so runuser and su cannot switch to it and setpriv is not installed; install setpriv (util-linux), chown the directory to a named user, or run this script as that uid (e.g. sudo -u '#$writer_uid')"
    fi
    fail "refusing to change $hook_dir as root - it is owned by $writer_user and no privilege-drop tool (runuser/setpriv/su) is available; re-run this script as $writer_user"
}

# Ours iff it carries the marker. This is the whole ownership test: a file
# without it belongs to somebody else and is never touched. Only a regular
# file (or a symlink to one) can qualify: grep on a FIFO planted under that
# name would hang the installer, and a directory is never ours.
is_ours() {
    [ -f "$1" ] && grep -qF -- "$MARKER" "$1" 2>/dev/null
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
# Physical path (-P): every path is later handed to the dropped writer, and
# a logical path through a root-only symlink (/root/whmcs -> /var/www/whmcs)
# is one the owner of includes/hooks cannot traverse.
whmcs_root=$(CDPATH='' cd -P -- "$whmcs_root" && pwd -P)

# Two independent signals that this really is a WHMCS root, so a typo cannot
# scatter a hook file into an unrelated tree.
[ -f "$whmcs_root/configuration.php" ] || fail "no configuration.php in $whmcs_root - is that a WHMCS root?"
hook_dir="$whmcs_root/includes/hooks"
[ -d "$hook_dir" ] || fail "no includes/hooks directory in $whmcs_root - is that a WHMCS root?"

target="$hook_dir/$HOOK_NAME"

# WHO WRITES. When we are root but includes/hooks belongs to someone else (the
# usual cPanel / PHP-FPM case, where it is owned by the web user), changing it
# as root is dangerous: that user can plant a symlink in their own directory
# and steer a root-owned write, rename or unlink at an arbitrary file. So every
# change to that directory - the install's write and rename, the uninstall's
# unlink - runs as the directory's OWNER instead (as_writer), whose privileges
# a planted symlink cannot exceed; the installed file is then owned by that
# user, exactly as if they had installed it themselves.
#
# Everything else stays with root - above all READING the shipped file, which
# is handed to the writer on stdin. The installer is normally run from the
# checkout or zip the module arrived in, and that may sit where the web user
# cannot read (/root/src/...). Re-running the whole installer as the owner, the
# previous design, died there with a bare "Permission denied" and left the old
# hook installed.
#
# "$hook_dir/." rather than "$hook_dir": if includes/hooks is itself a symlink,
# the owner that matters is the directory's, not the link's.
writer_user=''
writer_uid=''
writer_gid=''
if [ "$action" != 'status' ] && [ "$(id -u)" = '0' ]; then
    dir_uid=$(ls -ldn -- "$hook_dir/." | awk 'NR==1 {print $3; exit}')
    [ -n "$dir_uid" ] || fail "cannot tell who owns $hook_dir"
    if [ "$dir_uid" != '0' ]; then
        writer_uid=$dir_uid
        writer_gid=$(ls -ldn -- "$hook_dir/." | awk 'NR==1 {print $4; exit}')
        writer_user=$(ls -ld -- "$hook_dir/." | awk 'NR==1 {print $3; exit}')
    fi
fi

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
        # As the directory's owner when root is working in someone else's
        # directory - see WHO WRITES.
        as_writer rm -f -- "$target" || fail "could not remove $target${writer_user:+ as $writer_user}"
        printf 'removed %s\n' "$target"
        exit 0
        ;;

    install)
        [ -f "$SOURCE" ] || fail "missing source file: $SOURCE"
        is_ours "$SOURCE" || fail "$SOURCE does not carry $MARKER - refusing to install a file the uninstaller could not recognise"

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

        # Root opens the source (the redirect below); the writer - the
        # directory's owner when root is installing into someone else's
        # directory, see WHO WRITES - only ever sees the bytes on stdin. The
        # temp-file, symlink and rename guarantees live in WRITE_SCRIPT.
        as_writer /bin/sh -c "$WRITE_SCRIPT" "$SELF" "$hook_dir" "$HOOK_NAME" < "$SOURCE" \
            || fail "could not install $target${writer_user:+ as $writer_user}"

        printf 'installed %s\n' "$target"
        printf 'Open Configuration > System Settings > Servers to see the ISPConfig block.\n'
        printf 'To undo: %s --uninstall %s\n' "$SELF" "$(shquote "$whmcs_root")"
        warn_about_strays || fail "installed, but a stale marked copy remains in $hook_dir and may shadow $HOOK_NAME - delete it (see the warning above)"
        exit 0
        ;;
esac
