<?php

namespace App\Layers;

/**
 * スイッチのポートにケーブルが接続されているか (リンク状態) を表す
 *
 * PC側は接続している間、ファイルのロックを持ち続ける。スイッチ側はロックの有無でリンク状態を判断する。
 * プロセスが終了するとOSがロックを解放するので、強制終了してもリンクダウンとして検知できる。
 */
class PhysicalLink
{
    /** @var resource|null */
    private $plug = null;

    public function __construct(
        private string $path,
    ) {}

    /**
     * ケーブルを接続する (PC側)
     * @return bool すでに別のPCが接続している場合は false
     */
    public function plugIn(): bool
    {
        $file = fopen($this->path, 'c');
        if ($file === false || !flock($file, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->plug = $file;
        return true;
    }

    /**
     * ケーブルを抜く (PC側)
     */
    public function unplug(): void
    {
        if (is_resource($this->plug)) {
            flock($this->plug, LOCK_UN);
            fclose($this->plug);
        }
        $this->plug = null;
    }

    /**
     * リンクが確立しているかどうかを調べる (スイッチ側)
     */
    public function isUp(): bool
    {
        $file = fopen($this->path, 'c');
        if ($file === false) {
            return false;
        }
        // ロックを取れるなら、接続しているPCはいない
        $isDown = flock($file, LOCK_SH | LOCK_NB);
        fclose($file);
        return !$isDown;
    }

    public function __destruct()
    {
        $this->unplug();
    }
}
