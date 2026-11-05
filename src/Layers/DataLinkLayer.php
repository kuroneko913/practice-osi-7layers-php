<?php

namespace App\Layers;

use App\Layers\DataLinkLayer\EtherType;
use App\Layers\DataLinkLayer\FrameBuilderInterface;
use App\Layers\DataLinkLayer\FrameInterface;
use App\Layers\DataLinkLayer\FrameParserInterface;
use App\Layers\DataLinkLayer\MacAddress;
use Psr\Log\LoggerInterface;

class DataLinkLayer implements LayerInterface
{
    private string $buffer = '';

    /** @var array<string, MacAddress> 受信するマルチキャストグループ (12hex => MAC) */
    private array $multicastGroups = [];

    /** 宛先に関係なくすべてのフレームを受信するか (IEEE 802.3 4.2.9 promiscuous receive) */
    private bool $promiscuous = false;

    /** @var FrameInterface[] 自局宛てに送信し、受信待ちのフレーム */
    private array $loopbackFrames = [];

    /**
     * コンストラクタ
     * @param LayerInterface $lowerLayer
     * @param FrameBuilderInterface $frameBuilder
     * @param FrameParserInterface $frameParser
     * @param MacAddress $myMac 受信判定用の自MACアドレス
     */
    public function __construct(
        private LayerInterface $lowerLayer,
        private FrameBuilderInterface $frameBuilder,
        private FrameParserInterface $frameParser,
        private MacAddress $myMac,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * 改行が来るまでデータを溜めてから1フレームとして送信する
     */
    public function send(mixed $data, ?MacAddress $to = null, EtherType $type = EtherType::LocalExperimental): void
    {
        $this->buffer .= $data;
        if (str_ends_with($this->buffer, "\n")) {
            $payload = rtrim($this->buffer, "\n");
            $this->buffer = '';
            $this->sendFrame($payload, $to, $type);
        }
    }

    /**
     * データをそのまま1フレームとして送信する (バイナリのパケット用)
     */
    public function sendFrame(string $payload, ?MacAddress $to = null, EtherType $type = EtherType::LocalExperimental): void
    {
        $frame = $this->frameBuilder->build($payload, $to ?? MacAddress::broadcast(), $type);
        $this->lowerLayer->send($frame->toWire());

        // 全二重の物理層は折り返さないため、自局宛てのフレームはMACで受信する (IEEE 802.3 4.1.2)
        if ($frame->getDestMac()->equals($this->myMac)) {
            $this->loopbackFrames[] = $frame;
        }
    }

    /**
     * 物理層を読まずに受信できるフレームがあるかどうか
     */
    public function hasPendingFrame(): bool
    {
        return $this->loopbackFrames !== [];
    }

    /**
     * マルチキャストグループに参加する (NICの受信フィルタに登録する)
     */
    public function joinMulticastGroup(MacAddress $group): void
    {
        $this->multicastGroups[$group->toHex()] = $group;
    }

    /**
     * プロミスキャスモードを切り替える
     */
    public function setPromiscuous(bool $promiscuous): void
    {
        $this->promiscuous = $promiscuous;
    }

    /**
     * フレームを受信する。自分宛てでないフレーム、壊れたフレームは破棄する
     * @return FrameInterface|null 受信できなかった場合は null
     */
    public function receive(): ?FrameInterface
    {
        if ($this->hasPendingFrame()) {
            return $this->accept(array_shift($this->loopbackFrames));
        }

        $burst = $this->lowerLayer->receive();
        if ($burst === '') {
            return null;
        }

        $this->logger?->info('Received frame', ['chunk' => $burst]);
        $frame = $this->frameParser->parse($burst);
        if ($frame === null) {
            echo "[NIC] DROP: failed to parse frame\n";
            return null;
        }
        if (!$frame->isValid()) {
            echo "[NIC] DROP: frame validation failed (CRC error)\n";
            return null;
        }

        $destMac = $frame->getDestMac();
        if (!$this->isAcceptable($destMac)) {
            echo "[NIC] DROP: dest ({$destMac->toHex()}) is neither my MAC ({$this->myMac->toHex()}) nor a joined group (packet discarded)\n";
            return null;
        }

        return $this->accept($frame);
    }

    private function accept(FrameInterface $frame): FrameInterface
    {
        $destMac = $frame->getDestMac();
        $destLabel = $destMac->isBroadcast() ? 'BROADCAST' : $destMac->toHex();
        $type = EtherType::fromInt($frame->getEtherType())?->name ?? sprintf('unknown(0x%04X)', $frame->getEtherType());
        echo "\n[NIC ACCEPT] {dest: $destLabel, src: {$frame->getSrcMac()->toHex()}, type: $type, bytes: " . strlen($frame->getPayload()) . "}\n";

        return $frame;
    }

    private function isAcceptable(MacAddress $destMac): bool
    {
        return $this->promiscuous
            || $destMac->equals($this->myMac)
            || $destMac->isBroadcast()
            || isset($this->multicastGroups[$destMac->toHex()]);
    }

    public function getLowerLayer(): LayerInterface
    {
        return $this->lowerLayer;
    }
}
