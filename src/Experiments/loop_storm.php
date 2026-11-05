<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: ループ】TTLのないフレームは、ループの中を回り続ける\n";
echo "════════════════════════════════════════════════════════════════\n";

// PC用の4ポートに加えて、ケーブルをつなぐ2ポートを用意する
$lab = new SwitchLab(portCount: 6);

echo "▶ Port 5とPort 6をケーブルでつなぎ、ループを作ります\n";
$cable = $lab->plugLoopCable(5, 6);

echo "▶ PC-Aがブロードキャストを1通だけ送信します\n";
$lab->send('A', 'ぐるぐる', MacAddress::broadcast());

$seconds = 2.0;
echo "▶ {$seconds}秒間、ケーブルの信号を流します\n";
$storm = $lab->relayLoopCable($cable, $seconds);

echo "\n【{$seconds}秒間の結果 (PC-Aの送信は1通だけ)】\n";
echo "  ケーブルを通過したフレーム: {$storm['relayed']} 回\n";
foreach ($storm['received'] as $name => $count) {
    echo "  [PC-$name] 受信したコピー: $count 個\n";
}
echo "  スイッチが処理したフレーム: " . $lab->countSwitchLogLines('フレーム受信') . " 個\n";
echo "  PC-AのMACアドレスの移動: " . $lab->countSwitchLogLines('[MAC移動]') . " 回\n";

echo "\n▶ ケーブルを抜きます\n";
foreach ($cable as $end) {
    $end['link']->unplug();
}
$lab->settle(500);
$before = $lab->countSwitchLogLines('フレーム受信');
usleep(500000);
echo "  ケーブルを抜いた後の0.5秒で処理したフレーム: " . ($lab->countSwitchLogLines('フレーム受信') - $before) . " 個\n";

echo "\n💡 解説:\n";
echo "  ・ブロードキャストはPort 5とPort 6の両方へ流れ、ケーブルを通って戻ると再びフラッディングされます。\n";
echo "  ・Ethernetのヘッダには TTL がないので、フレームが自然に消えることはありません。\n";
echo "  ・戻ってきたフレームの送信元はPC-Aのままなので、スイッチはPC-Aが移動したと誤解します (MACフラッピング)。\n";
echo "  ・IPにはTTLがあるので、ルーターを通るたびに減って、いずれ破棄されます。\n";
echo "  ・実機ではSTP (スパニングツリー) などでポートを遮断して、ループを防ぎます。\n\n";

$lab->finish(lastLines: 25);
