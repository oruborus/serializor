<?php

declare(strict_types=1);

namespace Serializor\CodeExtractors;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionObject;
use RuntimeException;

use function array_merge;

final class AnonymousClassCodeExtractor implements CodeExtractor
{
    /** @param array<string, string> $memberNamesToDiscard */
    public function extract(
        ReflectionObject $reflection,
        array $memberNamesToDiscard,
        string $code,
    ): string {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $visitor = new AnonymousClassVisitor($reflection, $memberNamesToDiscard);
        /** @var Stmt[] $ast */
        $ast = $parser->parse($code);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getCode();
    }
}

/** @internal */
final class AnonymousClassVisitor extends NodeVisitorAbstract
{
    private ?Class_ $anonymousClassNode = null;

    /** @var Property[] $promotedProperties */
    private array $promotedProperties = [];

    private ?Namespace_ $namespace = null;

    /** @var Use_[] $useStatements */
    private array $useStatements = [];

    /** @param string[] $memberNamesToDiscard */
    public function __construct(
        private ReflectionObject $reflection,
        private array $memberNamesToDiscard,
    ) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->namespace = $node;
        }

        if ($node instanceof Use_) {
            $this->useStatements[] = $node;
        }

        if (
            $node instanceof New_
            && $node->class instanceof Class_
            && $node->class->name === null
        ) {
            if (
                $node->getStartLine() === $this->reflection->getStartLine()
                && $node->getEndLine() === $this->reflection->getEndLine()
            ) {
                if ($this->anonymousClassNode !== null) {
                    throw new RuntimeException('Class node was already identified');
                }

                $node->class->extends = $this->fullyQualifyName($node->class->extends);
                foreach ($node->class->implements as &$implements) {
                    $implements = $this->fullyQualifyName($implements);
                }

                $this->anonymousClassNode = $node->class;
            }
        }

        if (
            $this->anonymousClassNode !== null
            && $node instanceof Property
        ) {
            $node->type = $this->fullyQualifyType($node->type);
        }

        if (
            $this->anonymousClassNode !== null
            && $node instanceof ClassMethod
            && in_array($node->name->name, $this->memberNamesToDiscard)
        ) {
            if ($node->name->name !== '__construct') {
                return NodeVisitor::REMOVE_NODE;
            }

            foreach ($node->params as $param) {
                if ($param->flags !== 0) {
                    $property = new Property(
                        flags: $param->flags,
                        props: [
                            new PropertyItem($param->var->name)
                        ],
                        type: $this->fullyQualifyType($param->type),
                    );
                    $this->promotedProperties[] = $property;
                }
            }

            return NodeVisitor::REMOVE_NODE;
        }
    }

    private function fullyQualifyType(null|Identifier|Name|UnionType|IntersectionType|NullableType $type): null|Identifier|Name|ComplexType
    {
        if ($type === null || $type instanceof Identifier) {
            return $type;
        }

        if ($type instanceof Name) {
            return $this->fullyQualifyName($type);
        }

        if ($type instanceof NullableType) {
            $type->type = $this->fullyQualifyType($type->type);

            return $type;
        }

        foreach ($type->types as &$subType) {
            $subType = $this->fullyQualifyType($subType);
        }

        return $type;
    }

    private function fullyQualifyName(?Name $name): ?Name
    {
        if ($name === null || $name->isQualified()) {
            return $name;
        }

        return new Name($this->findFullName((string) $name), $name->getAttributes());
    }

    private function findFullName(string $name): string
    {
        foreach ($this->useStatements as $useStatement) {
            foreach ($useStatement->uses as $uses) {
                if ((string) $uses->alias === $name) {
                    return "\\{$uses->name}";
                }

                if ((string) $uses->name === $name) {
                    return (string) $uses->name;
                }
            }
        }

        if ($this->namespace) {
            $namespace = (string) $this->namespace->name;

            return "\\{$namespace}\\{$name}";
        }


        return $name;
    }

    public function leaveNode(Node $node)
    {
        if (
            $this->anonymousClassNode !== null
            && $node instanceof Class_
            && $this->promotedProperties !== []
        ) {
            /** @var list<Stmt> */
            $node->stmts = array_merge($this->promotedProperties, $node->stmts);
        }
    }

    public function getCode(): string
    {
        $node = $this->anonymousClassNode
            ?? throw new RuntimeException('no class node was identified');

        return (new Standard())->prettyPrint([$node]);
    }
}
