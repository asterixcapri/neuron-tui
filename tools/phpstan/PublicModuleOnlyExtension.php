<?php

declare(strict_types=1);

namespace NeuronCli\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\ClassNameUsageLocation;
use PHPStan\Rules\RestrictedUsage\RestrictedClassNameUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * Catches internal classes reached without an import statement.
 *
 * `PublicModuleOnlyRule` covers `use` statements; this covers every other way
 * a class name can appear — a fully qualified `new`, a static call, a type
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

        if (!PublicModule::isInternal($name)) {
            return null;
        }

        return RestrictedUsage::create(
            PublicModule::message($name),
            PublicModule::IDENTIFIER,
        );
    }
}
