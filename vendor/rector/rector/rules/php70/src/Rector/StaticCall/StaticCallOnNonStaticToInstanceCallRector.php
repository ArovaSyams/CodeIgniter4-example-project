<?php

declare(strict_types=1);

namespace Rector\Php70\Rector\StaticCall;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Type\ObjectType;
use Rector\Core\PhpParser\Node\Manipulator\ClassMethodManipulator;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeCollector\StaticAnalyzer;
use Rector\NodeTypeResolver\Node\AttributeKey;
use ReflectionClass;

/**
 * @see https://thephp.cc/news/2017/07/dont-call-instance-methods-statically
 * @see https://3v4l.org/tQ32f
 * @see https://3v4l.org/jB9jn
 * @see https://stackoverflow.com/a/19694064/1348344
 *
 * @see \Rector\Php70\Tests\Rector\StaticCall\StaticCallOnNonStaticToInstanceCallRector\StaticCallOnNonStaticToInstanceCallRectorTest
 */
final class StaticCallOnNonStaticToInstanceCallRector extends AbstractRector
{
    /**
     * @var ClassMethodManipulator
     */
    private $classMethodManipulator;

    /**
     * @var StaticAnalyzer
     */
    private $staticAnalyzer;

    public function __construct(ClassMethodManipulator $classMethodManipulator, StaticAnalyzer $staticAnalyzer)
    {
        $this->classMethodManipulator = $classMethodManipulator;
        $this->staticAnalyzer = $staticAnalyzer;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Changes static call to instance call, where not useful', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class Something
{
    public function doWork()
    {
    }
}

class Another
{
    public function run()
    {
        return Something::doWork();
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class Something
{
    public function doWork()
    {
    }
}

class Another
{
    public function run()
    {
        return (new Something)->doWork();
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    /**
     * @param StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->name instanceof Expr) {
            return null;
        }

        $methodName = $this->getName($node->name);

        $className = $this->resolveStaticCallClassName($node);
        if ($methodName === null || $className === null) {
            return null;
        }

        if ($this->shouldSkip($methodName, $className, $node)) {
            return null;
        }

        if ($this->isInstantiable($className)) {
            $new = new New_($node->class);

            return new MethodCall($new, $node->name, $node->args);
        }

        // can we add static to method?
        $classMethodNode = $this->nodeRepository->findClassMethod($className, $methodName);
        if ($classMethodNode === null) {
            return null;
        }

        if ($this->classMethodManipulator->isStaticClassMethod($classMethodNode)) {
            return null;
        }

        $this->makeStatic($classMethodNode);

        return null;
    }

    private function resolveStaticCallClassName(Node $node): ?string
    {
        if ($node->class instanceof PropertyFetch) {
            $objectType = $this->getObjectType($node->class);
            if ($objectType instanceof ObjectType) {
                return $objectType->getClassName();
            }
        }

        return $this->getName($node->class);
    }

    private function shouldSkip(string $methodName, string $className, StaticCall $staticCall): bool
    {
        $isStaticMethod = $this->staticAnalyzer->isStaticMethod($methodName, $className);
        if ($isStaticMethod) {
            return true;
        }

        if ($this->isNames($staticCall->class, ['self', 'parent', 'static', 'class'])) {
            return true;
        }

        $parentClassName = $staticCall->getAttribute(AttributeKey::PARENT_CLASS_NAME);
        if ($className === $parentClassName) {
            return true;
        }

        return $className === null;
    }

    private function isInstantiable(string $className): bool
    {
        $reflectionClass = new ReflectionClass($className);
        $classConstructorReflection = $reflectionClass->getConstructor();

        if ($classConstructorReflection === null) {
            return true;
        }

        if (! $classConstructorReflection->isPublic()) {
            return false;
        }

        // required parameters in constructor, nothing we can do
        return ! (bool) $classConstructorReflection->getNumberOfRequiredParameters();
    }
}
