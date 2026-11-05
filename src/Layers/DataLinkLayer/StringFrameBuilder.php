<?php

namespace App\Layers\DataLinkLayer;

/**
 * 文字列フレームを作成する
 */
class StringFrameBuilder implements FrameBuilderInterface
{
    public function __construct(
        private MacAddress $from,
    ) {}

    public function build(string $data, MacAddress $to, EtherType $type): FrameInterface
    {
        return new StringFrame(
            destMac: $to,
            srcMac: $this->from,
            etherType: $type->toInt(),
            payload: $data,
        );
    }
}
