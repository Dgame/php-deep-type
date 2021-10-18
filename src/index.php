<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

$code = file_get_contents(__DIR__ . '/test.php');

$parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7);
try {
    $ast = $parser->parse($code);
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";

    return;
}

final class Scope
{
    /** @var array<string, Value[]> */
    private array $variables = [];
    /** @var array<string, Value[]> */
    private array $parameters = [];
    /** @var self[] */
    private array $nested = [];
    /** @var array<int, self> */
    private array $specialized = [];

    public function __construct(private string $name, private ?self $outer = null) { }

    public function getVariableByName(string $name): array
    {
        return $this->variables[$name] ?? $this->outer?->getVariableByName($name) ?? [];
    }

    public function getFunctionScope(string $name): ?Scope
    {
        return $this->nested[$name] ?? null;
    }

    public function getParameterByName(string $name): ?Value
    {
        return $this->parameters[$name] ?? null;
    }

    public function getParameterAt(int $index): ?Value
    {
        return array_values($this->parameters)[$index] ?? null;
    }

    public function attachScope(self $scope): void
    {
        $this->nested[$scope->name] = $scope;
    }

    /**
     * @param string $scopeName
     * @param array<string, Value>  $values
     */
    public function refineScope(string $scopeName, array $values): void
    {
        if ($values === []) {
            return;
        }

        $scope = $this->nested[$scopeName] ?? null;
        if ($scope === null) {
            $scope = new self($scopeName);
            $this->attachScope($scope);
        }

        $specializedScope = clone $scope;
        $scope->specialized[reset($values)->line] = $specializedScope;

        foreach ($values as $value) {
            $specializedScope->storeParameter($value);
        }
    }

    public function __clone(): void
    {
        $this->specialized = [];
    }

    public function storeValue(Value $value): void
    {
        $oldValue = $this->variables[$value->name] ?? null;
        if ($oldValue === null) {
            $this->variables[$value->name][$value->line] = $value;
        } else {
            $lastValue = array_pop($oldValue);
            if ($lastValue->value === null) {
                $this->variables[$value->name][$value->line] = $value;
            } elseif ($value->value !== null) {
                $this->variables[$value->name][$value->line] = $value;
            }
        }
    }

    public function storeParameter(Value $value): void
    {
        $this->parameters[$value->name] = $value;
    }
}

final class Value
{
    public function __construct(
        public string $name,
        public mixed  $value,
        public string $type,
        public int    $line)
    {
    }

    public function withName(string $name): self
    {
        return new self(name: $name, value: $this->value, type: $this->type, line: $this->line);
    }
}

final class Analyzer
{
    public int $iteration = 0;
    public Scope $scope;

    public function __construct()
    {
        $this->scope = new Scope(name: 'global');
        $this->scope->storeValue(new Value(name: '_GET', value: [], type: 'array', line: 0));
    }

    public function visitNode(Node $node): void
    {
        if ($node instanceof Stmt\Expression) {
            $expr = $node->expr;
            if ($expr instanceof Expr\Assign) {
                $var = $expr->var;
                if ($var instanceof Expr\Variable) {
                    $this->scope->storeValue($this->detectValue($var->name, $expr->expr));
                } else {
                    var_dump('KEINE VARIABLE', $var);
                    exit;
                }
            } elseif ($expr instanceof Expr\FuncCall) {
                $name = $expr->name;
                if (!($name instanceof Node\Name)) {
                    print 'KEIN FUNCTION CALL! #' . $this->iteration . PHP_EOL;

                    return;
                }

                $function = $name->toString();
                $values = [];
                foreach ($expr->args as $i => $arg) {
                    $name      = $arg->name;
                    $paramName = $name instanceof Node\Identifier ? $name->toString() : $this->scope->getFunctionScope($function)?->getParameterAt($i)?->name;
                    $paramName ??= '#' . $i;

                    $value = $this->detectValue($paramName, $arg->value);
                    if ($value->value === null) {
                        print 'Kein Value gefunden für Parameter ' . $paramName . ' für Funktion ' . $function . ' #' . $this->iteration . '@' . $node->getLine() . PHP_EOL;
                    }

                    $values[$paramName] = $value->withName($paramName);
                }

                $this->scope->refineScope($function, $values);
            }
        } elseif ($node instanceof Node\Stmt\Function_) {
            $scope = new Scope(name: $node->name->toString());
            $this->scope->attachScope($scope);
            foreach ($node->params as $param) {
                $scope->storeParameter(
                    new Value(
                        name: $param->var->name,
                        value: $param->default === null ? null : $this->detectValue($param->var->name, $param->default)?->value,
                        type: $param->type->toString(),
                        line: $param->getLine()
                    )
                );
            }
        }
    }

    private function detectValue(string $name, Node $node): Value
    {
        if ($node instanceof Expr\Variable) {
            $values = $this->scope->getVariableByName($node->name);
            if ($values !== []) {
                return array_pop($values);
            }

            $value = $this->scope->getParameterByName($name);
            if ($value !== null) {
                return $value;
            }
        }

        if ($node instanceof PhpParser\Node\Scalar\LNumber) {
            return new Value(name: $name, value: $node->value, type: 'int', line: $node->getLine());
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return new Value(name: $name, value: $node->value, type: 'float', line: $node->getLine());
        }

        if ($node instanceof Node\Scalar\String_) {
            return new Value(name: $name, value: $node->value, type: 'string', line: $node->getLine());
        }

        if ($node instanceof Expr\Array_) {
            $valueType = null;
            $keyType = null;

            $values = [];
            /**
             * @var int $i
             * @var Expr\ArrayItem $item
             */
            foreach ($node->items as $i => $item) {
                if ($item->key === null) {
                    $keyType = 'int';
                } else {
                    $value = $this->detectValue('key_' . $i, $item->key);
                    $keyType = $keyType === null || $value->type === $keyType ? $value->type : 'mixed';
                }

                $value = $this->detectValue('value_' . $i, $item->value);
                $valueType = $valueType === null || $value->type === $valueType ? $value->type : 'mixed';

                $values[] = $value;
            }

            $type = $keyType === $valueType ? $valueType . '[]' : sprintf('array<%s, %s>', $keyType, $valueType);

            return new Value(name: $name, value: $values, type: $type, line: $node->getLine());
        }

        $values = [];
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};
            if ($subNode instanceof Node) {
                $value = $this->detectValue(name: $name, node: $subNode);
                if ($value->value !== null) {
                    $values[] = $value;
                }
            }
        }

        if (count($values) === 1) {
            return array_pop($values);
        }

        if (count($values) > 1) {
            return new Value(name: $name, value: null, type: implode('|', array_map(static fn(Value $value) => $value->type, $values)), line: $node->getLine());
        }

        return new Value(name: $name, value: null, type: 'mixed', line: $node->getLine());
    }
}

//$traverser = new NodeTraverser();
$analyzer  = new Analyzer();
foreach ($ast as $node) {
    $analyzer->visitNode($node);
}

print_r($analyzer->scope);

//for ($i = 0; $i < 2; $i++) {
//    $analyzer->iteration = $i;
//
//    $traverser->addVisitor($analyzer);
//    $traverser->traverse($ast);
//}
//
//print_r($analyzer->variables);
//print_r($analyzer->calls);
//print_r($analyzer->functions);
//$dumper = new NodeDumper();
//echo $dumper->dump($ast) . "\n";
