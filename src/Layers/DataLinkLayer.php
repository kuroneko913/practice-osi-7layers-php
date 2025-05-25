<?php

namespace App\Layers;

use App\Layers\DataLinkLayer\FrameBuilderInterface;
use Psr\Log\LoggerInterface;

class DataLinkLayer implements LayerInterface
{
    /**
     * コンストラクタ
     * @param LayerInterface $lowerLayer
     * @param FrameBuilderInterface $frameBuilder
     */
    public function __construct(
        private LayerInterface $lowerLayer,
        private FrameBuilderInterface $frameBuilder,
        private ?LoggerInterface $logger = null,
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

    public function receive(): ?string
    {
        $buffer = '';
        while (!$this->frameBuilder->isFrameEnd($buffer)) {
            // 物理層からbit列を受信
            $buffer .= $this->lowerLayer->receive();
        }
        echo "[receive] buffer: " . strlen($buffer) . " bits\n";
        $length = $this->frameBuilder->getFrameLength($buffer);
        $chunk = substr($buffer, 0, $length);
        $this->logger->info('Received frame', ['chunk' => $chunk]);
        $frame = $this->frameBuilder->parseFrame($chunk);
        return $frame;
    }
}
