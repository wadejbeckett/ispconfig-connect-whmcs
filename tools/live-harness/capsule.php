<?php

declare(strict_types=1);

namespace WHMCS\Database;

/**
 * WHMCS's Capsule is Laravel's Capsule manager; the module only uses its
 * static query builder facade (Capsule::table()).
 */
class Capsule extends \Illuminate\Database\Capsule\Manager
{
}
