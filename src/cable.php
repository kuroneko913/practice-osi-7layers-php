<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\DuplexPhysicalLayer;
use App\Layers\PhysicalLink;

/**
 * スイッチの2つのポートをつなぐLANケーブル (ストレートケーブルの両端を同じスイッチに挿した状態)
 *
 * 片方のポートからスイッチが送信した信号を、そのままもう片方のポートへ流す。
 * フレームとしては解釈しないので、壊れた信号も含めて素通しする。
 * 同じスイッチの2ポートをつなぐとループになり、ブロードキャストが回り続ける (ブロードキャストストーム)。
 * Ctrl+C でプロセスを終了すると、ケーブルを抜いたのと同じ扱いになる。
 */

$options = getopt('', ['ports:', 'delay-ms:']);

$ports = explode(',', (string)($options['ports'] ?? '5,6'));
if (count($ports) !== 2 || !ctype_digit($ports[0]) || !ctype_digit($ports[1]) || $ports[0] === $ports[1]) {
    echo "使い方: php src/cable.php [--ports=<ポート番号>,<ポート番号>] [--delay-ms=<ミリ秒>]\n";
    echo "        --ports    ケーブルの両端を挿すポート (デフォルト: 5,6)\n";
    echo "        --delay-ms 1フレームを通すごとに待つ時間。ループの様子をゆっくり見たいときに使う (デフォルト: 0)\n";
    echo "        (スイッチは PORT_COUNT=6 などで、ケーブル用のポートを含めて起動してください)\n";
    exit(1);
}
$delayMs = (int)($options['delay-ms'] ?? 0);

$fifoDir = getenv('FIFO_DIR') ?: (dirname(__DIR__, 1) . '/tmp/switch');
if (!is_dir($fifoDir)) {
    echo "⚠️ FIFOの配置ディレクトリがありません。先にスイッチを起動してください: $fifoDir\n";
    exit(1);
}

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║              LANケーブル (Port {$ports[0]} ⇄ Port {$ports[1]})                  ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

// ケーブルの両端をポートに挿す
$ends = [];
foreach ($ports as $port) {
    $link = new PhysicalLink("$fifoDir/port_{$port}.link");
    if (!$link->plugIn()) {
        echo "⚠️ Port $port にはすでに別の機器が接続しています\n";
        exit(1);
    }
    // PCと同じ向きで伝送線をつなぐ (ケーブル側から見た送信線 = スイッチの受信線)
    $phy = new DuplexPhysicalLayer("$fifoDir/port_{$port}_to_switch", "$fifoDir/switch_to_port_{$port}", intervalUs: 0);
    $phy->clear();
    $ends[] = ['port' => (int)$port, 'phy' => $phy, 'link' => $link];
    echo "🔌 Port $port に挿しました\n";
}

echo "────────────────────────────────────────────────────────────\n";
echo "信号を中継中... 同じスイッチにつないでいるので、ブロードキャストを1通送るとループします\n";
echo "Ctrl+C でケーブルを抜きます\n\n";

$total = 0;
$perSecond = [0 => 0, 1 => 0];
$reportAt = microtime(true) + 1.0;

while (true) {
    $read = array_map(fn($end) => $end['phy']->getRxStream(), $ends);
    $write = null;
    $except = null;
    $hasBuffered = $ends[0]['phy']->hasBufferedBurst() || $ends[1]['phy']->hasBufferedBurst();
    $ready = $hasBuffered ? 0 : @stream_select($read, $write, $except, 0, 100000); // 100ms

    if ($hasBuffered || $ready > 0) {
        foreach ([[0, 1], [1, 0]] as [$from, $to]) {
            while (($burst = $ends[$from]['phy']->tryReceive()) !== null) {
                $ends[$to]['phy']->send($burst);
                $perSecond[$from]++;
                $total++;
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
    }

    // 1秒ごとに、ケーブルを通過したフレームの数を表示する
    $now = microtime(true);
    if ($now >= $reportAt) {
        if ($perSecond[0] + $perSecond[1] > 0) {
            printf(
                "[%s] 🔁 Port %d → Port %d: %d フレーム/秒, Port %d → Port %d: %d フレーム/秒 (累計 %d)\n",
                date('H:i:s'),
                $ends[0]['port'], $ends[1]['port'], $perSecond[0],
                $ends[1]['port'], $ends[0]['port'], $perSecond[1],
                $total
            );
        }
        $perSecond = [0 => 0, 1 => 0];
        $reportAt = $now + 1.0;
    }
}
