<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: PCの移動】移動した本人が送信するまで、スイッチは移動を知らない\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab(portCount: 5);

// 準備: A と B の居場所を学習させる
$lab->send('A', '準備', MacAddress::broadcast());
$lab->settle();
$lab->send('B', '準備', MacAddress::broadcast());
$lab->settle();

$reportAtA = function (string $sender = 'B') use ($lab): void {
    $res = $lab->observe($sender)['A'];
    echo $res['accepted'] ? "  [PC-A] ⭕ 【受信成功！】: \"{$res['payload']}\"\n" : "  [PC-A] ❌ 届いていません\n";
};

echo "▶ PC-AをPort 1からPort 5へ移動します (Port 1の先はハブで、リンクは切れません。まだ何も送信しません)\n";
$lab->movePc('A', 5);

echo "▶ PC-B -> PC-A: 移動を知らないスイッチへ送信\n";
$lab->send('B', '移動の直後', $lab->mac('A'));
$reportAtA();
echo "  [Port 1] 空きポートに届いたフレーム数: " . $lab->countVacantPortFrames(1, 0) . "\n";

echo "▶ PC-A -> PC-B: 移動先のPort 5からPC-Aが送信\n";
$lab->send('A', '引っ越しました', $lab->mac('B'));
$lab->settle();

echo "▶ PC-B -> PC-A: もう一度送信\n";
$lab->send('B', 'PC-Aの送信の後', $lab->mac('A'));
$reportAtA();
echo "  [Port 1] 空きポートに届いたフレーム数: " . $lab->countVacantPortFrames(1, 0) . "\n";

echo "\n💡 解説:\n";
echo "  ・スイッチが学習するのは受信したフレームの送信元だけなので、黙って移動したPCの居場所は分かりません。\n";
echo "  ・移動の直後は、誰もいないPort 1へ転送され続けてフレームが失われます。\n";
echo "  ・PC-Aが移動先から1回送信すると、テーブルが更新されて届くようになります。\n\n";

$lab->finish();
