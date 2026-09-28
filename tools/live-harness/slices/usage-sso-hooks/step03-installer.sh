#!/bin/sh
# Step 3: hook installer, as root, against fake WHMCS trees whose includes/hooks
# is owned by a non-root user (www-data, nobody, and a nameless uid), run from a
# private (root-only, 0700) checkout and via a symlinked path through a root-only
# directory (the plan's /root/whmcs case, reproduced inside this slice's dir).
set -u
LT=/opt/livetest/usage-sso-hooks
SB=$LT/sandbox
REPO_MOD=/home/user/ispconfig-connect-whmcs/modules/servers/ispconfigconnect
PASS=0; FAIL=0

rm -rf "$SB"
mkdir -p "$SB"

mk_whmcs() { # <dir> <owner-spec>
    mkdir -p "$1/includes/hooks"
    printf '<?php\n// fake WHMCS configuration.php for the installer test\n' > "$1/configuration.php"
    chown "$2" "$1/includes/hooks"
    chmod 0755 "$1/includes/hooks"
}

# The private checkout: a copy of the module in a 0700 root-only directory.
mkdir -p "$SB/private/src"
cp -a "$REPO_MOD" "$SB/private/src/ispconfigconnect"
chmod 0700 "$SB/private"
INST="$SB/private/src/ispconfigconnect/hooks/install-hook.sh"
SRC="$SB/private/src/ispconfigconnect/hooks/ispconfigconnect.php"

mk_whmcs "$SB/whmcs-a" www-data:www-data
mk_whmcs "$SB/whmcs-b" nobody:nogroup
mk_whmcs "$SB/whmcs-c" 4242:4242
# Root-only directory holding a symlink to whmcs-b: the /root/whmcs case.
mkdir -p "$SB/rootonly"
ln -s "$SB/whmcs-b" "$SB/rootonly/whmcs"
chmod 0700 "$SB/rootonly"

run() { # <label> <cmd...> ; sets OUT ERR RC
    label=$1; shift
    OUT=$("$@" 2>"$SB/.stderr"); RC=$?
    ERR=$(cat "$SB/.stderr")
    printf '\n$ %s\n' "$*"
    printf '[%s] exit=%s\n--- stdout\n%s\n--- stderr\n%s\n' "$label" "$RC" "$OUT" "$ERR"
}

check() { # <desc> <condition-exit-status>
    if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); printf 'PASS: %s\n' "$1"; else FAIL=$((FAIL+1)); printf 'FAIL: %s\n' "$1"; fi
}

firstline() { printf '%s\n' "$1" | head -n1; }

T=$SB/whmcs-a/includes/hooks/ispconfigconnect.php

echo "===== A. www-data owned includes/hooks, installer run from a 0700 private checkout ====="
run a1 sh "$INST" "$SB/whmcs-a"
[ "$(firstline "$OUT")" = "installed $T" ]; check "A1 first run prints 'installed $T'" $?
[ $RC -eq 0 ]; check "A1 exit 0" $?
printf '%s' "$ERR$OUT" | grep -q 'WARNING - another copy'; [ $? -ne 0 ]; check "A1 no 'WARNING - another copy'" $?
st=$(stat -c '%U:%G %a' "$T"); echo "stat: $st"
[ "$st" = "www-data:www-data 644" ]; check "A1 file owned by includes/hooks owner (www-data), mode 0644" $?
left=$(ls -A "$SB/whmcs-a/includes/hooks" | grep -c '^\.ispconfigconnect\.php\.' ); echo "temp files: $left"; ls -la "$SB/whmcs-a/includes/hooks"
[ "$left" -eq 0 ]; check "A1 no .ispconfigconnect.php.XXXXXX temp file left" $?
cmp -s "$SRC" "$T"; check "A1 installed file identical to shipped file" $?
cmp -s "$REPO_MOD/hooks/ispconfigconnect.php" "$T"; check "A1 installed file identical to the repo's hook file" $?

