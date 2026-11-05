<?php

namespace App\Layers\DataLinkLayer;

/**
 * IEEE 802.3 の MAC(Media Access Control) アドレスを表現する
 */
class MacAddress
{
    public function __construct(
        public string $macAddress,
    ) {
        $len = strlen($this->macAddress);
        // MACアドレスは6バイトのバイナリ、または12文字のHEXで指定
        if ($len !== 6 && $len !== 12) {
            throw new \InvalidArgumentException('MACアドレスは6バイトまたは12文字のHEXで指定してください');
        }

        // 12文字のHEXならバイナリ6バイトに変換
        if ($len === 12) {
            // フォーマットチェック
            if (!preg_match('/\A[0-9A-Fa-f]{12}\z/', $this->macAddress)) {
                throw new \InvalidArgumentException('MACアドレスの形式が不正です');
            }
            $bin = @hex2bin($this->macAddress);
            if ($bin === false || strlen($bin) !== 6) {
                throw new \InvalidArgumentException('MACアドレスの変換に失敗しました');
            }
            $this->macAddress = $bin;
        }
    }

    public function getAddress(): string
    {
        return $this->macAddress;
    }

    public function getLength(): int
    {
        return 48;
    }

    /**
     * 他の MacAddress と同じアドレスかどうかを比較する
     */
    public function equals(MacAddress $other): bool
    {
        return $this->macAddress === $other->macAddress;
    }

    /**
     * 小文字HEX文字列 (12文字) として返す
     */
    public function toHex(): string
    {
        return bin2hex($this->macAddress);
    }

    /**
     * ブロードキャストアドレス (FF:FF:FF:FF:FF:FF) かどうかを判定する
     */
    public function isBroadcast(): bool
    {
        return $this->macAddress === "\xff\xff\xff\xff\xff\xff";
    }

    /**
     * グループアドレス (マルチキャスト/ブロードキャスト) かどうかを判定する
     * 先頭オクテットの最下位ビット (I/Gビット) が1ならグループアドレス
     */
    public function isMulticast(): bool
    {
        return (ord($this->macAddress[0]) & 1) === 1;
    }

    /**
     * ブロードキャストアドレス (FF:FF:FF:FF:FF:FF) を返す
     */
    public static function broadcast(): self
    {
        return new self('ffffffffffff');
    }

    /**
     * 12文字のHEXから生成する。形式が不正な場合は null を返す (ユーザー入力の検証用)
     */
    public static function tryFromHex(string $hex): ?self
    {
        if (!preg_match('/\A[0-9A-Fa-f]{12}\z/', $hex)) {
            return null;
        }
        return new self($hex);
    }

    /**
     * 表示用の AA:BB:CC:DD:EE:FF 形式で返す
     */
    public function format(): string
    {
        return implode(':', str_split(strtoupper($this->toHex()), 2));
    }
}

