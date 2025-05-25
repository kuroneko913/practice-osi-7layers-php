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
}

