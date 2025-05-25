<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\LayerInterface;
use App\Layers\PhysicalLayer;
use App\Layers\DataLinkLayer\Factory as DataLinkLayerFactory;
use App\Layers\NetworkLayer\Factory as NetworkLayerFactory;
use App\Layers\TransportLayer;
use App\Layers\SessionLayer;
use App\Layers\PresentationLayer;
use App\Layers\ApplicationLayer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Sender
{
    /**
     * @var LayerInterface
     */
    private $layer;

    public function __construct(LayerInterface $layer)
    {
        $this->layer = $layer;
    }

    public function run()
    {
        // 標準入力からデータを受け取り、FIFOに1bitずつ書き込む
        $input = trim(fgets(STDIN));
        for ($i = 0; $i < strlen($input); $i++) {
            $this->log($input[$i]);
            $this->layer->send($input[$i]);
        }
        $this->layer->send("\n");
    }

    private function log($message)
    {
        $now = microtime(true);
        $formatted = date("Y-m-d H:i:s") . sprintf(".%03d", ($now - floor($now)) * 1000);
        echo "[SEND] $message at $formatted\n";
    }
}

// ログディレクトリの作成
$logDir = dirname(__DIR__, 1) . '/logs';
if (!is_dir($logDir)) {
    echo "ログディレクトリが存在しません。作成します。\n";
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/sender.log';
$logger = new Logger('sender');
$logger->pushHandler(new StreamHandler($logFile, Logger::INFO));

// 別なレイヤーで追加されるはずだが今はここで設定
// 自分のMACアドレス
$senderMac = getenv('MACADDRESS') ?? null;
// 宛先MACアドレス
$receiverMac = 'aabbccddee02';
$type = '0x0800'; // デフォルトのIPプロトコル
$senderIp = getenv('IPADDRESS') ?? null;
$receiverIp = '192.168.1.2';

echo "start sender ($senderMac)\n\n";

// 各層のインスタンスを作成
$cablePath = getenv('FIFO_PATH') ?? null;
$physicalLayer = new PhysicalLayer($cablePath, 'w');
$dataLinkLayer = DataLinkLayerFactory::createEthernet($physicalLayer, type:$type, to:$receiverMac, from:$senderMac, logger:$logger);
$networkLayer = NetworkLayerFactory::createStringIPPacket(lowerLayer:$dataLinkLayer, senderIp:$senderIp, receiverIp:$receiverIp, logger:$logger);
$transportLayer = new TransportLayer($networkLayer);
$sessionLayer = new SessionLayer($transportLayer);
$presentationLayer = new PresentationLayer($sessionLayer);
$applicationLayer = new ApplicationLayer($presentationLayer);

// 送信側のインスタンスを作成
$sender = new Sender($applicationLayer);
$sender->run();
echo "end sender\n";
