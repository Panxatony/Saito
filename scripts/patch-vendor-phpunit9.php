<?php

/**
 * Patches CakePHP 3.10 vendor files for PHPUnit 9 + PHP 8 compatibility.
 *
 * Run after composer install/update via the post-install-cmd / post-update-cmd hooks.
 * The patches fix method signature incompatibilities that PHP 8 enforces strictly.
 * This script is idempotent — safe to run multiple times.
 *
 * Patched files:
 * - vendor/cakephp/cakephp/src/TestSuite/TestCase.php
 * - vendor/cakephp/cakephp/src/TestSuite/MockBuilder.php
 */

function applyPatch(string $file, string $search, string $replace): void
{
    $content = file_get_contents($file);
    if ($content === false) {
        echo "ERROR: Cannot read $file\n";
        return;
    }
    if (str_contains($content, $replace)) {
        // Already patched
        return;
    }
    if (!str_contains($content, $search)) {
        echo "WARNING: Patch target not found in $file — skipping\n";
        return;
    }
    file_put_contents($file, str_replace($search, $replace, $content));
}

$testCase = __DIR__ . '/../vendor/cakephp/cakephp/src/TestSuite/TestCase.php';
$mockBuilder = __DIR__ . '/../vendor/cakephp/cakephp/src/TestSuite/MockBuilder.php';

// ── TestCase.php patches ─────────────────────────────────────────────────────

// Add missing use imports (check for the complete expected block to detect prior application)
applyPatch(
    $testCase,
    "use PHPUnit\\Framework\\TestCase as BaseTestCase;",
    "use PHPUnit\\Framework\\MockObject\\MockBuilder as PHPUnitMockBuilder;\nuse PHPUnit\\Framework\\MockObject\\MockObject;\nuse PHPUnit\\Framework\\TestCase as BaseTestCase;"
);

// setUp(): void
applyPatch(
    $testCase,
    'public function setUp()',
    'public function setUp(): void'
);

// tearDown(): void
applyPatch(
    $testCase,
    'public function tearDown()',
    'public function tearDown(): void'
);

// getMockBuilder return type + use PHPUnitMockBuilder
applyPatch(
    $testCase,
    'public function getMockBuilder($className)
    {
        return new MockBuilder($this, $className);
    }',
    'public function getMockBuilder(string $className): PHPUnitMockBuilder
    {
        return new PHPUnitMockBuilder($this, $className);
    }'
);

// getMockClass typed params + return type
applyPatch(
    $testCase,
    'protected function getMockClass(
        $originalClassName,
        $methods = [],
        array $arguments = [],
        $mockClassName = \'\',
        $callOriginalConstructor = false,
        $callOriginalClone = true,
        $callAutoload = true,
        $cloneArguments = false
    ) {',
    'protected function getMockClass(
        string $originalClassName,
        $methods = [],
        array $arguments = [],
        string $mockClassName = \'\',
        bool $callOriginalConstructor = false,
        bool $callOriginalClone = true,
        bool $callAutoload = true,
        bool $cloneArguments = false
    ): string {'
);

// getMockForTrait typed params + return type
applyPatch(
    $testCase,
    'protected function getMockForTrait(
        $traitName,
        array $arguments = [],
        $mockClassName = \'\',
        $callOriginalConstructor = true,
        $callOriginalClone = true,
        $callAutoload = true,
        $mockedMethods = [],
        $cloneArguments = false
    ) {',
    'protected function getMockForTrait(
        string $traitName,
        array $arguments = [],
        string $mockClassName = \'\',
        bool $callOriginalConstructor = true,
        bool $callOriginalClone = true,
        bool $callAutoload = true,
        array $mockedMethods = [],
        bool $cloneArguments = false
    ): MockObject {'
);

// getMockForAbstractClass typed params + return type
applyPatch(
    $testCase,
    'protected function getMockForAbstractClass(
        $originalClassName,
        array $arguments = [],
        $mockClassName = \'\',
        $callOriginalConstructor = true,
        $callOriginalClone = true,
        $callAutoload = true,
        $mockedMethods = [],
        $cloneArguments = false
    ) {',
    'protected function getMockForAbstractClass(
        string $originalClassName,
        array $arguments = [],
        string $mockClassName = \'\',
        bool $callOriginalConstructor = true,
        bool $callOriginalClone = true,
        bool $callAutoload = true,
        array $mockedMethods = [],
        bool $cloneArguments = false
    ): MockObject {'
);

