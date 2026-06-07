<?php

declare(strict_types=1);

namespace SearchGateway\Admin;

use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\Health\HealthChecker;

final class HealthAdminService
{
    public function __construct(
        private readonly ?HealthChecker $checker = null,
        private readonly ?SearchAnalytics $analytics = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(string $probeQuery = 'health-probe'): array
    {
        $report = [
            'status' => 'unknown',
            'timestamp' => time(),
            'providers' => [],
        ];
        if ($this->checker !== null) {
            $report['providers'] = $this->checker->check($probeQuery);
            $statuses = array_column($report['providers'], 'status');
            $report['status'] = in_array('unhealthy', $statuses, true) ? 'degraded' : 'healthy';
        }
        if ($this->analytics !== null) {
            $report['analytics'] = [
                'total' => count($this->analytics->events()),
            ];
        }
        return $report;
    }
}
