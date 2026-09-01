<?php

declare(strict_types=1);

namespace NeuronTui\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\ClassNameUsageLocation;
use PHPStan\Rules\RestrictedUsage\RestrictedClassNameUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * Reports internal classes reached without an import statement.
 *
 * `PublicModuleOnlyRule` covers imports; this covers every other way a class
 * name can appear — a fully qualified `new`, a static call, a type
 * declaration.
 *
 * @internal
 */
final class PublicModuleOnlyExtension implements
    RestrictedClassNameUsageExtension
{
    public function isRestrictedClassNameUsage(
        ClassReflection $classReflection,
        Scope $scope,
        ClassNameUsageLocation $location,
    ): ?RestrictedUsage {
        $name = $classReflection->getName();

        if (!PublicModulePolicy::isInternal($name)) {
            return null;
        }

        return RestrictedUsage::create(
            PublicModulePolicy::violationMessage($name),
            PublicModulePolicy::IDENTIFIER,
        );
    }
}
