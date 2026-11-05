<?php

namespace App\Layers\DataLinkLayer;

/**
 * 受信した信号を Ethernet II フレームとして解釈する
 */
class EthernetFrameParser implements FrameParserInterface
{
    public function parse(string $raw): ?FrameInterface
    {
        // プリアンブルは欠けることがあるため、最初のSFDまでのビットを捨てる (IEEE 802.3 4.2.6)
        $sfd = strpos($raw, EthernetFrame::SFD_OCTET);
        if ($sfd === false) {
            return null;
        }
        $bits = substr($raw, $sfd + strlen(EthernetFrame::SFD_OCTET));

        // 不正な信号、オクテット境界に揃っていないフレームは破棄
        if (strspn($bits, '01') !== strlen($bits) || strlen($bits) % 8 !== 0) {
            return null;
        }

        // 短すぎる/長すぎるフレームは破棄
        $overhead = EthernetFrame::HEADER_BYTES + EthernetFrame::FCS_BYTES;
        $frameBytes = strlen($bits) / 8;
        if ($frameBytes < $overhead + EthernetFrame::MIN_PAYLOAD_BYTES
            || $frameBytes > $overhead + EthernetFrame::MAX_PAYLOAD_BYTES) {
            return null;
        }

        $bytes = EthernetFrame::bitsToBytes($bits);
        return new EthernetFrame(
            destMac: new MacAddress(substr($bytes, 0, 6)),
            srcMac: new MacAddress(substr($bytes, 6, 6)),
            etherType: unpack('n', substr($bytes, 12, 2))[1],
            payload: substr($bytes, EthernetFrame::HEADER_BYTES, -EthernetFrame::FCS_BYTES),
            fcs: substr($bytes, -EthernetFrame::FCS_BYTES),
        );
    }
}
