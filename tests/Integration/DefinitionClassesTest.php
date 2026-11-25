<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;

/**
 * Every guard and command named in a definition must resolve, so a typo in a class name fails the test suite
 * instead of the first order that takes that transition.
 */
class DefinitionClassesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function definitions(): array
    {
        $files = glob(__DIR__ . '/../../config/*-state-machine.json') ?: [];

        return array_combine(array_map('basename', $files), array_map(fn(string $f) => [$f], $files));
    }

    #[DataProvider('definitions')]
    public function testEveryGuardAndCommandResolves(string $file): void
    {
        Bootstrap::createRunner($file)->validateClasses();

        $this->addToAssertionCount(1); // validateClasses() throws a ResolutionException listing every bad class
    }
}
