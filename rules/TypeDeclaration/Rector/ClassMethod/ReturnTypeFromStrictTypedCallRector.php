<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UnionType as PhpParserUnionType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\UnionType;
use PHPStan\Type\VoidType;
use Rector\Core\Php\PhpVersionProvider;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\PHPStanStaticTypeMapper\Enum\TypeKind;
use Rector\TypeDeclaration\NodeAnalyzer\TypeNodeUnwrapper;
use Rector\TypeDeclaration\TypeAnalyzer\ReturnStrictTypeAnalyzer;
use Rector\TypeDeclaration\TypeInferer\ReturnTypeInferer;
use Rector\VendorLocker\NodeVendorLocker\ClassMethodReturnTypeOverrideGuard;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedCallRector\ReturnTypeFromStrictTypedCallRectorTest
 */
final class ReturnTypeFromStrictTypedCallRector extends AbstractRector
{
    public function __construct(
        private readonly TypeNodeUnwrapper $typeNodeUnwrapper,
        private readonly ReturnStrictTypeAnalyzer $returnStrictTypeAnalyzer,
        private readonly ReturnTypeInferer $returnTypeInferer,
        private readonly PhpVersionProvider $phpVersionProvider,
        private readonly ClassMethodReturnTypeOverrideGuard $classMethodReturnTypeOverrideGuard
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Add return type from strict return type of call', [
            new CodeSample(
                <<<'CODE_SAMPLE'
final class SomeClass
{
    public function getData()
    {
        return $this->getNumber();
    }

    private function getNumber(): int
    {
        return 1000;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
final class SomeClass
{
    public function getData(): int
    {
        return $this->getNumber();
    }

    private function getNumber(): int
    {
        return 1000;
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class, Function_::class, Closure::class, ArrowFunction::class];
    }

    /**
     * @param ClassMethod|Function_|Closure|ArrowFunction $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->isSkipped($node)) {
            return null;
        }

        if ($node instanceof ArrowFunction) {
            return $this->processArrowFunction($node);
        }

        /** @var Return_[] $returns */
        $returns = $this->betterNodeFinder->find((array) $node->stmts, function (Node $subNode) use ($node): bool {
            $currentFunctionLike = $this->betterNodeFinder->findParentType($subNode, FunctionLike::class);

            if ($currentFunctionLike === $node) {
                return $subNode instanceof Return_;
            }

            $currentReturn = $this->betterNodeFinder->findParentType($subNode, Return_::class);
            if (! $currentReturn instanceof Return_) {
                return false;
            }

            $currentReturnFunctionLike = $this->betterNodeFinder->findParentType($currentReturn, FunctionLike::class);
            if ($currentReturnFunctionLike !== $currentFunctionLike) {
                return false;
            }

            return $subNode instanceof Return_;
        });

        $returnedStrictTypes = $this->returnStrictTypeAnalyzer->collectStrictReturnTypes($returns);
        if ($returnedStrictTypes === []) {
            return null;
        }

        if (count($returnedStrictTypes) === 1) {
            return $this->refactorSingleReturnType($returns[0], $returnedStrictTypes[0], $node);
        }

        if ($this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::UNION_TYPES)) {
            /** @var PhpParserUnionType[] $returnedStrictTypes */
            $unwrappedTypes = $this->typeNodeUnwrapper->unwrapNullableUnionTypes($returnedStrictTypes);
            $node->returnType = new PhpParserUnionType($unwrappedTypes);

            return $node;
        }

        return null;
    }

    private function processArrowFunction(ArrowFunction $arrowFunction): ?ArrowFunction
    {
        $resolvedType = $this->nodeTypeResolver->getType($arrowFunction->expr);

        // void type is not accepted for arrow functions - https://www.php.net/manual/en/functions.arrow.php#125673
        if ($resolvedType instanceof VoidType) {
            return null;
        }

        $returnType = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($resolvedType, TypeKind::RETURN);

        if (! $returnType instanceof Node) {
            return null;
        }

        $arrowFunction->returnType = $returnType;
        return $arrowFunction;
    }

    private function isUnionPossibleReturnsVoid(ClassMethod | Function_ | Closure $node): bool
    {
        $inferReturnType = $this->returnTypeInferer->inferFunctionLike($node);
        if ($inferReturnType instanceof UnionType) {
            foreach ($inferReturnType->getTypes() as $type) {
                if ($type instanceof VoidType) {
                    return true;
                }
            }
        }

        return false;
    }

    private function processSingleUnionType(
        ClassMethod | Function_ | Closure $node,
        UnionType $unionType,
        NullableType $nullableType
    ): Closure | ClassMethod | Function_ {
        $types = $unionType->getTypes();
        $returnType = $types[0] instanceof ObjectType && $types[1] instanceof NullType
            ? new NullableType(new FullyQualified($types[0]->getClassName()))
            : $nullableType;

        $node->returnType = $returnType;
        return $node;
    }

    private function isSkipped(ClassMethod | Function_ | Closure | ArrowFunction $node): bool
    {
        if (! $this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::SCALAR_TYPES)) {
            return true;
        }

        if ($node instanceof ArrowFunction) {
            return $node->returnType !== null;
        }

        if ($node->returnType !== null) {
            return true;
        }

        if (! $node instanceof ClassMethod) {
            return $this->isUnionPossibleReturnsVoid($node);
        }

        if ($this->classMethodReturnTypeOverrideGuard->shouldSkipClassMethod($node)) {
            return true;
        }

        if (! $node->isMagic()) {
            return $this->isUnionPossibleReturnsVoid($node);
        }

        return true;
    }

    private function refactorSingleReturnType(
        Return_ $return,
        Identifier|Name|NullableType|ComplexType $returnedStrictTypeNode,
        ClassMethod | Function_ | Closure $functionLike
    ): Closure | ClassMethod | Function_ {
        $resolvedType = $this->nodeTypeResolver->getType($return);

        if ($resolvedType instanceof UnionType) {
            if (! $returnedStrictTypeNode instanceof NullableType) {
                return $functionLike;
            }

            return $this->processSingleUnionType($functionLike, $resolvedType, $returnedStrictTypeNode);
        }

        /** @var Name $returnType */
        $returnType = $resolvedType instanceof ObjectType
            ? new FullyQualified($resolvedType->getClassName())
            : $returnedStrictTypeNode;

        $functionLike->returnType = $returnType;

        return $functionLike;
    }
}
