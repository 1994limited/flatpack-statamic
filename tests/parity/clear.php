<?php
// Clear the Stache (cold start) for the given app, so the next dump/render pays for warming.
require $argv[1].'/vendor/autoload.php'; $app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($boot = getenv('PARITY_BOOT')) require $boot;
Statamic\Facades\Stache::clear();
echo "cleared\n";
