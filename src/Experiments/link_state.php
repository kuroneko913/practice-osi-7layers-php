<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: リンク状態】ケーブルを抜くと、スイッチは転送をやめて学習した内容を忘れる\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

$report = function (string $sender) use ($lab): void {
    foreach ($lab->observe($sender) as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] ⭕ 【受信成功！】: \"{$res['payload']}\"\n";
        } elseif ($res['arrived']) {
            echo "  [PC-$name] ⚠️ フレームが届いたが、NICで破棄 (DROP)\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
        }
    }
};

// 準備: PC-Aの居場所を学習させる
$lab->send('A', '準備', MacAddress::broadcast());
$lab->settle();

echo "▶ PC-Cのケーブルを抜き、PC-B -> ALL へブロードキャスト\n";
$lab->unplug('C');
$lab->send('B', 'PC-Cがいない間のメッセージ', MacAddress::broadcast());
$report('B');

echo "▶ PC-Cのケーブルを挿し直す (抜いている間のメッセージは受け取りません)\n";
$lab->plugBack('C');
$report('B');

echo "▶ PC-Aのケーブルを抜き、PC-B -> PC-A へ送信\n";
$lab->unplug('A');
$lab->send('B', 'PC-Aがいない間のメッセージ', $lab->mac('A'));
$report('B');

echo "▶ PC-Aのケーブルを挿し直し、PC-A -> PC-B へ送信\n";
$lab->plugBack('A');
$lab->send('A', 'ただいま', $lab->mac('B'));
$report('A');

echo "▶ PC-B -> PC-A へ送信\n";
$lab->send('B', 'おかえり', $lab->mac('A'));
$report('B');

echo "\n💡 解説:\n";
echo "  ・リンクダウンしたポートにはフレームを転送しないので、PC-Cには何も残りません。\n";
echo "  ・リンクダウンすると、そのポートで学習したMACアドレスを忘れます。\n";
echo "  ・そのためPC-A宛ては未知の宛先に戻り、誰もいないポートへ転送し続ける問題が起きません。\n";
echo "  ・リンクが切れないハブ越しの移動では、この仕組みは働きません (station_move.php)。\n\n";

$lab->finish();
