<?php

namespace App\Layers\NetworkLayer;

use App\Layers\DataLinkLayer\MacAddress;

/**
 * IPアドレスとMACアドレスの対応を保持するARPキャッシュ
 * 古くなったエントリは消える (RFC 1122 2.3.2.1)。静的エントリは消えない
 */
class ArpTable
{
    /** @var array<string, array{ip: IpAddress, mac: MacAddress, expiresAt: ?float}> */
    private array $entries = [];

    public function __construct(
        private float $entrySeconds = 60.0,
    ) {}

    public function addStatic(IpAddress $ip, MacAddress $mac): void
    {
        $this->entries[(string)$ip] = ['ip' => $ip, 'mac' => $mac, 'expiresAt' => null];
    }

    public function learn(IpAddress $ip, MacAddress $mac): void
    {
        $this->entries[(string)$ip] = ['ip' => $ip, 'mac' => $mac, 'expiresAt' => microtime(true) + $this->entrySeconds];
    }

    public function lookup(IpAddress $ip): ?MacAddress
    {
        $this->removeExpired();
        return $this->entries[(string)$ip]['mac'] ?? null;
    }

    /**
     * @return array<string, array{ip: IpAddress, mac: MacAddress, expiresAt: ?float}>
     */
    public function getEntries(): array
    {
        $this->removeExpired();
        return $this->entries;
    }

    private function removeExpired(): void
    {
        $now = microtime(true);
        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= $now) {
                unset($this->entries[$key]);
            }
        }
    }
}
