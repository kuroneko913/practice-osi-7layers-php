<?php

namespace App\Layers\DataLinkLayer;

/**
 * フレームを取り扱うインターフェース
 */
interface FrameBuilderInterface
{
    /**
     * フレームを作成する
     * @param string $data
     * @return string
     */
    public function buildFrame(string $data): string;

    /**
     * フレームをパースする
     * @param string $raw
     * @return string|null
     */
    public function parseFrame(string $raw): ?string;

    /**
     * フレームが終了しているかどうかを判断する
     * @param string $raw
     * @return bool
     */
    public function isFrameEnd(string $raw): bool;

    /**
     * フレーム全体の長さを返す
     * @param string $raw
     * @return int
     */
    public function getFrameLength(string $raw): int;
}
