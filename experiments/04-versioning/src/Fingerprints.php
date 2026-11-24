<?php

declare(strict_types=1);

namespace Experiment04;

use ReflectionClass;

/**
 * Release guardrail. For every version: a hash of its definition file and of the source file of every
 * guard and command it references. Compare against what was recorded at release time; any difference
 * means something a released version depends on was edited.
 */
final class Fingerprints
{
    /**
     * @param callable(string): string|null $classFile maps a FQCN to its source file (default: reflection)
     *
     * @return array<string, array<string, string>> "v1" => ["definition" => sha1, "Some\\Class" => sha1]
     */
    public static function build(Catalog $catalog, ?callable $classFile = null): array
    {
        $classFile ??= fn(string $fqcn) => (new ReflectionClass($fqcn))->getFileName();
        $out = [];
        foreach ($catalog->versions() as $v) {
            $out["v{$v}"] = ['definition' => sha1_file($catalog->file($v))];
            foreach ($catalog->referencedClasses($v) as $class) {
                $out["v{$v}"][$class] = sha1_file($classFile($class));
            }
        }

        return $out;
    }

    /** @return string[] human readable differences; empty when everything released is untouched */
    public static function diff(array $released, array $current): array
    {
        $problems = [];
        foreach ($released as $version => $items) {
            foreach ($items as $label => $hash) {
                $now = $current[$version][$label] ?? null;
                if ($now !== $hash) {
                    $problems[] = "{$version}: " . ($now === null ? "{$label} no longer referenced or missing" : "{$label} changed after release");
                }
            }
        }

        return $problems;
    }
}
