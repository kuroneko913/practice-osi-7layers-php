<?php

namespace App;

// 観点別の実験をまとめて実行する。個別に実行する場合は src/Experiments/ 以下のスクリプトを直接呼ぶ
$experiments = [
    'unknown_unicast',
    'known_unicast',
    'broadcast',
    'ethertype',
    'invalid_frames',
    'self_delivery',
    'station_move',
    'port_swap',
    'aging',
    'link_state',
    'flood_sniffing',
    'mac_spoofing',
    'table_overflow',
];

echo "════════════════════════════════════════════════════════════════\n";
echo "       L2 スイッチ＆4台のPC (A, B, C, D) 実験シミュレータ       \n";
echo "              〜 データリンク層の限界を体験する 〜              \n";
echo "════════════════════════════════════════════════════════════════\n\n";

foreach ($experiments as $experiment) {
    // 各実験はMACアドレステーブルが空のスイッチから始める
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/Experiments/$experiment.php"), $exitCode);
    echo "\n";
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

echo "════════════════════════════════════════════════════════════════\n";
echo "【実験完了】L2データリンク層の限界が浮き彫りになりました！\n";
echo "════════════════════════════════════════════════════════════════\n";
