<?php

namespace App\Experiments;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: フラッディングの盗聴】フィルタを外したNICは、流れてきたフレームをすべて読める\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

echo "▶ PC-DのNICをプロミスキャスモードにします\n";
$lab->setPromiscuous('D');

$report = function (string $sender) use ($lab): void {
    foreach ($lab->observe($sender) as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] " . ($name === 'D' ? '👀 盗聴した内容' : '⭕ 受信した内容') . ": \"{$res['payload']}\"\n";
        } elseif ($res['arrived']) {
            echo "  [PC-$name] ⚠️ フレームが届いたが、NICで破棄 (DROP)\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
        }
    }
};

echo "▶ PC-A -> PC-B: スイッチはPC-Bの居場所をまだ知りません\n";
$lab->send('A', '1通目の秘密', $lab->mac('B'));
$report('A');

echo "▶ PC-B -> PC-A: 返信によりスイッチがPC-Bの居場所を学習します\n";
$lab->send('B', '返信', $lab->mac('A'));
$report('B');

echo "▶ PC-A -> PC-B: スイッチはPC-Bの居場所を知っています\n";
$lab->send('A', '2通目の秘密', $lab->mac('B'));
$report('A');

echo "\n💡 解説:\n";
echo "  ・NICの宛先フィルタはそのPC自身の設定なので、外せば届いたフレームをすべて読めます。\n";
echo "  ・未知の宛先へのフレームはフラッディングされるため、PC-Dにも1通目の内容が読まれました。\n";
echo "  ・学習後の2通目はピンポイント転送なので、PC-Dには届きません。\n";
echo "  ・スイッチは暗号化しません。内容を守るには、上位層で暗号化する必要があります。\n\n";

$lab->finish();
