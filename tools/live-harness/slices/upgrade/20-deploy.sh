#!/bin/sh
# Section 2 checks 2.1-2.3 on the simulated WHMCS root (old build deployed there by phase 0).
U=/opt/livetest/upgrade; R=$U/whmcs-root; REPO=/home/user/ispconfig-connect-whmcs
P82=/tmp/claude-0/-home-user-ispconfig-connect-whmcs/3b4b6608-32bb-51b3-a26d-ea850c8c07e5/scratchpad/readiness/runtime/php8.2
echo "== 2.1 what a plain cp -r would do (scratch copy, informational)"
rm -rf $U/cp-r-test && mkdir -p $U/cp-r-test && cp -a $R/modules/servers/ispconfigconnect $U/cp-r-test/ && cp -r $REPO/modules/servers/ispconfigconnect/. $U/cp-r-test/ispconfigconnect/
(cd $U/cp-r-test && find ispconfigconnect -type f | LC_ALL=C sort | xargs sha256sum | sha256sum; find ispconfigconnect -type f | wc -l)
echo "files only in old build (would be left stale by cp -r):"; (cd $U/old/modules/servers/ispconfigconnect && find . -type f | sort) > $U/old.lst; (cd $REPO/modules/servers/ispconfigconnect && find . -type f | sort) > $U/new.lst; comm -23 $U/old.lst $U/new.lst; echo "(end)"; echo "files only in new build:"; comm -13 $U/old.lst $U/new.lst
echo "== 2.1 rsync -a --delete"
rsync -a --delete $REPO/modules/servers/ispconfigconnect/ $R/modules/servers/ispconfigconnect/; echo "rsync exit=$?"
cd $R/modules/servers && echo "host digest: $(find ispconfigconnect -type f | LC_ALL=C sort | xargs sha256sum | sha256sum)"; echo "files: $(find ispconfigconnect -type f | wc -l)"
sha256sum ispconfigconnect/ispconfigconnect.php ispconfigconnect/hooks/ispconfigconnect.php ispconfigconnect/hooks/install-hook.sh
test -f ispconfigconnect/lib/Provision/ClientLockedException.php && echo "ClientLockedException.php present"
echo "== Step 2 lint: php8.3 -l"; n=0; bad=0; for f in $(find $R/modules/servers/ispconfigconnect -name '*.php'); do n=$((n+1)); /usr/bin/php8.3 -l $f >/dev/null 2>&1 || { bad=$((bad+1)); /usr/bin/php8.3 -l $f; }; done; echo "php8.3: $n files, $bad with errors"
echo "== Step 2 lint: php8.2 -l"; n=0; bad=0; for f in $(find $R/modules/servers/ispconfigconnect -name '*.php'); do n=$((n+1)); $P82 -l $f >/dev/null 2>&1 || { bad=$((bad+1)); $P82 -l $f; }; done; echo "php8.2: $n files, $bad with errors"
/usr/bin/php8.3 -l $R/modules/servers/ispconfigconnect/ispconfigconnect.php; $P82 -l $R/modules/servers/ispconfigconnect/ispconfigconnect.php
echo "== 2.2 old hook files"
ls -la $R/modules/servers/ispconfigconnect/hooks.php 2>&1
echo "marker grep:"; grep -l '@ispconfigconnect-hook-file@' $R/includes/hooks/*.php
echo "-- stray marked copy test: copy the OLD hook to includes/hooks/ispcc-old-copy.php"
cp $R/includes/hooks/ispconfigconnect.php $R/includes/hooks/ispcc-old-copy.php
grep -l '@ispconfigconnect-hook-file@' $R/includes/hooks/*.php
sh $R/modules/servers/ispconfigconnect/hooks/install-hook.sh $R; echo "installer exit=$? (plan: 1 while a stray marked copy remains)"
cmp -s $R/includes/hooks/ispconfigconnect.php $U/old/modules/servers/ispconfigconnect/hooks/ispconfigconnect.php && echo "includes/hooks/ispconfigconnect.php is still the OLD hook"
rm $R/includes/hooks/ispcc-old-copy.php; echo "stray removed"; grep -l '@ispconfigconnect-hook-file@' $R/includes/hooks/*.php
echo "== 2.3 / step 3 re-run the hook installer (new build)"
sh $R/modules/servers/ispconfigconnect/hooks/install-hook.sh $R; echo "run1 exit=$?"
sh $R/modules/servers/ispconfigconnect/hooks/install-hook.sh $R; echo "run2 exit=$?"
sh $R/modules/servers/ispconfigconnect/hooks/install-hook.sh --status $R; echo "status exit=$?"
cmp $R/includes/hooks/ispconfigconnect.php $REPO/modules/servers/ispconfigconnect/hooks/ispconfigconnect.php && echo "installed hook == new build hook"
ls -la $R/includes/hooks/; ls -la $R/includes/hooks/.ispconfigconnect* 2>&1 | head -2
