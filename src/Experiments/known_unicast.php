<?php

namespace App\Experiments;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: 学習済みユニキャスト】宛先を知っているスイッチはピンポイント転送する\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

// 準備: PC-Aに送信させて、スイッチにPC-Aの居場所を学習させる
echo "▶ 準備: PC-A -> PC-B へ送信し、スイッチにPC-Aの居場所を学習させます\n";
$lab->send('A', 'こんにちはBさん！', $lab->mac('B'));
$lab->settle();

echo "▶ 送信元: PC-B -> 宛先: PC-A ({$lab->mac('A')->format()})\n";
echo "  メッセージ: \"Aさん、届いたよ！\"\n\n";

$lab->send('B', 'Aさん、届いたよ！', $lab->mac('A'));

$received = $lab->observe('B');

echo "\n【各PCのNIC受信結果】\n";
foreach ($received as $name => $res) {
    if ($res['accepted']) {
        echo "  [PC-$name] ⭕ 【受信成功！】: \"{$res['payload']}\"\n";
    } elseif ($res['arrived']) {
        echo "  [PC-$name] ⚠️ 【フラッディング発生】フレームが届いたが、NICで破棄 (DROP)\n";
    } else {
        echo "  [PC-$name] 🛡️ 【フレーム非到達】無駄なトラフィックなし！(帯域節約 & 盗聴防止)\n";
    }
}

echo "\n💡 解説:\n";
echo "  ・スイッチはPC-BのMACをPort 2として学習しました。\n";
echo "  ・宛先のPC-AはすでにPort 1にいると知っているため、【Port 1にだけピンポイント転送】しました！\n";
echo "  ・PC-CやPC-Dにはフレームが一切流れず、ネットワーク帯域が節約されました。\n\n";

$lab->finish();
