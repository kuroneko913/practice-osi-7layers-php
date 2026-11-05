<?php

namespace App\Experiments;

use App\Layers\NetworkLayer\IpAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: ARP】宛先のIPアドレスから、ブロードキャストでMACアドレスを調べる\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

$reportAtB = function (array $received) use ($lab): void {
    foreach ($received['B'] ?? [] as $packet) {
        echo "  [PC-B] ⭕ {$packet->getSource()} から受信: \"{$packet->getPayload()}\"\n";
    }
    foreach (['C', 'D'] as $name) {
        if (isset($received[$name])) {
            echo "  [PC-$name] ⚠️ パケットを受信してしまいました\n";
        }
    }
};

echo "▶ PC-A -> {$lab->ip('B')}: PC-AはPC-BのMACアドレスを知りません\n";
$lab->sendPacket('A', '1通目', $lab->ip('B'));
$reportAtB($lab->exchange());
foreach (['A', 'B', 'C'] as $name) {
    $lab->printArpTable($name);
}

echo "\n▶ PC-A -> {$lab->ip('B')}: ARPキャッシュがあるので、すぐに送信します\n";
$lab->sendPacket('A', '2通目', $lab->ip('B'));
$reportAtB($lab->exchange());

echo "\n▶ PC-A -> 192.168.1.200: 誰も使っていないIPアドレス\n";
$nobody = new IpAddress('192.168.1.200');
$lab->sendPacket('A', '届かないパケット', $nobody);
$lab->exchange();
echo "  [PC-A] 応答がないので、パケットは送信されないまま待ち続けます\n";

echo "\n💡 解説:\n";
echo "  ・ARPリクエストはブロードキャストなので、PC-CとPC-Dにも届きます。\n";
echo "  ・応答するのは問い合わせられたPC-Bだけで、ユニキャストでPC-Aに返します。\n";
echo "  ・PC-BはリクエストからPC-Aを学習し、無関係なPC-Cは何も学習しません。\n";
echo "  ・ARPはブロードキャストに頼るので、L2の限界であるフラッディングの一因にもなります。\n\n";

$lab->finish();
