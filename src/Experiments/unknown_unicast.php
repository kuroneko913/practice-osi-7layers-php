<?php

namespace App\Experiments;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: 未知ユニキャスト】宛先を知らないスイッチはフラッディングする\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

echo "▶ 送信元: PC-A -> 宛先: PC-B ({$lab->mac('B')->format()})\n";
echo "  メッセージ: \"こんにちはBさん！\"\n\n";

$lab->send('A', 'こんにちはBさん！', $lab->mac('B'));

$received = $lab->observe('A');

echo "\n【各PCのNIC受信結果】\n";
foreach ($received as $name => $res) {
    if ($res['accepted']) {
        echo "  [PC-$name] ⭕ 【受信成功！】: \"{$res['payload']}\"\n";
    } elseif ($res['arrived']) {
        echo "  [PC-$name] ⚠️ 【フラッディング発生】フレームが届いたが、自分宛てではないためNICで破棄 (DROP)\n";
    } else {
        echo "  [PC-$name] 🛡️ フレーム未達 (届いていません)\n";
    }
}

echo "\n💡 解説:\n";
echo "  ・スイッチはPC-AのMACをPort 1として学習しました。\n";
echo "  ・しかし宛先のPC-Bがどのポートにいるか分からないため、\n";
echo "    受信元(Port 1)以外の全ポート (Port 2, 3, 4) にフレームを【フラッディング】しました！\n";
echo "  ・そのため、無関係なPC-CやPC-Dにもフレームが届き、NICで破棄する無駄な処理が発生しました。\n\n";

$lab->finish();
