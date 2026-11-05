<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\DuplexPhysicalLayer;
use App\Layers\PhysicalLink;
use App\Layers\DataLinkLayer\Factory as DataLinkLayerFactory;
use App\Layers\DataLinkLayer\MacAddress;
use App\Layers\DataLinkLayer;

// コマンドライン引数をパース
$options = getopt('', ['config:', 'ethers:', 'name:', 'port:', 'mac:', 'to:', 'message:', 'promiscuous']);

$config = isset($options['config']) ? loadNodeConfig($options['config']) : [];

// 自分のMACアドレスと接続ポートは起動時に外部から与える
// 優先順位は 引数 > 環境変数 > 設定ファイル (getenv は未設定時に false を返す)
$port = (string)($options['port'] ?? (getenv('PORT') ?: ($config['port'] ?? '')));
$mac = MacAddress::tryFromHex((string)($options['mac'] ?? (getenv('MACADDRESS') ?: ($config['mac'] ?? ''))));
if (!ctype_digit($port) || $mac === null) {
    echo "使い方: php src/node.php --config=<設定ファイル> [--to=<宛先> --message=<メッセージ>]\n";
    echo "        php src/node.php --port=<ポート番号> --mac=<12桁のMACアドレス> [--name=<表示名>] [--ethers=<ethersファイル>] [--promiscuous]\n";
    echo "        (環境変数 PORT, MACADDRESS, NODE_NAME でも指定できます)\n";
    exit(1);
}

$name = $options['name'] ?? (getenv('NODE_NAME') ?: ($config['name'] ?? "Port{$port}"));

// 宛先の名前解決に使うアドレス帳 (省略可)
$ethersPath = $options['ethers'] ?? $config['ethers'] ?? null;
$ethers = $ethersPath === null ? [] : loadEthers($ethersPath);

$fifoDir = getenv('FIFO_DIR') ?: (dirname(__DIR__, 1) . '/tmp/switch');
$txPath = $fifoDir . "/port_{$port}_to_switch";
$rxPath = $fifoDir . "/switch_to_port_{$port}";

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║                     PC ノード [$name]                      ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "  名前:       PC-$name\n";
echo "  接続ポート: Port $port\n";
echo "  自MAC:      " . $mac->format() . " ({$mac->toHex()})\n";
echo "  Tx伝送線:   $txPath\n";
echo "  Rx伝送線:   $rxPath\n";
echo "  アドレス帳: " . ($ethersPath ?? '(なし)') . "\n";
echo "────────────────────────────────────────────────────────────\n";

// ケーブルを接続する
$link = new PhysicalLink("$fifoDir/port_{$port}.link");
if (!$link->plugIn()) {
    echo "⚠️ Port $port にはすでに別のPCが接続しています\n";
    exit(1);
}

// 全二重物理層の初期化
$physicalLayer = new DuplexPhysicalLayer($txPath, $rxPath);
// 接続前に伝送線に残っていたデータは受け取らない
$physicalLayer->clear();

// データリンク層の初期化 (自分のMACアドレスのみを登録)
$dataLinkLayer = DataLinkLayerFactory::createEthernet(
    lowerLayer: $physicalLayer,
    from: $mac
);
if (isset($options['promiscuous'])) {
    echo "👀 プロミスキャスモード: 宛先に関係なくすべてのフレームを受信します\n";
    $dataLinkLayer->setPromiscuous(true);
}

// ワンショット送信モード（引数で --to と --message が渡された場合）
if (isset($options['to']) && isset($options['message'])) {
    $targetInput = strtoupper($options['to']);
    $destination = resolveDestination($options['to'], $ethers);
    $message = $options['message'];

    if ($destination === null) {
        echo "⚠️ 宛先が正しくありません。ALL、アドレス帳の名前、または12桁のMACアドレスを指定してください。(入力: {$options['to']})\n";
        exit(1);
    }

    sendMessage($dataLinkLayer, $destination, $message, $targetInput);
    exit(0);
}

// 対話型＆常時受信モード
echo "【使い方】\n";
echo "  誰かに送信: [宛先] [メッセージ]\n";
echo "    例: aabbccddee0b こんにちは！  (指定したMACアドレス宛てにユニキャスト)\n";
echo "    例: B こんにちは！             (アドレス帳に登録した名前宛てにユニキャスト)\n";
echo "    例: ALL 全員聞いて！       (全員宛てにブロードキャスト)\n";
echo "  終了: quit\n\n";
echo "[常時受信待機中...] 入力してください > \n";

// STDINをノンブロッキングに設定
stream_set_blocking(STDIN, false);
$rxStream = $physicalLayer->getRxStream();

$stdinBuffer = '';

