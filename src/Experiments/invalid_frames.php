<?php

namespace App\Experiments;

use App\Layers\DataLinkLayer\EtherType;
use App\Layers\DataLinkLayer\EthernetFrame;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "════════════════════════════════════════════════════════════════\n";
echo "【観点: 不正なフレーム】ストア＆フォワードのスイッチは壊れたフレームを転送しない\n";
echo "════════════════════════════════════════════════════════════════\n";

$lab = new SwitchLab();

$frame = new EthernetFrame($lab->mac('B'), $lab->mac('A'), EtherType::LocalExperimental->toInt(), str_pad('hello', EthernetFrame::MIN_PAYLOAD_BYTES, "\0"));
$signal = $frame->toWire();

// ペイロードの先頭1ビットを反転 (プリアンブル+SFD 64ビット + ヘッダ 112ビットの次)
$corrupted = $signal;
$corrupted[176] = $corrupted[176] === '0' ? '1' : '0';

$cases = [
    '正常なフレーム' => $signal,
    '伝送中に1ビット壊れたフレーム (FCSエラー)' => $corrupted,
    '最小フレーム長より短いフレーム' => substr($signal, 0, -16),
];

foreach ($cases as $label => $case) {
    echo "▶ PC-A -> PC-B: $label\n";
    $lab->transmit('A', $case);
    foreach ($lab->observe('A') as $name => $res) {
        if ($res['accepted']) {
            echo "  [PC-$name] ⭕ 【受信成功！】: \"{$res['payload']}\"\n";
        } elseif ($res['arrived']) {
            echo "  [PC-$name] ⚠️ フレームが届いたが、NICで破棄 (DROP)\n";
        } else {
            echo "  [PC-$name] 🛡️ 【フレーム非到達】スイッチで破棄されました\n";
        }
    }
}

echo "\n💡 解説:\n";
echo "  ・スイッチはフレーム全体を受信してからFCSを検査するため、壊れたフレームをどのポートにも転送しません。\n";
echo "  ・最小フレーム長に満たないフレームも、フレームとして解釈できないため破棄されます。\n";
echo "  ・データリンク層は破棄するだけで再送はしません。回復は上位層の役割です。\n\n";

$lab->finish();
