<?php

namespace App\Layers\NetworkLayer;

use App\Layers\DataLinkLayer\MacAddress;

/**
 * IPv4 over Ethernet のARPパケットを表現する (RFC 826)
 */
class ArpPacket
{
    public const OPERATION_REQUEST = 1;
    public const OPERATION_REPLY = 2;

    private const HARDWARE_ETHERNET = 1;
    private const PROTOCOL_IPV4 = 0x0800;
    private const HARDWARE_ADDRESS_BYTES = 6;
    private const PROTOCOL_ADDRESS_BYTES = 4;
    private const PACKET_BYTES = 28;

    public function __construct(
        private int $operation,
        private MacAddress $senderMac,
        private IpAddress $senderIp,
        private MacAddress $targetMac,
        private IpAddress $targetIp,
    ) {}

    /**
     * 指定したIPアドレスのMACアドレスを尋ねるリクエストを作成する
     */
    public static function request(MacAddress $senderMac, IpAddress $senderIp, IpAddress $targetIp): self
    {
        return new self(self::OPERATION_REQUEST, $senderMac, $senderIp, new MacAddress('000000000000'), $targetIp);
    }

    public function isRequest(): bool
    {
        return $this->operation === self::OPERATION_REQUEST;
    }

    public function getSenderMac(): MacAddress
    {
        return $this->senderMac;
    }

    public function getSenderIp(): IpAddress
    {
        return $this->senderIp;
    }

    public function getTargetIp(): IpAddress
    {
        return $this->targetIp;
    }

    public function toBytes(): string
    {
        return pack(
            'nnCCn',
            self::HARDWARE_ETHERNET,
            self::PROTOCOL_IPV4,
            self::HARDWARE_ADDRESS_BYTES,
            self::PROTOCOL_ADDRESS_BYTES,
            $this->operation
        )
            . $this->senderMac->getAddress() . $this->senderIp->getBytes()
            . $this->targetMac->getAddress() . $this->targetIp->getBytes();
    }

    /**
     * バイト列をパースする。対応していない種別や不正なパケットは null を返す
     * Ethernetのパディングが続いていても無視する
     */
    public static function parse(string $bytes): ?self
    {
        if (strlen($bytes) < self::PACKET_BYTES) {
            return null;
        }

        $fields = unpack('nhardware/nprotocol/Chlen/Cplen/noperation', $bytes);
        if ($fields['hardware'] !== self::HARDWARE_ETHERNET
            || $fields['protocol'] !== self::PROTOCOL_IPV4
            || $fields['hlen'] !== self::HARDWARE_ADDRESS_BYTES
            || $fields['plen'] !== self::PROTOCOL_ADDRESS_BYTES
            || !in_array($fields['operation'], [self::OPERATION_REQUEST, self::OPERATION_REPLY], true)) {
            return null;
        }

        return new self(
            operation: $fields['operation'],
            senderMac: new MacAddress(substr($bytes, 8, 6)),
            senderIp: IpAddress::fromBytes(substr($bytes, 14, 4)),
            targetMac: new MacAddress(substr($bytes, 18, 6)),
            targetIp: IpAddress::fromBytes(substr($bytes, 24, 4)),
        );
    }
}
