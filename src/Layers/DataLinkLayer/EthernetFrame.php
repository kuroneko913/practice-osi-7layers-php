<?php

namespace App\Layers\DataLinkLayer;

/**
 * Ethernet II フレームを表現する
 * IEEE 802.1Q のVLANタグを持つこともできる
 */
class EthernetFrame implements FrameInterface
{
    public const MIN_PAYLOAD_BYTES = 46;
    public const MAX_PAYLOAD_BYTES = 1500;

    /** ヘッダ (DEST + SRC + EtherType) のバイト数 */
    public const HEADER_BYTES = 6 + 6 + 2;
    public const FCS_BYTES = 4;

    /** フレーム (宛先MACアドレスからFCSまで) の最小長。タグがあっても変わらない */
    public const MIN_FRAME_BYTES = 64;

    /** VLANタグがあることを示す値 (TPID)。タグなしフレームのEtherTypeと同じ位置に入る */
    public const TPID = 0x8100;
    /** VLANタグ (TPID + TCI) のバイト数 */
    public const TAG_BYTES = 4;

    /** Preamble: 7 bytes of 0x55 (ワイヤ上の順序) */
    public const PREAMBLE_OCTET = '10101010';
    /** Start Frame Delimiter: 1 byte of 0xD5 (ワイヤ上の順序) */
    public const SFD_OCTET = '10101011';

    private string $fcs;

    /**
     * @param int $etherType EtherType欄の16ビット値
     * @param string $payload ペイロードのバイト列
     * @param string|null $fcs 受信したFCSのバイト列 (未指定時は計算する)
     * @param int|null $tci VLANタグのTCI (PCP 3ビット + DEI 1ビット + VID 12ビット)。タグなしなら null
     */
    public function __construct(
        private MacAddress $destMac,
        private MacAddress $srcMac,
        private int $etherType,
        private string $payload,
        ?string $fcs = null,
        private ?int $tci = null,
    ) {
        $this->fcs = $fcs ?? $this->calcFcs();
    }

    /**
     * Ethernet FCS (CRC-32) 計算。VLANタグも計算の範囲に含む
     */
    private function calcFcs(): string
    {
        return pack('V', hexdec(hash('crc32b', $this->toCaptureBytes())));
    }

    /**
     * 送信元MACアドレスの後ろに入るVLANタグ (TPID + TCI)。タグなしなら空文字
     */
    private function tagBytes(): string
    {
        return $this->tci === null ? '' : pack('nn', self::TPID, $this->tci);
    }

    public function getDestMac(): MacAddress
    {
        return $this->destMac;
    }

    public function getSrcMac(): MacAddress
    {
        return $this->srcMac;
    }

    public function getEtherType(): int
    {
        return $this->etherType;
    }

    public function isTagged(): bool
    {
        return $this->tci !== null;
    }

    /**
     * VLANタグのVLAN ID。タグなしなら null (VID 0 は優先度だけを示すタグで、VLANを指定しない)
     */
    public function getVlanId(): ?int
    {
        return $this->tci === null ? null : $this->tci & 0x0FFF;
    }

    /**
     * VLANタグを付けたフレームを返す。フレームが変わるのでFCSは計算し直す
     */
    public function withVlanTag(int $vlanId): self
    {
        // 優先度 (PCP) と DEI は元のタグから引き継ぐ
        $tci = (($this->tci ?? 0) & 0xF000) | ($vlanId & 0x0FFF);
        return new self($this->destMac, $this->srcMac, $this->etherType, $this->payload, tci: $tci);
    }

    /**
     * VLANタグを外したフレームを返す。フレームが変わるのでFCSは計算し直す
     */
    public function withoutVlanTag(): self
    {
        return new self($this->destMac, $this->srcMac, $this->etherType, $this->payload);
    }

    /**
     * Length/Type欄が長さの場合はパディングを除いて返す (IEEE 802.3 4.2.9 RemovePad)
     */
    public function getPayload(): string
    {
        if ($this->hasLengthField() && $this->hasValidLength()) {
            return substr($this->payload, 0, $this->etherType);
        }
        return $this->payload;
    }

    public function isValid(): bool
    {
        return $this->fcs === $this->calcFcs() && $this->hasValidLength();
    }

    /**
     * Length/Type欄の値が長さとして解釈されるかどうか
     */
    private function hasLengthField(): bool
    {
        return $this->etherType <= self::MAX_PAYLOAD_BYTES;
    }

    /**
     * 長さの値がデータ欄の実際の長さと矛盾しないかどうか (IEEE 802.3 3.4)
     * タグ付きフレームは、タグの分だけパディングが少なくてもよい。
     * タグなしのフレームにタグを付けただけなら、パディングはタグなしのときのまま残っている。
     */
    private function hasValidLength(): bool
    {
        if (!$this->hasLengthField()) {
            return true;
        }
        $length = strlen($this->payload);
        $untaggedLength = max($this->etherType, self::MIN_PAYLOAD_BYTES);
        if ($this->tci === null) {
            return $length === $untaggedLength;
        }
        $taggedLength = max($this->etherType, self::MIN_PAYLOAD_BYTES - self::TAG_BYTES);
        return $length >= $taggedLength && $length <= $untaggedLength;
    }

    public function toWire(): string
    {
        return str_repeat(self::PREAMBLE_OCTET, 7) . self::SFD_OCTET
            . self::bytesToBits($this->toCaptureBytes() . $this->fcs);
    }

    /**
     * バイト列をワイヤ上のビット列に変換する (各オクテットはLSBファースト)
     */
    public static function bytesToBits(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= strrev(str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT));
        }
        return $bits;
    }

    /**
     * 宛先MACアドレスからペイロードまでのバイト列を返す
     * パケットキャプチャと同じ形式で、プリアンブル、SFD、FCSを含まない
     */
    public function toCaptureBytes(): string
    {
        return $this->destMac->getAddress() . $this->srcMac->getAddress()
            . $this->tagBytes() . pack('n', $this->etherType) . $this->payload;
    }

    /**
     * ワイヤ上のビット列をバイト列に変換する (各オクテットはLSBファースト)
     */
    public static function bitsToBytes(string $bits): string
    {
        $bytes = '';
        foreach (str_split($bits, 8) as $octet) {
            $bytes .= chr(bindec(strrev($octet)));
        }
        return $bytes;
    }
}
