{* ISPConfig Connect - client area product overview (full replacement).
   Design rule §3.6.3: no credential is ever rendered here - the panel login
   name is shown, a secret never is. *}

<h2>{$product}</h2>

<div class="row">
    <div class="col-sm-5">{$LANG.orderproduct}</div>
    <div class="col-sm-7">{$groupname} - {$product}</div>
</div>

{if $domain}
    <div class="row">
        <div class="col-sm-5">{$LANG.orderdomain}</div>
        <div class="col-sm-7">
            {$domain}
            <a href="http://{$domain}" target="_blank" class="btn btn-default btn-xs">{$LANG.visitwebsite}</a>
        </div>
    </div>
{/if}

<div class="row">
    <div class="col-sm-5">{$LANG.clientareastatus}</div>
    <div class="col-sm-7">{$status}</div>
</div>

<div class="row">
    <div class="col-sm-5">{$LANG.clientareahostingnextduedate}</div>
    <div class="col-sm-7">{$nextduedate}</div>
</div>

<div class="row">
    <div class="col-sm-5">{$LANG.orderbillingcycle}</div>
    <div class="col-sm-7">{$billingcycle}</div>
</div>

<hr>

<h3>ISPConfig Control Panel</h3>

<div class="row">
    <div class="col-sm-5">Control panel login name</div>
    <div class="col-sm-7">{$ispconfigUsername}</div>
</div>

<div class="row">
    <div class="col-sm-5">Control panel</div>
    <div class="col-sm-7">
        <a href="{$panelUrl}" target="_blank" class="btn btn-primary btn-sm">Open Control Panel</a>
    </div>
</div>

<p class="text-muted">
    Changes made here or in the control panel are applied by the hosting
    server within about a minute of being accepted.
</p>
