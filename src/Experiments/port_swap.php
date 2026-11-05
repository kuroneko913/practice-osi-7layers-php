<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: ポートの入れ替え】スイッチの情報が古いと、別のPCへフレームを届けてしまう\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

// 準備: A, B, C の居場所を学習させる
foreach (['A', 'B', 'C'] as $name) {
    $lab->send($name, '準備', MacAddress::broadcast());
    $lab->settle();
}

$report = function () use ($lab): void {
    foreach ($lab->observe('B') as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] ⭕ 受信した内容: \"{$res['payload']}\"\n";
        } elseif ($res['arrived']) {
            echo "  [PC-$name] ⚠️ 自分宛てではないフレームが届いたので、NICで破棄 (DROP)\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
        }
    }
};

echo "▶ PC-B -> PC-A: 通常の状態 (PC-AはPort 1)\n";
$lab->send('B', '1通目', $lab->mac('A'));
$report();

echo "▶ PC-AとPC-Cの接続先を入れ替えます (ハブ越しでリンクは切れません。まだ何も送信しません)\n";
$lab->swapPorts('A', 'C');

echo "▶ PC-B -> PC-A: スイッチはPC-AがPort 1にいると思ったまま\n";
$lab->send('B', '2通目', $lab->mac('A'));
$report();

echo "▶ Port 1にいるPC-CのNICをプロミスキャスモードにして、もう一度 PC-B -> PC-A\n";
$lab->setPromiscuous('C');
$lab->send('B', '3通目', $lab->mac('A'));
$report();

echo "▶ PC-Aが新しい場所から送信し、スイッチのテーブルを更新します\n";
$lab->send('A', 'ここにいます', $lab->mac('B'));
$lab->settle();

echo "▶ PC-B -> PC-A: テーブルの更新後\n";
$lab->send('B', '4通目', $lab->mac('A'));
$report();

echo "\n💡 解説:\n";
echo "  ・スイッチは入れ替えを知らないので、PC-A宛てのフレームを今のPC-Cへ届けてしまいます。\n";
echo "  ・普通のNICは宛先MACアドレスが違うので破棄しますが、これは受信側の善意に頼った防御です。\n";
echo "  ・プロミスキャスモードのNICなら、他人宛ての内容をそのまま読めます。\n";
echo "  ・PC-Aが送信すればテーブルは更新されます。それまでPC-Aには届きません。\n";
echo "  ・ケーブルを抜いて入れ替えた場合は、リンクダウンでテーブルが消えるので、この問題は起きません。\n\n";

$lab->finish();
