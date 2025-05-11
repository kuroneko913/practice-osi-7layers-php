<?php

namespace App\Layers;

use App\Layers\DataLinkLayer\FrameBuilderInterface;

class DataLinkLayer implements LayerInterface
{
    /**
     * コンストラクタ
     * @param LayerInterface $lowerLayer
     * @param FrameBuilderInterface $frameBuilder
     */
    public function __construct(
        private LayerInterface $lowerLayer,
        private FrameBuilderInterface $frameBuilder
     ) {}

    public function send(mixed $data): void
    {
        static $buffer = '';
        $buffer .= $data;
        if (str_ends_with($buffer, "\n")) {
            $frame = $this->frameBuilder->buildFrame(trim($buffer));
            $this->lowerLayer->send($frame);
            $buffer = '';
        }
    }

    public function receive(): mixed
    {
        $buffer = '';
        while (true) {
            $raw = $this->lowerLayer->receive();
            $buffer .= $raw;
            if (!$this->frameBuilder->isFrameEnd($buffer)) {
                continue;
            }
            // フレームが完成したらパースする
            $frame = $this->frameBuilder->parseFrame($buffer);
            $frameLength = $this->frameBuilder->getFrameLength($buffer);
            $buffer = substr($buffer, $frameLength);
            return $frame;
        }
    }
}
