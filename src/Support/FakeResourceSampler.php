<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\Contracts\ResourceSamplerContract;
use ValcuAndrei\PestE2E\DTO\ResourceSampleDTO;

/**
 * Test double for {@see ResourceSamplerContract}. Returns a fixed queue of
 * samples so admission-controller tests can rehearse specific pressure
 * curves (spike then recovery, sustained high memory, missing metrics) without
 * having to force the CI host into a particular state.
 *
 * If the queue is exhausted the last-emitted sample is repeated. If no
 * samples were queued the sampler returns a "both metrics unavailable"
 * sentinel so the controller fails open in tests that don't care about
 * the sampler.
 *
 * @internal
 */
final class FakeResourceSampler implements ResourceSamplerContract
{
    /** @var list<ResourceSampleDTO> */
    private array $queue = [];

    private ?ResourceSampleDTO $last = null;

    public int $sampleCallCount = 0;

    /**
     * @param  array<string, int|float|string|bool|null>  $diagnostics
     */
    public function enqueue(?float $cpuUsedPct, ?float $memoryUsedPct, array $diagnostics = []): self
    {
        $this->queue[] = new ResourceSampleDTO($cpuUsedPct, $memoryUsedPct, $diagnostics);

        return $this;
    }

    public function sample(): ResourceSampleDTO
    {
        $this->sampleCallCount++;

        if ($this->queue !== []) {
            $next = array_shift($this->queue);
            $this->last = $next;

            return $next;
        }

        if ($this->last instanceof ResourceSampleDTO) {
            return $this->last;
        }

        return new ResourceSampleDTO(
            cpuUsedPct: null,
            memoryUsedPct: null,
            diagnostics: ['sampler' => self::class, 'note' => 'queue-empty-fail-open'],
        );
    }
}
