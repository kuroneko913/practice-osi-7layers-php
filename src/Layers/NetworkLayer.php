<?php

namespace App\Layers;

use App\Layers\DataLinkLayer\EtherType;
use App\Layers\DataLinkLayer\FrameInterface;
use App\Layers\DataLinkLayer\MacAddress;
use App\Layers\NetworkLayer\ArpPacket;
use App\Layers\NetworkLayer\ArpTable;
use App\Layers\NetworkLayer\IpAddress;
use App\Layers\NetworkLayer\Ipv4Packet;

/**
 * ネットワーク層
 * 宛先IPアドレスのMACアドレスをARPで解決し、IPv4パケットを送受信する
 * ルーティングは実装していないので、全ての宛先が同じネットワークにいるものとして扱う
 */
class NetworkLayer implements LayerInterface
{
    /** ARPリクエストの最小間隔 (RFC 1122 2.3.2.1 の推奨値) */
    private const ARP_REQUEST_INTERVAL_SECONDS = 1.0;

    private string $buffer = '';

    private int $nextIdentification = 0;

    private ArpTable $arpTable;

    /** @var array<string, Ipv4Packet> ARPの解決を待っているパケット (宛先ごとに最新の1つを保持) */
    private array $pendingPackets = [];

    /** @var array<string, float> 宛先ごとの最後のARPリクエストの時刻 */
    private array $lastArpRequestAt = [];

    /** @var Ipv4Packet[] 自分のIPアドレス宛てに送信し、受信待ちのパケット */
    private array $localPackets = [];

    /**
     * @param IpAddress|null $defaultDestination send() の宛先 (上位層から宛先を受け取れないため)
     * @param int $ttl 送信するパケットのTTL
     * @param float $arpEntrySeconds ARPキャッシュの保持時間
     */
    public function __construct(
        private DataLinkLayer $lowerLayer,
        private IpAddress $myIp,
        private MacAddress $myMac,
        private ?IpAddress $defaultDestination = null,
        private int $ttl = 64,
        float $arpEntrySeconds = 60.0,
    ) {
        $this->arpTable = new ArpTable($arpEntrySeconds);
        $this->arpTable->addStatic($myIp, $myMac);
    }

    /**
     * 静的ARPエントリを登録する (ARPの応答を受け取れない単方向の伝送線などで使う)
     */
    public function addStaticArpEntry(IpAddress $ip, MacAddress $mac): void
    {
        $this->arpTable->addStatic($ip, $mac);
    }

    public function getArpTable(): ArpTable
    {
        return $this->arpTable;
    }

    /**
     * ARPの解決を待っているパケットがあるかどうか
     */
    public function hasPendingPackets(): bool
    {
        return $this->pendingPackets !== [];
    }

    /**
     * 自分のIPアドレス宛てで受信待ちのパケットがあるかどうか
     */
    public function hasLocalPackets(): bool
    {
        return $this->localPackets !== [];
    }

    /**
     * 改行が来るまでデータを溜めてから1パケットとして宛先に送信する
     */
    public function send(mixed $data): void
    {
        $this->buffer .= $data;
        if (str_ends_with($this->buffer, "\n")) {
            if ($this->defaultDestination === null) {
                throw new \RuntimeException('宛先のIPアドレスが設定されていません');
            }
            $payload = rtrim($this->buffer, "\n");
            $this->buffer = '';
            $this->sendPacket($payload, $this->defaultDestination);
        }
    }

    /**
     * IPv4パケットを送信する。MACアドレスが不明ならARPで解決してから送信する
     */
    public function sendPacket(string $payload, IpAddress $to, int $protocol = Ipv4Packet::PROTOCOL_EXPERIMENTAL): void
    {
        $packet = new Ipv4Packet($this->myIp, $to, $protocol, $payload, $this->nextIdentification, $this->ttl);
        $this->nextIdentification = ($this->nextIdentification + 1) & 0xFFFF;

        // 自分宛てのパケットはネットワークに流さず、この層で折り返す
        if ($to->equals($this->myIp)) {
            $this->localPackets[] = $packet;
            return;
        }

        if ($to->isBroadcast()) {
            $this->transmit($packet, MacAddress::broadcast());
            return;
        }

        $mac = $this->arpTable->lookup($to);
        if ($mac !== null) {
            $this->transmit($packet, $mac);
            return;
        }

        $this->pendingPackets[(string)$to] = $packet;
        $this->requestArp($to);
    }

