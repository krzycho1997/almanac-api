<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ModuleBoundaryTest
{
    /**
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function modulesUseOtherModulesOnlyThroughContracts(): iterable
    {
        foreach (Codebase::modules() as $module) {
            yield ".{$module}" => PHPat::rule()
                ->classes(Codebase::namespaceOf($module))
                ->canOnly()
                ->dependOn()
                ->classes(
                    Codebase::outsideApp(),
                    Codebase::namespaceOf($module),
                    Codebase::namespaceOf(Codebase::SHARED),
                    Codebase::contracts(),
                )
                ->because("{$module} may use only its own classes, Shared and the Contract namespace of other modules")
                ->nonIgnorable();
        }
    }

    #[TestRule]
    public function sharedDependsOnNoModule(): Rule
    {
        return PHPat::rule()
            ->classes(Codebase::namespaceOf(Codebase::SHARED))
            ->canOnly()
            ->dependOn()
            ->classes(Codebase::outsideApp(), Codebase::namespaceOf(Codebase::SHARED))
            ->because('Shared is used by every module and must not know any of them')
            ->nonIgnorable();
    }
}
