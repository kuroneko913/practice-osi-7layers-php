<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: ブロードキャスト】全員宛てのフレームは全ポートへ転送される\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

echo "▶ 送信元: PC-A -> 宛先: ALL (FF:FF:FF:FF:FF:FF)\n";
echo "  メッセージ: \"全員集合！\"\n\n";

$lab->send('A', '全員集合！', MacAddress::broadcast());

$received = $lab->observe('A');

echo "\n【各PCのNIC受信結果】\n";
foreach ($received as $name => $res) {
    if ($res['accepted']) {
        echo "  [PC-$name] 📢 【ブロードキャスト受信成功！】: \"{$res['payload']}\"\n";
    } else {
        echo "  [PC-$name] ── 未受信\n";
    }
}

echo "\n💡 解説:\n";
echo "  ・宛先が FF:FF:FF:FF:FF:FF のフレームは、スイッチの学習状態に関係なく全ポートに転送されます。\n";
echo "  ・全員がこのメッセージを受け取ります（ARPリクエストなどに使われます）。\n\n";

$lab->finish();
