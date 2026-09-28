#!/bin/sh
# Plan section 4 steps 1-3 on the simulated WHMCS root.
U=/opt/livetest/upgrade; R=$U/whmcs-root; REPO=/home/user/ispconfig-connect-whmcs
echo "== 4.1 locked clients vs Suspended services"
mariadb --table -e "SELECT client_id,username,locked FROM dbispconfig7.client WHERE locked='y';"
mariadb --table whmcs7 -e "SELECT id,domain,packageid,domainstatus FROM tblhosting WHERE domainstatus='Suspended';"
echo "== 4.2 restore files from the tarball"
rm -rf $R/modules/servers/ispconfigconnect && tar xzpf $R/backup/ispcc-prefix-backup.tgz -C $R modules/servers/ispconfigconnect; echo "tar exit=$?"
(cd $R/modules/servers && echo "digest: $(find ispconfigconnect -type f | LC_ALL=C sort | xargs sha256sum | sha256sum)  files: $(find ispconfigconnect -type f | wc -l)")
echo "recorded before upgrade: $(cat $R/backup/pre-upgrade-digest.txt)"
echo "== 4.2 alternative: git archive 1ed05b3 (into a scratch root)"
rm -rf $U/git-archive-root && mkdir -p $U/git-archive-root && git -C $REPO archive 1ed05b3 modules/servers/ispconfigconnect | tar -x -C $U/git-archive-root
(cd $U/git-archive-root/modules/servers && echo "digest: $(find ispconfigconnect -type f | LC_ALL=C sort | xargs sha256sum | sha256sum)")
echo "== 4.3 restore the hook: run the restored installer"
sh $R/modules/servers/ispconfigconnect/hooks/install-hook.sh --status $R; echo "status(before) exit=$?"
sh $R/modules/servers/ispconfigconnect/hooks/install-hook.sh $R; echo "install exit=$?"
sh $R/modules/servers/ispconfigconnect/hooks/install-hook.sh --status $R; echo "status exit=$?"
cmp $R/includes/hooks/ispconfigconnect.php $U/old/modules/servers/ispconfigconnect/hooks/ispconfigconnect.php && echo "hook == old build hook"
echo "== 4.3 alternative: copy the backed-up hook back"
mkdir -p $U/hookcopy && tar xzpf $R/backup/ispcc-prefix-backup.tgz -C $U/hookcopy includes/hooks/ispconfigconnect.php && cmp $U/hookcopy/includes/hooks/ispconfigconnect.php $R/includes/hooks/ispconfigconnect.php && echo "backed-up hook identical to the one now installed"