run a2 sh "$INST" "$SB/whmcs-a"
[ "$OUT" = "already up to date: $T" ]; check "A2 second run prints 'already up to date: $T'" $?
[ $RC -eq 0 ]; check "A2 exit 0" $?
[ -z "$ERR" ]; check "A2 nothing on stderr (no WARNING)" $?

run a3 sh "$INST" --status "$SB/whmcs-a"
[ "$OUT" = "installed and up to date: $T" ]; check "A3 --status prints 'installed and up to date: $T'" $?
[ $RC -eq 0 ]; check "A3 exit 0" $?
st=$(stat -c '%U:%G %a' "$T"); [ "$st" = "www-data:www-data 644" ]; check "A3 owner/mode unchanged after re-run ($st)" $?
/usr/bin/php8.3 -l "$T" >/dev/null 2>&1; check "A3 installed hook lints clean under php8.3" $?

echo
echo "===== B. symlinked path through a root-only dir -> nobody-owned tree ====="
TB=$SB/whmcs-b/includes/hooks/ispconfigconnect.php
run b1 sh "$INST" "$SB/rootonly/whmcs"
[ "$(firstline "$OUT")" = "installed $TB" ]; check "B1 installs to and prints the PHYSICAL path $TB" $?
[ $RC -eq 0 ]; check "B1 exit 0" $?
printf '%s' "$OUT" | grep -q "rootonly"; [ $? -ne 0 ]; check "B1 printed path does not use the symlinked logical path" $?
st=$(stat -c '%U:%G %a' "$TB"); echo "stat: $st"
[ "$st" = "nobody:nogroup 644" ]; check "B1 file owned by nobody:nogroup, 0644" $?
[ "$(ls -A "$SB/whmcs-b/includes/hooks" | grep -c '^\.ispconfigconnect\.php\.')" -eq 0 ]; check "B1 no temp file left" $?
run b2 sh "$INST" "$SB/rootonly/whmcs"
[ "$OUT" = "already up to date: $TB" ] && [ $RC -eq 0 ]; check "B2 second run via symlink: 'already up to date: $TB', exit 0" $?
run b3 sh "$INST" --status "$SB/rootonly/whmcs"
[ "$OUT" = "installed and up to date: $TB" ] && [ $RC -eq 0 ]; check "B3 --status via symlink: 'installed and up to date: $TB', exit 0" $?

echo
echo "===== C. owner uid 4242 with no passwd entry (setpriv path) ====="
TC=$SB/whmcs-c/includes/hooks/ispconfigconnect.php
run c1 sh "$INST" "$SB/whmcs-c"
[ "$(firstline "$OUT")" = "installed $TC" ] && [ $RC -eq 0 ]; check "C1 installed, exit 0" $?
st=$(stat -c '%u:%g %a' "$TC"); echo "stat: $st"
[ "$st" = "4242:4242 644" ]; check "C1 file owned by 4242:4242, 0644" $?

echo
echo "===== D. run straight from the repository checkout (read-only use) ====="
run d1 sh "$REPO_MOD/hooks/install-hook.sh" --status "$SB/whmcs-a"
[ "$OUT" = "installed and up to date: $T" ] && [ $RC -eq 0 ]; check "D1 --status from the repo copy agrees (same bytes)" $?

