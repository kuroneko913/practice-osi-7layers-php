<?php

namespace App\Experiments;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: 自局宛て】自分宛てに送信したフレームは自分のMACで受信する\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

echo "▶ 送信元: PC-A -> 宛先: PC-A ({$lab->mac('A')->format()})\n";
echo "  メッセージ: \"自分へのメモ\"\n\n";

$lab->send('A', '自分へのメモ', $lab->mac('A'));

echo "【各PCのNIC受信結果】\n";
$frame = $lab->receive('A');
if ($frame !== null) {
    echo "  [PC-A] ⭕ 【受信成功！】: \"" . rtrim($frame->getPayload(), "\0") . "\"\n";
} else {
    echo "  [PC-A] ── 未受信\n";
}
foreach ($lab->observe('A') as $name => $res) {
    if ($res['arrived']) {
        echo "  [PC-$name] ⚠️ フレームが届きました\n";
    } else {
        echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
    }
}

echo "\n💡 解説:\n";
echo "  ・全二重の物理層は送信データを折り返さないため、PC-AのMACが自分で受信します (IEEE 802.3 4.1.2)。\n";
echo "  ・スイッチは送信元のPC-AをPort 1として学習した直後に、宛先も同じPort 1だと判断してフレームを破棄します。\n\n";

$lab->finish();
