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

{* Single sign-on is OPTIONAL and PER SERVER: it needs the separate ispconfig-sso
   project on the panel this service is hosted on. WHMCS decides the module-wide
   SSO label with no server in scope, so on a fleet where only some panels carry
   that project the label alone would offer a login button that can only fail.
   This gate is the per-service half of the answer - ispconfigconnect_ClientArea()
   sets $ssoAvailable from THIS service's own server - and where it is false the
   customer simply sees the panel address and login name above, with no broken
   button. The link is WHMCS's own documented single sign-on entry point
   (developers.whmcs.com/provisioning-modules/single-sign-on/), so no panel URL
   or token is ever assembled here.
   $WEB_ROOT is the client area's own variable, not one this module passes: this
   template is rendered inside the product details page and shares its scope,
   which is the same reason $LANG, $product and $status resolve above. *}
{if $ssoAvailable}
    <div class="row">
        <div class="col-sm-5">One-click login</div>
        <div class="col-sm-7">
            <a href="{$WEB_ROOT|escape}/clientarea.php?action=productdetails&amp;id={$ssoServiceId|escape:'url'}&amp;dosinglesignon=1"
               class="btn btn-default btn-sm">{$ssoLabel|escape}</a>
        </div>
    </div>
{/if}

<p class="text-muted">
    Changes made here or in the control panel are applied by the hosting
    server within about a minute of being accepted.
</p>
