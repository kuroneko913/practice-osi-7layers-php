<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\MacAddress;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: エージング】古いエントリが消えると、再びフラッディングで届くようになる\n";
echo "════════════════════════════════════════════════════════════════\n";

// 実験のため、保持時間を標準の300秒から2秒に短縮する
$agingSeconds = 2;
$lab = new SwitchLab(portCount: 5, switchEnv: ['MAC_AGING_SECONDS' => (string)$agingSeconds]);

// 準備: PC-Aの居場所を学習させる
$lab->send('A', '準備', MacAddress::broadcast());
$lab->settle();

$report = function () use ($lab): void {
    foreach ($lab->observe('B') as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] ⭕ 【受信成功！】: \"{$res['payload']}\"\n";
        } elseif ($res['arrived']) {
            echo "  [PC-$name] ⚠️ 【フラッディング発生】フレームが届いたが、NICで破棄 (DROP)\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】\n";
        }
    }
};

echo "▶ PC-AをPort 1からPort 5へ移動します (Port 1の先はハブで、リンクは切れません。何も送信しません)\n";
$lab->movePc('A', 5);

echo "▶ PC-B -> PC-A: エントリが残っている間に送信\n";
$lab->send('B', '保持時間内', $lab->mac('A'));
$report();
echo "  [Port 1] 空きポートに届いたフレーム数: " . $lab->countVacantPortFrames(1, 0) . "\n";

echo "▶ 保持時間 ({$agingSeconds}秒) が過ぎるのを待ちます\n";
usleep(($agingSeconds + 1) * 1000000);

echo "▶ PC-B -> PC-A: エントリが消えた後に送信\n";
$lab->send('B', '保持時間切れの後', $lab->mac('A'));
$report();

echo "\n💡 解説:\n";
echo "  ・エントリが残っている間は、PC-Aが黙っている限りフレームは失われ続けます。\n";
echo "  ・保持時間が過ぎると宛先が未知に戻り、フラッディングでPC-Aまで届きます。\n";
echo "  ・その代わり、無関係なPCにもフレームが流れます。標準の300秒なら、回復まで最大5分かかります。\n\n";

$lab->finish();
