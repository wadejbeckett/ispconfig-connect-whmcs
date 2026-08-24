{* ISPConfig Connect - client area product overview (full replacement).
   Design rule §3.6.3: no credential is ever rendered here - the panel login
   name is shown, a secret never is.
   WHMCS ships Smarty auto-escaping OFF for server-module templates, so every
   rendered variable carries |escape explicitly (Smarty html escaping uses
   ENT_QUOTES, so it is attribute-safe too). *}

<h2>{$product|escape}</h2>

<div class="row">
    <div class="col-sm-5">{$LANG.orderproduct|escape}</div>
    <div class="col-sm-7">{$groupname|escape} - {$product|escape}</div>
</div>

{if $domain}
    <div class="row">
        <div class="col-sm-5">{$LANG.orderdomain|escape}</div>
        <div class="col-sm-7">
            {$domain|escape}
            <a href="http://{$domain|escape}" target="_blank" class="btn btn-default btn-xs">{$LANG.visitwebsite|escape}</a>
        </div>
    </div>
{/if}

<div class="row">
    <div class="col-sm-5">{$LANG.clientareastatus|escape}</div>
    <div class="col-sm-7">{$status|escape}</div>
</div>

<div class="row">
    <div class="col-sm-5">{$LANG.clientareahostingnextduedate|escape}</div>
    <div class="col-sm-7">{$nextduedate|escape}</div>
</div>

<div class="row">
    <div class="col-sm-5">{$LANG.orderbillingcycle|escape}</div>
    <div class="col-sm-7">{$billingcycle|escape}</div>
</div>

<hr>

<h3>ISPConfig Control Panel</h3>

<div class="row">
    <div class="col-sm-5">Control panel login name</div>
    <div class="col-sm-7">{$ispconfigUsername|escape}</div>
</div>

<div class="row">
    <div class="col-sm-5">Control panel</div>
    <div class="col-sm-7">
        <a href="{$panelUrl|escape}" target="_blank" class="btn btn-primary btn-sm">Open Control Panel</a>
    </div>
</div>

<p class="text-muted">
    Changes made here or in the control panel are applied by the hosting
    server within about a minute of being accepted.
</p>
