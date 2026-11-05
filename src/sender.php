<?php

namespace App;

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use App\Layers\LayerInterface;
use App\Layers\PhysicalLayer;
use App\Layers\DataLinkLayer\Factory as DataLinkLayerFactory;
use App\Layers\DataLinkLayer\MacAddress;
use App\Layers\NetworkLayer;
use App\Layers\NetworkLayer\IpAddress;
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

echo "start sender ($senderMac)\n\n";

// 各層のインスタンスを作成
$cablePath = getenv('FIFO_PATH') ?? null;
$physicalLayer = new PhysicalLayer($cablePath, 'w');
$dataLinkLayer = DataLinkLayerFactory::createEthernet($physicalLayer, from:new MacAddress($senderMac), logger:$logger);
$receiverIp = new IpAddress('192.168.1.2');
$networkLayer = new NetworkLayer($dataLinkLayer, new IpAddress(getenv('IPADDRESS')), new MacAddress($senderMac), defaultDestination: $receiverIp);
// 単方向の伝送線ではARPの応答を受け取れないので、宛先のMACアドレスを静的に登録する
$networkLayer->addStaticArpEntry($receiverIp, new MacAddress('aabbccddee02'));
$transportLayer = new TransportLayer($networkLayer);
$sessionLayer = new SessionLayer($transportLayer);
$presentationLayer = new PresentationLayer($sessionLayer);
$applicationLayer = new ApplicationLayer($presentationLayer);

// 送信側のインスタンスを作成
$sender = new Sender($applicationLayer);
$sender->run();
echo "end sender\n";
