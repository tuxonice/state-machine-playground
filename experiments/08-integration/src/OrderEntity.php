<?php

declare(strict_types=1);

namespace Experiment08;

use Doctrine\ORM\Mapping as ORM;
use Tlab\StateMachine\Storage\StatefulInterface;

/**
 * What a Doctrine entity needs to be a state machine subject: implement StatefulInterface (two methods).
 * The definition version (experiment 4) and the optimistic-lock version column live on the entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class OrderEntity implements StatefulInterface
{
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $state = null;

    #[ORM\Column(type: 'integer')]
    private int $definitionVersion = 1;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string')]
        private string $id,
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

    public function getVersion(): int
    {
        return $this->version;
    }
}
