<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: MACテーブルの溢れ】テーブルが満杯だと、宛先を学習できずフラッディングが続く\n";
echo "════════════════════════════════════════════════════════════════\n";

// 実験のため、テーブルの容量を4エントリに制限する
$tableSize = 4;
$lab = new SwitchLab(switchEnv: ['MAC_TABLE_SIZE' => (string)$tableSize]);

echo "▶ PC-DのNICをプロミスキャスモードにします\n";
$lab->setPromiscuous('D');

echo "▶ PC-Dが偽の送信元MACアドレスで{$tableSize}回送信し、テーブルを埋めます\n";
for ($i = 1; $i <= $tableSize; $i++) {
    $lab->transmitAs('D', new MacAddress(sprintf('02000000000%d', $i)), MacAddress::broadcast(), '偽のフレーム');
}
$lab->settle();

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

echo "▶ PC-A -> PC-B\n";
$lab->send('A', '1通目の秘密', $lab->mac('B'));
$report('A');

echo "▶ PC-B -> PC-A: 通常ならここでPC-Bの居場所を学習します\n";
$lab->send('B', '返信', $lab->mac('A'));
$report('B');

echo "▶ PC-A -> PC-B\n";
$lab->send('A', '2通目の秘密', $lab->mac('B'));
$report('A');

echo "\n💡 解説:\n";
echo "  ・テーブルが満杯なので、PC-AもPC-Bも学習されません。\n";
echo "  ・宛先がずっと未知のままなので、2通目もフラッディングされてPC-Dに読まれました。\n";
echo "  ・flood_sniffing.php では2通目が届かなかったのと比べると、違いが分かります。\n";
echo "  ・偽のエントリはエージングで消えるまで残るので、送り続ければこの状態を維持できます。\n\n";

$lab->finish();
