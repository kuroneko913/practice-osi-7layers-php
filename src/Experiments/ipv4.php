<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\EtherType;
use App\Layers\NetworkLayer\Ipv4Packet;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: IPv4】ヘッダの全長でパディングを除き、チェックサムと宛先を検査する\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

$report = function (array $received): void {
    if (!isset($received['B'])) {
        echo "  [PC-B] 🛡️ 上位層へは渡されませんでした\n";
    }
    foreach ($received['B'] ?? [] as $packet) {
        $bytes = strlen($packet->getPayload());
        echo "  [PC-B] ⭕ {$packet->getSource()} から {$bytes} バイト受信: \"{$packet->getPayload()}\"\n";
    }
};

$send = function (string $bytes) use ($lab): void {
    $lab->sendFrame('A', $bytes, $lab->mac('B'), EtherType::IPv4);
};
$packet = new Ipv4Packet($lab->ip('A'), $lab->ip('B'), Ipv4Packet::PROTOCOL_EXPERIMENTAL, 'hello');

echo "▶ 正常なパケット (5バイト。Ethernetでは46バイトにパディングされます)\n";
$send($packet->toBytes());
$report($lab->exchange());

echo "▶ ヘッダが壊れたパケット (TTLを書き換えたが、チェックサムは古いまま)\n";
$corrupted = $packet->toBytes();
$corrupted[8] = chr(ord($corrupted[8]) - 1);
$send($corrupted);
$report($lab->exchange());

echo "▶ MACアドレスはPC-B宛てだが、IPアドレスはPC-C宛てのパケット\n";
$send((new Ipv4Packet($lab->ip('A'), $lab->ip('C'), Ipv4Packet::PROTOCOL_EXPERIMENTAL, 'hello'))->toBytes());
$report($lab->exchange());

echo "▶ PC-Aが自分のIPアドレス宛てに送信\n";
$lab->sendPacket('A', '自分へのメモ', $lab->ip('A'));
$local = $lab->exchange()['A'][0] ?? null;
echo $local !== null ? "  [PC-A] ⭕ 受信: \"{$local->getPayload()}\" (Ethernetには流れません)\n" : "  [PC-A] ── 未受信\n";

echo "\n💡 解説:\n";
echo "  ・Ethernetのフレームには長さがないので、パディングを除けるのはIPヘッダの全長のおかげです。\n";
echo "  ・ヘッダチェックサムが合わないパケットは破棄されます。\n";
echo "  ・FCSはフレーム全体、IPチェックサムはIPヘッダだけを守ります。層ごとに検査の範囲が違います。\n";
echo "  ・ルーターを実装していないので、TTLはまだ減りません。\n\n";

$lab->finish();
