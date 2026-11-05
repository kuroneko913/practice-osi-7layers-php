<?php

namespace App\Layers\DataLinkLayer;

/**
 * フレームを表現するインターフェース
 */
interface FrameInterface
{
    public function getDestMac(): MacAddress;

    public function getSrcMac(): MacAddress;

    /**
     * ヘッダのEtherType欄の値
     * 未知のプロトコルのフレームも転送できるよう、EtherTypeに変換せず生の値を返す
     */
    public function getEtherType(): int;

    /**
     * ペイロード
     * EtherTypeのフレームはパディングを含む (本来の長さは上位層のプロトコルが判断する)
     */
    public function getPayload(): string;

    /**
     * 誤り検出符号が正しいかどうかを判定する
     */
    public function isValid(): bool;

    /**
     * 物理層に渡す形式に変換する
     */
    public function toWire(): string;
}
