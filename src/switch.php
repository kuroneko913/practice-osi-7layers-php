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

// ポートごとのVLAN (例: PORT_VLANS=1:10,2:10,3:20,4:20)
$portVlans = [];
foreach (array_filter(explode(',', (string)getenv('PORT_VLANS'))) as $assignment) {
    if (!preg_match('/\A\s*(\d+)\s*:\s*(\d+)\s*\z/', $assignment, $m)) {
        echo "⚠️ PORT_VLANS の形式が不正です: $assignment (例: PORT_VLANS=1:10,2:10,3:20,4:20)\n";
        exit(1);
    }
    $portVlans[(int)$m[1]] = (int)$m[2];
}

// トランクポート (例: TRUNK_PORTS=5)。別のスイッチとつなぎ、すべてのVLANのフレームをタグ付きで流す
$trunkPorts = [];
foreach (array_filter(explode(',', (string)getenv('TRUNK_PORTS'))) as $port) {
    if (!ctype_digit(trim($port))) {
        echo "⚠️ TRUNK_PORTS の形式が不正です: $port (例: TRUNK_PORTS=5)\n";
        exit(1);
    }
    $trunkPorts[] = (int)trim($port);
}

try {
    $switch = new L2Switch(
        $portCount,
        $fifoDir,
        agingSeconds: $agingSeconds,
        macTableSize: $macTableSize,
        capture: $capture,
        keepMacOnLinkDown: $keepMacOnLinkDown,
        portVlans: $portVlans,
        trunkPorts: $trunkPorts,
    );
} catch (\RuntimeException | \InvalidArgumentException $e) {
    echo "⚠️ " . $e->getMessage() . "\n";
    exit(1);
}
$switch->run();
