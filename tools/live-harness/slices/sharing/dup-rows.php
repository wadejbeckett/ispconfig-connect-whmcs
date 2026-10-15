<?php

/**
 * tblcustomfieldsvalues has NO unique key on (fieldid, relid) in real WHMCS. Do duplicate
 * value rows for one field/service confuse the property reader, the clearer, the
 * shared-client guard or the usage sweep?
 *
 * Reader  = $model->serviceProperties->get() (WHMCS; the harness returns the first row in
 *           index order = OLDEST), falling back to the module's newest-row reader when blank.
 * Guard   = ispconfigconnect_otherServicesLinkedToClient() -> effectivePropertyValues(): NEWEST row.
 * Sweep   = ispconfigconnect_serviceRefs() -> serverServiceRows() -> effectivePropertyValues().
 * Clearer = ServiceProperties::clear() -> model save + cross-product blanking of every row.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$st = load_state();
[$P1, $srv1] = [$st['p1'], $st['srv1']];
$fidId = field_id($P1, 'ispconfig_client_id');
$fidUser = field_id($P1, 'ispconfig_client_username');

function guard_sees(int $asService, int $clientId, int $candidate): bool
{
    $others = ispconfigconnect_otherServicesLinkedToClient($asService, $clientId) ?? [];

    return in_array($candidate, array_column($others, 'serviceid'), true);
}

function sweep_client(int $serverId, int $serviceId): int
{
    foreach (ispconfigconnect_serviceRefs($serverId) as $ref) {
        if ($ref->serviceId === $serviceId) {
            return $ref->clientId;
        }
    }

    return -1;
}

function show_view(string $label, int $sid, int $srv, int $holder, int $clientId, ?array $params = null): array
{
    $reader = module_reads($sid, $params)['client_id'];
    $guard = guard_sees($holder, $clientId, $sid);
    $sweep = sweep_client($srv, $sid);
    out(sprintf('  %s: reader=%s | guard (from service #%d, client #%d) sees #%d as sharer: %s | sweep clientId=%d',
        $label, var_export($reader, true), $holder, $clientId, $sid, $guard ? 'YES' : 'no', $sweep));

    return [$reader, $guard, $sweep];
}

hdr('DUP setup: D1 (created, holds a client), D2/D3/D4 (never created), D5 created then unlinked (free client)');
$D1 = harness_add_service($P1, $srv1, 'dup-one.example', ['userid' => 101]);
$D2 = harness_add_service($P1, $srv1, 'dup-two.example', ['userid' => 102, 'domainstatus' => 'Active']);
$D3 = harness_add_service($P1, $srv1, 'dup-three.example', ['userid' => 103, 'domainstatus' => 'Active']);
$D4 = harness_add_service($P1, $srv1, 'dup-four.example', ['userid' => 104, 'domainstatus' => 'Active']);
$D5 = harness_add_service($P1, $srv1, 'dup-five.example', ['userid' => 105]);
out('  Create D1: ' . harness_call('CreateAccount', $D1));
out('  Create D5: ' . harness_call('CreateAccount', $D5));
$M = module_reads($D1)['client_id'];
$X = module_reads($D5)['client_id'];
$Xuser = module_reads($D5)['username'];
out("  D1=#$D1 -> client #$M; D5=#$D5 -> client #$X ($Xuser); D2=#$D2 D3=#$D3 D4=#$D4");
out('  Unlink D5: ' . harness_call('UnlinkAccount', $D5) . " (client #$X is now held by no service)");

hdr('DUP-B (control): D2 rows [older blank, newer M] - the shape the plan\'s step 17.3 INSERT produces after an admin save');
insert_value($fidId, $D2, '');
insert_value($fidId, $D2, (string) $M);
print_props($D2);
[$r, $g, $s] = show_view('D2', $D2, $srv1, $D1, $M);
check('DUP-B', $r === $M && $g && $s === $M, 'older blank + newer value: reader, guard and sweep agree');

hdr('DUP-A: D2 rows [older M, newer blank] (a hand edit / import / first-row-only save leaves this)');
Capsule::table('tblcustomfieldsvalues')->where('relid', $D2)->delete();
insert_value($fidId, $D2, (string) $M);
insert_value($fidId, $D2, '');
print_props($D2);
[$r, $g, $s] = show_view('D2', $D2, $srv1, $D1, $M);
check('DUP-A', $r === $M && $g && $s === $M, 'older value + newer blank: reader, guard and sweep agree', "reader=$r guard=" . var_export($g, true) . " sweep=$s");
out('  consequence: D1 (holding the same client) is NOT refused by the guard although D2 reads as linked to #' . $M . ':');
$pw = 'Dup-A-Rotation-1-Xy9';
Capsule::table('tblhosting')->where('id', $D1)->update(['password' => $pw]);
$rr = harness_call('ChangePassword', $D1);
out("  Change Password D1 -> $rr");
check('DUP-A-effect', $rr !== 'success', 'a whole-client operation on D1 is refused while D2 reads as a sharer', $rr);
$tabD2 = ispconfigconnect_AdminServicesTabFields(harness_params($D2));
out('  D2 admin tab says: ' . ($tabD2['ISPConfig Link'] ?? '?'));

hdr('DUP-C: D2 rows [older M, newer K] (two different ids)');
Capsule::table('tblcustomfieldsvalues')->where('relid', $D2)->delete();
insert_value($fidId, $D2, (string) $M);
insert_value($fidId, $D2, '999');
print_props($D2);
[$r, $g, $s] = show_view('D2', $D2, $srv1, $D1, $M);
check('DUP-C', $r === $s, 'reader and sweep agree on which client D2 is linked to', "reader=$r sweep=$s guard-sees-as-sharer-of-#$M=" . var_export($g, true));
Capsule::table('tblcustomfieldsvalues')->where('relid', $D2)->delete();

hdr('DUP-CLR: the clearer (Unlink) with duplicate rows on two products');
insert_value($fidId, $D2, '');
insert_value($fidId, $D2, (string) $M);
insert_value($fidId, $D2, (string) $M);
insert_value($fidUser, $D2, 'x');
insert_value($fidUser, $D2, 'y');
$fidP3 = ensure_field($st['p3'], 'ispconfig_client_id');
insert_value($fidP3, $D2, (string) $M);
insert_value($fidP3, $D2, (string) $M);
print_props($D2, '(before Unlink)');
out('  Unlink D2: ' . harness_call('UnlinkAccount', $D2));
print_props($D2, '(after Unlink)');
check('DUP-CLR', nonblank_props($D2) === [] && module_reads($D2)['client_id'] === null && sweep_client($srv1, $D2) === 0, 'Unlink blanks every duplicate row on every product; reader and sweep then agree: unlinked');
Capsule::table('tblcustomfieldsvalues')->where('relid', $D2)->delete();

hdr('DUP-D (flow): plan-style INSERT on an admin-saved service, Unlink, then Link by name, then a SECOND service Links the same account');
foreach (['harness (update ALL matching rows)' => 'harness', 'first-row-only save (Eloquent firstOrNew-style)' => 'first'] as $label => $mode) {
    out("  --- save semantics: $label");
    $A = harness_add_service($P1, $srv1, "dupd-a-$mode.example", ['userid' => 111, 'domainstatus' => 'Active']);
    $B = harness_add_service($P1, $srv1, "dupd-b-$mode.example", ['userid' => 112, 'domainstatus' => 'Active']);
    // admin "Save Changes" rows, then the plan's test INSERT
    foreach (Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $P1)->get() as $f) {
        insert_value((int) $f->id, $A, '');
    }
    insert_value($fidId, $A, (string) $M);
    $pa = static fn () => $mode === 'first' ? first_row_params($A) : harness_params($A);
    $pb = static fn () => $mode === 'first' ? first_row_params($B) : harness_params($B);
    out('  Unlink A: ' . ispconfigconnect_UnlinkAccount($pa()));
    Capsule::table('tblhosting')->where('id', $A)->update(['username' => $Xuser]);
    out('  Link A to "' . $Xuser . '": ' . ispconfigconnect_LinkAccount($pa()));
    print_props($A, '(A after Link)');
    [$ra, $ga, $sa] = show_view('A', $A, $srv1, $B, $X, $pa());
    Capsule::table('tblhosting')->where('id', $B)->update(['username' => $Xuser]);
    $lb = ispconfigconnect_LinkAccount($pb());
    out('  Link B to the SAME account "' . $Xuser . '": ' . substr($lb, 0, 200));
    check("DUP-D-$mode", str_starts_with($lb, 'Link refused') && $ra === $X && $ga && $sa === $X, "[$mode] A reads #$X and the guard/sweep see it; B's Link to the same account is refused", "A reader=$ra guard=" . var_export($ga, true) . " sweep=$sa; B Link=" . substr($lb, 0, 60));
    // Clean both so the next mode starts from a free client.
    ispconfigconnect_UnlinkAccount($pa());
    ispconfigconnect_UnlinkAccount($pb());
    set_status($A, 'Cancelled');
    set_status($B, 'Cancelled');
}

$st['dup'] = ['D1' => $D1, 'D2' => $D2, 'D3' => $D3, 'D4' => $D4, 'D5' => $D5, 'M' => $M, 'X' => $X];
save_state($st);
summary();