// assertTextContains: replace deprecated assertContains with assertStringContainsString
applyPatch(
    $testCase,
    '        $this->assertContains($needle, $haystack, $message, $ignoreCase);
    }

    /**
     * Assert that a text doesn\'t contain another text, ignoring differences in newlines.',
    '        if ($ignoreCase) {
            $this->assertStringContainsStringIgnoringCase($needle, $haystack, $message);
        } else {
            $this->assertStringContainsString($needle, $haystack, $message);
        }
    }

    /**
     * Assert that a text doesn\'t contain another text, ignoring differences in newlines.'
);

// assertTextNotContains: replace deprecated assertNotContains with assertStringNotContainsString
applyPatch(
    $testCase,
    '        $this->assertNotContains($needle, $haystack, $message, $ignoreCase);
    }

    /**
     * Asserts HTML tags.',
    '        if ($ignoreCase) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $haystack, $message);
        } else {
            $this->assertStringNotContainsString($needle, $haystack, $message);
        }
    }

    /**
     * Asserts HTML tags.'
);

echo "Patched: $testCase\n";

// ── MockBuilder.php replacement ──────────────────────────────────────────────
// PHPUnit 9 made MockBuilder final; CakePHP's subclass only suppressed a PHP 7
// deprecation that no longer exists in PHP 8, so replace with a no-op stub.

$noopStub = <<<'PHP'
<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please view the LICENSE.txt
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.8.8
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\TestSuite;

/**
 * Compatibility shim: PHPUnit 9 made MockBuilder final so we can no longer
 * extend it. The original suppression of ReflectionType::__toString()
 * deprecation is irrelevant on PHP 8, so this is now a no-op stub.
 *
 * @internal
 */
class MockBuilder
{
    public static function setSupressedErrorHandler(): void
    {
    }
}
PHP;

$current = file_get_contents($mockBuilder);
if ($current !== $noopStub) {
    file_put_contents($mockBuilder, $noopStub);
}
echo "Patched: $mockBuilder\n";

// ── TestSuite Constraint subclasses: add ': bool' return type to matches() ────
// PHPUnit 9 declared Constraint::matches($other): bool; CakePHP 3.10.x shipped
// the constraint classes without return types, which is a fatal error on PHP 8.
$constraintDir = __DIR__ . '/../vendor/cakephp/cakephp/src/TestSuite/Constraint';
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($constraintDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iter as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    applyPatch(
        $file->getPathname(),
        'public function matches($other)',
        'public function matches($other): bool'
    );
    applyPatch(
        $file->getPathname(),
        'public function toString()',
        'public function toString(): string'
    );
    applyPatch(
        $file->getPathname(),
        'public function failureDescription($other)',
        'public function failureDescription($other): string'
    );
    applyPatch(
        $file->getPathname(),
        'protected function failureDescription($other)',
        'protected function failureDescription($other): string'
    );
}
echo "Patched: Constraint subclasses in $constraintDir\n";

// ── ServerRequest.php: trim(null) deprecation on PHP 8 ───────────────────────
$serverRequest = __DIR__ . '/../vendor/cakephp/cakephp/src/Http/ServerRequest.php';
applyPatch($serverRequest, 'return trim($ipaddr);', 'return trim((string)$ipaddr);');
echo "Patched: $serverRequest\n";

// ── SimpleImage.php: PHP 8 GD object compatibility ───────────────────────────
$simpleImage = __DIR__ . '/../vendor/claviska/simpleimage/src/claviska/SimpleImage.php';
applyPatch(
    $simpleImage,
    "if(preg_match('/^data:(.*?);/', \$image)) {",
    "if(preg_match('/^data:(.*?);/', (string)\$image)) {"
);
applyPatch(
    $simpleImage,
    'if($this->image !== null && get_resource_type($this->image) === \'gd\') {',
    'if($this->image !== null && (is_resource($this->image) ? get_resource_type($this->image) === \'gd\' : true)) {'
);
echo "Patched: $simpleImage\n";

echo "All patches applied.\n";
