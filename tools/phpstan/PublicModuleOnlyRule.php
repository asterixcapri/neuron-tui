<?php

declare(strict_types=1);

namespace NeuronCli\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A Host Application may only reach Neuron CLI through its public module.
 *
 * This covers import statements, including ones for a class that is never
 * used afterwards. `PublicModuleOnlyExtension` covers every other mention of
 * a class name.
 *
 * @implements Rule<Node>
 *
 * @internal
 */
final class PublicModuleOnlyRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($this->importedNames($node) as [$line, $name]) {
            if (!PublicModule::isInternal($name)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                PublicModule::message($name),
            )
                ->identifier(PublicModule::IDENTIFIER)
                ->line($line)
                ->build();
        }

        return $errors;
    }

    /**
     * @return iterable<int, array{int, string}>
     */
    private function importedNames(Node $node): iterable
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
                    Name::concat($node->prefix, $use->name)?->toString()
                        ?? $use->name->toString(),
                ];
            }
        }
    }
}
