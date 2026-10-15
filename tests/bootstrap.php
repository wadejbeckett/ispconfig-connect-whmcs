<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/whmcs-functions.php';
// In-memory WHMCS\Database\Capsule stand-in. Disabled by default: a query made
// before a test opts in throws the same "unavailable" RuntimeException a host
// without the class raises, so an unopted test sees exactly the class-absent
// behaviour. Tests opt in with Capsule::enable()/seed(); ModuleTestCase resets
// it around every test.
require_once __DIR__ . '/stubs/CapsuleDouble.php';
