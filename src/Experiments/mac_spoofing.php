<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: MACアドレスの詐称】スイッチは送信元を検証しないので、宛先を横取りされる\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

// 準備: A と B の居場所を学習させる
$lab->send('A', '準備', MacAddress::broadcast());
$lab->settle();
$lab->send('B', '準備', MacAddress::broadcast());
$lab->settle();

echo "▶ PC-DのNICをプロミスキャスモードにします\n";
$lab->setPromiscuous('D');

$report = function () use ($lab): void {
    foreach ($lab->observe('B') as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] " . ($name === 'D' ? '👀 盗聴した内容' : '⭕ 受信した内容') . ": \"{$res['payload']}\"\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
        }
    }
};

echo "▶ PC-B -> PC-A: 通常の状態\n";
$lab->send('B', '1通目', $lab->mac('A'));
$report();

echo "▶ PC-Dが送信元をPC-AのMACアドレスと偽って送信します\n";
$lab->transmitAs('D', $lab->mac('A'), $lab->mac('B'), '偽のフレーム');
$lab->settle();

echo "▶ PC-B -> PC-A: 詐称の後\n";
$lab->send('B', '2通目', $lab->mac('A'));
$report();

echo "▶ 本物のPC-Aが送信します\n";
$lab->send('A', '本物', $lab->mac('B'));
$lab->settle();

echo "▶ PC-B -> PC-A: 本物のPC-Aの送信の後\n";
$lab->send('B', '3通目', $lab->mac('A'));
$report();

echo "\n💡 解説:\n";
echo "  ・スイッチは送信元MACアドレスをそのまま信じて学習するため、偽のフレーム1つでテーブルが書き換わります。\n";
echo "  ・その間、PC-A宛てのフレームはPC-Dへ転送され、PC-Aには届きません。\n";
echo "  ・本物のPC-Aが送信すると書き戻されますが、次の詐称でまた奪われます。\n\n";

$lab->finish();
