<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\EtherType;
use App\Layers\DataLinkLayer\EthernetFrame;
use App\Layers\NetworkLayer\ArpPacket;
use App\Layers\NetworkLayer\Ipv4Packet;

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

// EtherTypeの宣言と中身が一致するよう、種別ごとに正しい形式のデータを送る
$ipv6Header = pack('NnCC', 0x60000000, 0, 59, 64) // ペイロードなし (Next Header = 59: No Next Header)
    . inet_pton('2001:db8::1') . inet_pton('2001:db8::2');
$payloads = [
    EtherType::IPv4->name => (new Ipv4Packet($lab->ip('A'), $lab->ip('B'), Ipv4Packet::PROTOCOL_EXPERIMENTAL, 'hello'))->toBytes(),
    EtherType::ARP->name => ArpPacket::request($lab->mac('A'), $lab->ip('A'), $lab->ip('B'))->toBytes(),
    EtherType::IPv6->name => $ipv6Header,
    EtherType::LocalExperimental->name => 'hello',
];

foreach (EtherType::cases() as $type) {
    echo "▶ PC-A -> PC-B: {$type->name} として送信\n";
    $lab->sendFrame('A', $payloads[$type->name], $lab->mac('B'), $type);
    $reportAtB();
}

echo "▶ PC-A -> PC-B: 未対応の EtherType 0x88B6 として送信\n";
$unknown = new EthernetFrame($lab->mac('B'), $lab->mac('A'), 0x88B6, str_pad('hello', EthernetFrame::MIN_PAYLOAD_BYTES, "\0"));
$lab->transmit('A', $unknown->toWire());
$reportAtB();

echo "\n💡 解説:\n";
echo "  ・EtherTypeを見れば、データ部分をどのプロトコルとして処理すべきか判断できます。\n";
echo "  ・スイッチの転送判定はMACアドレスのみで行うため、未対応の種別もそのまま転送されました。\n";
echo "  ・EtherTypeと中身が食い違うと、受信側やWiresharkは不正なパケットとして扱います。\n\n";

$lab->finish();
