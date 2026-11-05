<?php

namespace App\Layers\DataLinkLayer;

/**
 * 受信した信号をフレームとして解釈するインターフェース
 */
interface FrameParserInterface
{
    /**
     * フレームをパースする
     * @param string $raw 物理層から受け取ったバースト
     * @return FrameInterface|null フレームとして解釈できない場合は null
     */
    public function parse(string $raw): ?FrameInterface;
}
