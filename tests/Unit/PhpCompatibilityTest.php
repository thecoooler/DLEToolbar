<?php

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

// Tests run on PHP 8, while the plugin supports PHP 7.4: reject PHP 8-only syntax and functions in src/.
it('keeps plugin sources compatible with PHP 7.4', function (): void {
    $visitor = new class extends NodeVisitorAbstract {
        /** @var list<string> */
        public array $violations = [];
        public string $file = '';

        public function enterNode(Node $node)
        {
            $construct = null;
            if ($node instanceof Node\Expr\Match_) {
                $construct = 'match';
            }
            if ($node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\NullsafePropertyFetch) {
                $construct = 'nullsafe operator';
            }
            if ($node instanceof Node\AttributeGroup) {
                $construct = 'attribute';
            }
            if ($node instanceof Node\Stmt\Enum_) {
                $construct = 'enum';
            }
            if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
                $construct = 'union/intersection type';
            }
            if ($node instanceof Node\Param && $node->flags !== 0) {
                $construct = 'promoted property';
            }
            if ($node instanceof Node\Param && $node->type instanceof Node\Identifier && $node->type->toLowerString() === 'mixed') {
                $construct = 'mixed type';
            }
            if ($node instanceof Node\FunctionLike && $node->getReturnType() instanceof Node\Identifier
                && in_array($node->getReturnType()->toLowerString(), ['mixed', 'never', 'static'], true)) {
                $construct = $node->getReturnType()->toLowerString() . ' return type';
            }
            if ($node instanceof Node\Stmt\Property && $node->type instanceof Node\Identifier && $node->type->toLowerString() === 'mixed') {
                $construct = 'mixed type';
            }
            if ($node instanceof Node\Stmt\Property && ($node->flags & Modifiers::READONLY) !== 0) {
                $construct = 'readonly';
            }
            if ($node instanceof Node\Arg && $node->name !== null) {
                $construct = 'named argument';
            }
            if ($node instanceof Node\VariadicPlaceholder) {
                $construct = 'first-class callable';
            }
            if ($node instanceof Node\Expr\Throw_ && !$node->getAttribute('statement')) {
                $construct = 'throw expression';
            }
            if ($node instanceof Node\Stmt\Catch_ && $node->var === null) {
                $construct = 'catch without variable';
            }
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array($node->name->getLast(), ['str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'array_is_list', 'fdiv', 'get_resource_id'], true)) {
                $construct = $node->name->getLast() . '()';
            }
            if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Throw_) {
                $node->expr->setAttribute('statement', true);
            }
            if ($construct !== null) {
                $this->violations[] = $this->file . ':' . $node->getStartLine() . ' ' . $construct;
            }
            return null;
        }
    };

    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $traverser = new NodeTraverser($visitor);
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ENGINE_DIR . '/iCooLER/DLEToolbar', FilesystemIterator::SKIP_DOTS));
    $paths = [ENGINE_DIR . '/inc/dletoolbar.php'];
    foreach ($sources as $source) {
        if ($source->getExtension() === 'php') {
            $paths[] = $source->getPathname();
        }
    }
    foreach ($paths as $path) {
        $visitor->file = $path;
        $traverser->traverse($parser->parse(file_get_contents($path)));
    }

    expect($visitor->violations)->toBe([]);
});