    private function transmit(Ipv4Packet $packet, MacAddress $to): void
    {
        $this->lowerLayer->sendFrame($packet->toBytes(), $to, EtherType::IPv4);
    }

    private function requestArp(IpAddress $target): void
    {
        $key = (string)$target;
        $now = microtime(true);
        if ($now - ($this->lastArpRequestAt[$key] ?? 0.0) < self::ARP_REQUEST_INTERVAL_SECONDS) {
            return;
        }
        $this->lastArpRequestAt[$key] = $now;

        echo "[ARP] Who has $target? Tell {$this->myIp}\n";
        $request = ArpPacket::request($this->myMac, $this->myIp, $target);
        $this->lowerLayer->sendFrame($request->toBytes(), MacAddress::broadcast(), EtherType::ARP);
    }

    /**
     * フレームを受信し、EtherTypeに応じて処理する
     * @return Ipv4Packet|null 自分宛てのIPv4パケット。それ以外は null
     */
    public function receive(): ?Ipv4Packet
    {
        $local = $this->receiveLocal();
        if ($local !== null) {
            return $local;
        }

        $frame = $this->lowerLayer->receive();
        return $frame === null ? null : $this->handleFrame($frame);
    }

    /**
     * 自分のIPアドレス宛てに送信したパケットを取り出す。下位層は読まない
     */
    public function receiveLocal(): ?Ipv4Packet
    {
        return array_shift($this->localPackets);
    }

    /**
     * 受信済みのフレームをEtherTypeに応じて処理する
     * @return Ipv4Packet|null 自分宛てのIPv4パケット。それ以外は null
     */
    public function handleFrame(FrameInterface $frame): ?Ipv4Packet
    {
        switch (EtherType::fromInt($frame->getEtherType())) {
            case EtherType::ARP:
                $this->handleArp($frame->getPayload());
                return null;
            case EtherType::IPv4:
                return $this->handleIpv4($frame->getPayload());
            default:
                return null;
        }
    }

    /**
     * ARPパケットの受信処理 (RFC 826 Packet Reception)
     */
    private function handleArp(string $bytes): void
    {
        $arp = ArpPacket::parse($bytes);
        if ($arp === null || $arp->getSenderIp()->equals($this->myIp)) {
            return;
        }

        // すでに知っている相手なら、宛先に関係なく最新の情報に更新する
        $senderIp = $arp->getSenderIp();
        $merged = $this->arpTable->lookup($senderIp) !== null;
        if ($merged) {
            $this->arpTable->learn($senderIp, $arp->getSenderMac());
        }

        if ($arp->getTargetIp()->equals($this->myIp)) {
            if (!$merged) {
                $this->arpTable->learn($senderIp, $arp->getSenderMac());
            }
            if ($arp->isRequest()) {
                echo "[ARP] {$this->myIp} is at {$this->myMac->format()} (Reply to $senderIp)\n";
                $reply = new ArpPacket(ArpPacket::OPERATION_REPLY, $this->myMac, $this->myIp, $arp->getSenderMac(), $senderIp);
                $this->lowerLayer->sendFrame($reply->toBytes(), $arp->getSenderMac(), EtherType::ARP);
            }
        }

        $this->flushPendingPacket($senderIp);
    }

    /**
     * MACアドレスが分かった宛先へ、待たせていたパケットを送信する
     */
    private function flushPendingPacket(IpAddress $ip): void
    {
        $key = (string)$ip;
        $mac = $this->arpTable->lookup($ip);
        if ($mac !== null && isset($this->pendingPackets[$key])) {
            $this->transmit($this->pendingPackets[$key], $mac);
            unset($this->pendingPackets[$key]);
        }
    }

    private function handleIpv4(string $bytes): ?Ipv4Packet
    {
        $packet = Ipv4Packet::parse($bytes);
        if ($packet === null) {
            echo "[IP] DROP: invalid packet\n";
            return null;
        }

        $destination = $packet->getDestination();
        if (!$destination->equals($this->myIp) && !$destination->isBroadcast()) {
            echo "[IP] DROP: dest ($destination) is not my IP ({$this->myIp})\n";
            return null;
        }

        return $packet;
    }
}
