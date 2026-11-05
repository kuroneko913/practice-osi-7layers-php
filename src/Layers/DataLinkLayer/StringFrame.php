<?php

namespace App\Layers\DataLinkLayer;

/**
 * 文字列形式のフレームを表現する (中身を目で追うための、Ethernetと差し替え可能な形式)
 */
class StringFrame implements FrameInterface
{
    private string $crc;

    /**
     * @param string|null $crc 受信したCRC (未指定時は計算する)
     */
    public function __construct(
        private MacAddress $destMac,
        private MacAddress $srcMac,
        private int $etherType,
        private string $payload,
        ?string $crc = null
    ) {
        $this->crc = $crc ?? $this->calcCrc();
    }

    private function calcCrc(): string
    {
        return sprintf('%u', crc32($this->payload));
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

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function isValid(): bool
    {
        return $this->crc === $this->calcCrc();
    }

    public function toWire(): string
    {
        $type = sprintf('0x%04X', $this->etherType);
        // 制御文字が物理層の終端シンボルと衝突しないようエスケープする
        $payload = addcslashes($this->payload, "\0..\37\\");
        return "<FRAME>DEST:{$this->destMac->toHex()}|SRC:{$this->srcMac->toHex()}|TYPE:$type|PAYLOAD:$payload|CRC:{$this->crc}</FRAME>";
    }
}