while (true) {
    $readStreams = [STDIN];
    if (is_resource($rxStream)) {
        $readStreams[] = $rxStream;
    }

    $write = null;
    $except = null;
    $ready = @stream_select($readStreams, $write, $except, 0, 50000); // 50ms

    // 自分宛てに送信したフレームと、読み込み済みのフレームは伝送線の監視では検知できない
    $hasPendingFrame = $dataLinkLayer->hasPendingFrame() || $physicalLayer->hasBufferedBurst();

    if (!$hasPendingFrame && ($ready === false || $ready === 0)) {
        continue;
    }

    // 1. スイッチからのフレーム受信チェック
    if ($hasPendingFrame || (is_resource($rxStream) && in_array($rxStream, $readStreams, true))) {
        // データリンク層でフレームを受信
        $frame = $dataLinkLayer->receive();
        // 上位のプロトコルがまだないので、ここでパディングを除去する
        $receivedPayload = $frame === null ? '' : rtrim($frame->getPayload(), "\0");
        if ($receivedPayload !== '') {
            printReceivedMessage($frame->getSrcMac()->format(), $receivedPayload);
        }
    }

    // 2. キーボード入力のチェック
    if (in_array(STDIN, $readStreams, true)) {
        $input = fgets(STDIN);
        if ($input === false) {
            continue;
        }

        $line = trim($input);
        if ($line === 'quit' || $line === 'exit') {
            echo "終了します。\n";
            break;
        }

        if ($line === '') {
            echo "> ";
            continue;
        }

        $parts = explode(' ', $line, 2);
        $targetInput = strtoupper($parts[0]);
        $message = $parts[1] ?? 'Hello!';

        // フォーマット検証
        $destination = resolveDestination($parts[0], $ethers);
        if ($destination === null) {
            echo "⚠️ 宛先が正しくありません。ALL、アドレス帳の名前、または12桁のMACアドレスを指定してください。(入力: {$parts[0]})\n";
            echo "> ";
            continue;
        }

        sendMessage($dataLinkLayer, $destination, $message, $targetInput);
        echo "> ";
    }
}

/**
 * ノードの設定ファイル (JSON) を読み込む
 * ethers のパスは設定ファイルからの相対パスとして解決する
 *
 * @return array{name?: string, port?: int, mac?: string, ethers?: string}
 */
function loadNodeConfig(string $path): array
{
    $json = @file_get_contents($path);
    $config = $json === false ? null : json_decode($json, true);
    if (!is_array($config)) {
        echo "⚠️ 設定ファイルを読み込めません: $path\n";
        exit(1);
    }

    if (isset($config['ethers']) && !str_starts_with($config['ethers'], '/')) {
        $config['ethers'] = dirname($path) . '/' . $config['ethers'];
    }
    return $config;
}

/**
 * ethers ファイル (<MACアドレス> <名前>) を読み込む
 *
 * @return array<string, MacAddress> 大文字の名前 => MACアドレス
 */
function loadEthers(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        echo "⚠️ ethersファイルを読み込めません: $path\n";
        exit(1);
    }

    $ethers = [];
    foreach ($lines as $i => $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line === '') {
            continue;
        }
        $fields = preg_split('/\s+/', $line);
        $mac = MacAddress::tryFromHex(str_replace(':', '', $fields[0]));
        if ($mac === null || count($fields) !== 2) {
            echo "⚠️ ethersファイルの形式が不正です: $path:" . ($i + 1) . "\n";
            exit(1);
        }
        $ethers[strtoupper($fields[1])] = $mac;
    }
    return $ethers;
}

/**
 * 入力された宛先をMACアドレスに変換する。不正な場合は null を返す
 *
 * @param array<string, MacAddress> $ethers
 */
function resolveDestination(string $input, array $ethers): ?MacAddress
{
    $key = strtoupper($input);
    if ($key === 'ALL' || $key === 'BROADCAST') {
        return MacAddress::broadcast();
    }
    return $ethers[$key] ?? MacAddress::tryFromHex($input);
}

function printReceivedMessage(string $sender, string $payload): void
{
    $timestamp = date('H:i:s');
    echo "\n🔔 [$timestamp] 【メッセージ受信成功！】 from $sender: \"$payload\"\n";
    echo "> ";
}

function sendMessage(DataLinkLayer $layer, MacAddress $destMac, string $message, string $targetLabel): void
{
    echo "📤 [SEND] 送信中 -> $targetLabel ({$destMac->format()}): \"$message\"\n";

    // 宛先MACを引数で直接渡してフレーム送信 (ステートレス)
    $layer->send($message . "\n", $destMac);
    echo "   └─ 送信完了！\n";
}
