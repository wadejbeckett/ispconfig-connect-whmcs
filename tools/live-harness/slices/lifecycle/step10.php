<?php
// Step 10: admin service tab escaping.
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S1 = $st['S1'];

hr('Step 10: admin tab escaping');
$orig = prop_current($S1, 'ispconfig_client_username');
out('  original username property: ' . json_encode($orig));
// The plan's SQL, verbatim (with <S1> substituted).
$payload = '<b>B</b><img src=x onerror=alert(1)>';
$n = \WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value=? WHERE f.type='product' AND f.fieldname='ispconfig_client_username' AND v.relid=?", [$payload, $S1]);
out("  plan SQL updated $n row(s)");
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S1));
foreach ($tab as $k => $v) {
    out(sprintf('    %-34s => %s', $k, mb_substr($v, 0, 200)));
}
// Render as WHMCS does: label/value pairs dropped into the admin page HTML unescaped.
$html = '';
foreach ($tab as $k => $v) {
    $html .= "<tr><td class=\"fieldlabel\">$k</td><td class=\"fieldarea\">$v</td></tr>\n";
}
file_put_contents(LT_DIR . '/step10-admin-tab.html', $html);
$link = $tab['ISPConfig Link'] ?? '';
$user = $tab['ISPConfig Username'] ?? '';
assert_line('10', str_contains($link, '&lt;b&gt;B&lt;/b&gt;&lt;img src=x onerror=alert(1)&gt;') && !str_contains($link, '<b>') && !str_contains($link, '<img'), 'ISPConfig Link row shows the markup literally (&lt;b&gt;...): ' . $link);
assert_line('10', $user === '&lt;b&gt;B&lt;/b&gt;&lt;img src=x onerror=alert(1)&gt;', 'ISPConfig Username row shows the markup literally: ' . $user);
$raw = preg_match('/<(b|img)[\s>]/i', $html) === 1;
assert_line('10', !$raw, 'rendered tab HTML (step10-admin-tab.html) carries no live <b> or <img> element; source shows &lt;b&gt;');

// Restore.
\WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value=? WHERE f.type='product' AND f.fieldname='ispconfig_client_username' AND v.relid=?", [$orig, $S1]);
$tab2 = ispconfigconnect_AdminServicesTabFields(harness_params($S1));
assert_line('10', ($tab2['ISPConfig Username'] ?? null) === $orig, 'real username restored: ' . json_encode($tab2['ISPConfig Username'] ?? null));
