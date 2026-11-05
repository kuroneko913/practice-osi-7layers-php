<?php

namespace App\Layers\DataLinkLayer;

/**
 * EtherType (イーサタイプ)
 *
 * イーサネットフレーム内に格納されている「上位層（第3層・ネットワーク層）のプロトコル種別」を表す16ビットの識別子。
 * IEEE (Institute of Electrical and Electronics Engineers) が公式規格として管理・割り当てを行っています。
 */
enum EtherType: string
{
    case IPv4 = '0x0800';
    case ARP  = '0x0806';
    case IPv6 = '0x86DD';
    /** IEEE Std 802 の Local Experimental Ethertype。上位のプロトコルを持たないメッセージに使う */
    case LocalExperimental = '0x88B5';

    /**
     * プロトコルの日本語説明
     */
    public function description(): string
    {
        return match ($this) {
            self::IPv4 => 'IPv4 (Internet Protocol version 4)',
            self::ARP  => 'ARP (Address Resolution Protocol - IPアドレスからMACアドレスを解決する)',
            self::IPv6 => 'IPv6 (Internet Protocol version 6)',
            self::LocalExperimental => 'Local Experimental (ローカルな実験用)',
        };
    }

    /**
     * ヘッダの16ビット値から生成する。未知の値は null を返す
     */
    public static function fromInt(int $value): ?self
    {
        return self::tryFrom(sprintf('0x%04X', $value));
    }

    /**
     * ヘッダに格納する16ビット値
     */
    public function toInt(): int
    {
        return hexdec($this->value);
    }

    /**
     * 16進数表記 (プレフィックスなし、小文字4文字)
     */
    public function hex(): string
    {
        return strtolower(str_replace('0x', '', $this->value));
    }
}

