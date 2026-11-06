<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Devices\L2Switch;
use App\Devices\PcapWriter;

$portCount = (int)(getenv('PORT_COUNT') ?: 4);
$fifoDir = getenv('FIFO_DIR') ?: (dirname(__DIR__, 1) . '/tmp/switch');

$agingSeconds = (float)(getenv('MAC_AGING_SECONDS') ?: 300);
$macTableSize = (int)(getenv('MAC_TABLE_SIZE') ?: 1024);

$capturePath = getenv('CAPTURE_PATH');
$capture = $capturePath ? new PcapWriter($capturePath) : null;

$keepMacOnLinkDown = (bool)getenv('MAC_KEEP_ON_LINK_DOWN');

try {
    $switch = new L2Switch(
        $portCount,
        $fifoDir,
        agingSeconds: $agingSeconds,
        macTableSize: $macTableSize,
        capture: $capture,
        keepMacOnLinkDown: $keepMacOnLinkDown,
    );
} catch (\RuntimeException $e) {
    echo "⚠️ " . $e->getMessage() . "\n";
    exit(1);
}
$switch->run();
