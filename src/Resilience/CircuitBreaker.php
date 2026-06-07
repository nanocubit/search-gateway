<?php

declare(strict_types=1);

namespace SearchGateway\Resilience;

/**
 * Backward-compatibility alias.
 *
 * The class was renamed to {@see InMemoryCircuitBreaker}. The old name is preserved
 * here as a thin subclass so existing userland code importing `CircuitBreaker`
 * keeps working without surprises (type checks `instanceof CircuitBreaker` still
 * pass and the public API is identical).
 *
 * @deprecated since 2.0 — use SearchGateway\Resilience\InMemoryCircuitBreaker
 */
class CircuitBreaker extends InMemoryCircuitBreaker
{
}
