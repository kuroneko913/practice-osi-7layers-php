<?php

namespace App\Layers\DataLinkLayer;

/**
 * 受信した信号を Ethernet II フレームとして解釈する
 * IEEE 802.1Q のVLANタグ付きフレームにも対応する
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

        $bytes = EthernetFrame::bitsToBytes($bits);
        if (strlen($bytes) < EthernetFrame::MIN_FRAME_BYTES) {
            return null;
        }

        // EtherTypeの位置にTPIDがあれば、その後ろの2バイトがTCI、さらに後ろが本来のEtherType
        $tagged = unpack('n', substr($bytes, 12, 2))[1] === EthernetFrame::TPID;
        $tagBytes = $tagged ? EthernetFrame::TAG_BYTES : 0;
        $headerBytes = EthernetFrame::HEADER_BYTES + $tagBytes;

        // 長すぎるフレームは破棄 (タグ付きならタグの分だけ長くてよい)
        if (strlen($bytes) > $headerBytes + EthernetFrame::MAX_PAYLOAD_BYTES + EthernetFrame::FCS_BYTES) {
            return null;
        }

        return new EthernetFrame(
            destMac: new MacAddress(substr($bytes, 0, 6)),
            srcMac: new MacAddress(substr($bytes, 6, 6)),
            etherType: unpack('n', substr($bytes, 12 + $tagBytes, 2))[1],
            payload: substr($bytes, $headerBytes, -EthernetFrame::FCS_BYTES),
            fcs: substr($bytes, -EthernetFrame::FCS_BYTES),
            tci: $tagged ? unpack('n', substr($bytes, 14, 2))[1] : null,
        );
    }
}
