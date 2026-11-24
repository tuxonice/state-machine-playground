<?php

declare(strict_types=1);

namespace Experiment04;

use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StateMachine\StateMachineRunner;
use Tlab\StateMachine\TransitionResult;

/** Routes every call to the runner of the version the order was created with. */
final class OrderService
{
    public function __construct(private Catalog $catalog, private VersionedStore $store, private string $name = 'order')
    {
    }

    /** New orders always start on the latest version. */
    public function create(string $orderId): int
    {
        $version = $this->catalog->latest();
        $this->store->create($orderId, $version, (string) $this->catalog->machine($version)->getCurrentState());

        return $version;
    }

    public function apply(string $orderId, string $event, array $data = []): TransitionResult
    {
        return $this->runnerFor($orderId)->applyTo(new Order($orderId), $event, $data);
    }

    /** @return string[] */
    public function availableEvents(string $orderId): array
    {
        return $this->runnerFor($orderId)->availableEventsFor(new Order($orderId));
    }

    private function runnerFor(string $orderId): StateMachineRunner
    {
        $version = $this->store->versionOf($orderId) ?? throw new \RuntimeException("unknown order {$orderId}");

        return $this->catalog->registry->get(Catalog::key($this->name, $version));
    }
}
