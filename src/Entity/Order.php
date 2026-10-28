<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Entity;

use Tlab\StateMachine\Storage\StatefulInterface;

class Order implements StatefulInterface
{
    private ?string $state = null;
    private ?string $paymentMethod = null;

    public function __construct(
        private readonly string $id,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }
}
