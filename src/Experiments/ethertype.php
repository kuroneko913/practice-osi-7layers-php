<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\EtherType;
use App\Layers\DataLinkLayer\EthernetFrame;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: EtherType】受信側はプロトコル種別を識別し、スイッチは種別を問わず転送する\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

$reportAtB = function () use ($lab): void {
    $res = $lab->observe('A')['B'];
    if (!$res['accepted']) {
        echo "  [PC-B] ── 未受信\n";
        return;
    }
    $hex = sprintf('0x%04X', $res['etherType']);
    $type = EtherType::fromInt($res['etherType']);
    $description = $type?->description() ?? '未対応のプロトコル (上位層へ渡す先がない)';
    echo "  [PC-B] ⭕ EtherType $hex: $description\n";
};

foreach (EtherType::cases() as $type) {
    echo "▶ PC-A -> PC-B: {$type->name} として送信\n";
    $lab->sendFrame('A', "{$type->name} payload", $lab->mac('B'), $type);
    $reportAtB();
}

echo "▶ PC-A -> PC-B: 未対応の EtherType 0x88B6 として送信\n";
$unknown = new EthernetFrame($lab->mac('B'), $lab->mac('A'), 0x88B6, str_pad('hello', EthernetFrame::MIN_PAYLOAD_BYTES, "\0"));
$lab->transmit('A', $unknown->toWire());
$reportAtB();

echo "\n💡 解説:\n";
echo "  ・EtherTypeを見れば、データ部分をどのプロトコルとして処理すべきか判断できます。\n";
echo "  ・スイッチの転送判定はMACアドレスのみで行うため、未対応の種別もそのまま転送されました。\n";
echo "  ・プロトコル本体 (ARP, IPv4, IPv6) はまだ実装していません。ここでは種別の識別までを確認しています。\n\n";

$lab->finish();
