<?php

declare(strict_types=1);

namespace SearchGateway\Admin;

use SearchGateway\Analytics\SearchAnalytics;

final class AnalyticsAdminService
{
    public function __construct(private readonly ?SearchAnalytics $analytics = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        if ($this->analytics === null) {
            return ['enabled' => false];
        }
        return [
            'enabled' => true,
            'totalEvents' => count($this->analytics->events()),
            'topQueries' => $this->analytics->topQueries(10),
            'providerDistribution' => $this->analytics->providerDistribution(),
            'latencyByProvider' => $this->analytics->latencyByProvider(),
            'errorRateByProvider' => $this->analytics->errorRateByProvider(),
        ];
    }
}