echo
echo "===== E. stray marked copy (upgrade check 2) ====="
cp "$T" "$SB/whmcs-a/includes/hooks/ispconfigconnect-old.php"; chown www-data:www-data "$SB/whmcs-a/includes/hooks/ispconfigconnect-old.php"
run e1 sh "$INST" "$SB/whmcs-a"
[ $RC -eq 1 ]; check "E1 install exits 1 while a stray marked copy remains" $?
printf '%s' "$ERR" | grep -q 'WARNING - another copy'; check "E1 prints 'WARNING - another copy'" $?
run e2 sh "$INST" --status "$SB/whmcs-a"
[ $RC -eq 1 ]; check "E2 --status exits 1 while a stray copy remains" $?
grep -l '@ispconfigconnect-hook-file@' "$SB"/whmcs-a/includes/hooks/*.php
rm -f "$SB/whmcs-a/includes/hooks/ispconfigconnect-old.php"
run e3 sh "$INST" --status "$SB/whmcs-a"
[ $RC -eq 0 ] && [ -z "$ERR" ]; check "E3 clean again after deleting the stray" $?

echo
echo "===== F. upgrade over an older marked copy, and foreign-file refusal ====="
printf '<?php\n// @ispconfigconnect-hook-file@ older build\n' > "$SB/.old"; runuser -u www-data -- sh -c "cat > '$T'" < "$SB/.old"
run f1 sh "$INST" --status "$SB/whmcs-a"
[ "$OUT" = "installed but DIFFERENT from the shipped file: $T" ] && [ $RC -eq 0 ]; check "F1 --status reports DIFFERENT for an older copy" $?
run f2 sh "$INST" "$SB/whmcs-a"
[ "$(firstline "$OUT")" = "installed $T" ] && [ $RC -eq 0 ]; check "F2 install replaces the older copy" $?
cmp -s "$SRC" "$T" && [ "$(stat -c '%U %a' "$T")" = "www-data 644" ]; check "F2 replaced bytes identical, still www-data 0644" $?
mk_whmcs "$SB/whmcs-f" www-data:www-data
printf '<?php // somebody else\n' > "$SB/whmcs-f/includes/hooks/ispconfigconnect.php"
run f3 sh "$INST" "$SB/whmcs-f"
[ $RC -eq 1 ] && grep -q '// somebody else' "$SB/whmcs-f/includes/hooks/ispconfigconnect.php"; check "F3 refuses (exit 1) and leaves a foreign file untouched" $?
run f4 sh "$INST" --uninstall "$SB/whmcs-f"
[ $RC -eq 1 ] && [ -f "$SB/whmcs-f/includes/hooks/ispconfigconnect.php" ]; check "F4 --uninstall refuses a foreign file" $?
mk_whmcs "$SB/whmcs-g" www-data:www-data
runuser -u www-data -- ln -s /etc/passwd "$SB/whmcs-g/includes/hooks/ispconfigconnect.php"
run f5 sh "$INST" "$SB/whmcs-g"
[ $RC -eq 1 ] && [ -L "$SB/whmcs-g/includes/hooks/ispconfigconnect.php" ]; check "F5 a symlink planted at the target is refused (not followed)" $?

echo
echo "===== G. --uninstall ====="
run g1 sh "$INST" --uninstall "$SB/whmcs-a"
[ "$OUT" = "removed $T" ] && [ $RC -eq 0 ] && [ ! -e "$T" ]; check "G1 --uninstall: 'removed $T', exit 0, file gone" $?
run g2 sh "$INST" --uninstall "$SB/whmcs-a"
[ "$OUT" = "nothing to remove: $T" ] && [ $RC -eq 0 ]; check "G2 second --uninstall: 'nothing to remove', exit 0" $?
run g3 sh "$INST" --status "$SB/whmcs-a"
[ "$OUT" = "not installed: $T" ] && [ $RC -eq 0 ]; check "G3 --status after uninstall: 'not installed', exit 0" $?
run g4 sh "$INST" --uninstall "$SB/rootonly/whmcs"
[ "$OUT" = "removed $TB" ] && [ $RC -eq 0 ] && [ ! -e "$TB" ]; check "G4 --uninstall via the symlinked path removes the physical file" $?
run g5 sh "$INST" "$SB/rootonly/whmcs"
[ $RC -eq 0 ]; check "G5 reinstall via symlink after uninstall" $?

echo
echo "===== H. usage errors ====="
run h1 sh "$INST"
[ $RC -eq 2 ]; check "H1 no argument: exit 2" $?
run h2 sh "$INST" "$SB/private"
[ $RC -eq 1 ]; check "H2 not a WHMCS root: exit 1" $?

echo
echo "TOTAL PASS=$PASS FAIL=$FAIL"
