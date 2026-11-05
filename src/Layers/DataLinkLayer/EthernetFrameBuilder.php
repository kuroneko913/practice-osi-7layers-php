<?php

namespace App\Layers\DataLinkLayer;

/**
 * Ethernet II フレームを作成する
 */
class EthernetFrameBuilder implements FrameBuilderInterface
{
    /**
     * @param MacAddress $from 送信元MACアドレス (自端末のMAC)
     */
    public function __construct(
        private MacAddress $from,
    ) {}

    public function build(string $data, MacAddress $to, EtherType $type): FrameInterface
    {
        if (strlen($data) > EthernetFrame::MAX_PAYLOAD_BYTES) {
            throw new \RuntimeException("Payload size exceeds maximum Ethernet payload size (1500 bytes)");
        }

        return new EthernetFrame(
            destMac: $to,
            srcMac: $this->from,
            etherType: $type->toInt(),
            payload: str_pad($data, EthernetFrame::MIN_PAYLOAD_BYTES, "\0"),  // minimum Ethernet payload size
        );
    }
}
