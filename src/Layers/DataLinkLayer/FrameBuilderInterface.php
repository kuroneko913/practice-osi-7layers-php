<?php

namespace App\Layers\DataLinkLayer;

/**
 * フレームを作成するインターフェース
 */
interface FrameBuilderInterface
{
    /**
     * フレームを作成する
     * @param string $data ペイロードデータ
     * @param MacAddress $to 送信先MACアドレス (DEST MAC)
     * @param EtherType $type ペイロードのプロトコル種別
     * @return FrameInterface
     */
    public function build(string $data, MacAddress $to, EtherType $type): FrameInterface;
}
