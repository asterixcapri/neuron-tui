<?php

declare(strict_types=1);

namespace NeuronCli\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports import statements naming anything but the public module.
 *
 * It reads the import statement itself rather than the class behind it, so
 * it also catches an import that is never used afterwards — the way an
 * internal class most easily ends up looking like part of the interface —
 * and it keeps working when the analysed project cannot resolve the class.
 * `PublicModuleOnlyExtension` covers every other mention of a class name.
 *
 * The node type is `Stmt` because the two statements that import a name,
 * `Use_` and `GroupUse`, share no closer ancestor.
 *
 * @implements Rule<Stmt>
 *
 * @internal
 */
final class PublicModuleOnlyRule implements Rule
{
    public function getNodeType(): string
    {
        return Stmt::class;
    }

    /**
     * @param Stmt $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($this->importedNames($node) as [$line, $name]) {
            if (!PublicModulePolicy::isInternal($name)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                PublicModulePolicy::violationMessage($name),
            )
                ->identifier(PublicModulePolicy::IDENTIFIER)
                ->line($line)
                ->build();
        }

        return $errors;
    }

    /**
     * @return iterable<int, array{int, string}>
     */
    private function importedNames(Stmt $node): iterable
    {
        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                yield [$use->getStartLine(), $use->name->toString()];
            }

            return;
        }

        if ($node instanceof GroupUse) {
            foreach ($node->uses as $use) {
                yield [
                    $use->getStartLine(),
                    $node->prefix->toString() . '\\' . $use->name->toString(),
                ];
            }
        }
    }
}
