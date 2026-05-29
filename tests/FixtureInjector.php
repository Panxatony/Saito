<?php

declare(strict_types=1);

namespace App\Test;

use Cake\TestSuite\Fixture\FixtureManager;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestSuite;

/**
 * PHPUnit 9-compatible replacement for Cake\TestSuite\Fixture\FixtureInjector.
 *
 * CakePHP 3.10's FixtureInjector calls loadPHPUnitAliases() at file-load time and
 * extends the removed BaseTestListener class. This class reimplements the same logic
 * using PHPUnit 9's still-present (deprecated) TestListener interface.
 *
 * FixtureManager is created internally rather than via XML <object> argument to avoid
 * PHPUnit instantiating it during config parsing (before the bootstrap runs).
 */
class FixtureInjector implements TestListener
{
    use TestListenerDefaultImplementation;

    protected FixtureManager $_fixtureManager;

    protected ?TestSuite $_first = null;

    public function __construct()
    {
        $manager = new FixtureManager();
        if (isset($_SERVER['argv'])) {
            $manager->setDebug(in_array('--debug', $_SERVER['argv'], true));
        }
        $this->_fixtureManager = $manager;
        $this->_fixtureManager->shutDown();
        TestCase::$fixtureManager = $manager;
    }

    public function startTestSuite(TestSuite $suite): void
    {
        if ($this->_first === null) {
            $this->_first = $suite;
        }
    }

    public function endTestSuite(TestSuite $suite): void
    {
        if ($this->_first === $suite) {
            $this->_fixtureManager->shutDown();
        }
    }

    public function startTest(Test $test): void
    {
        if ($test instanceof TestCase) {
            $this->_fixtureManager->fixturize($test);
            $this->_fixtureManager->load($test);
        }
    }

    public function endTest(Test $test, float $time): void
    {
        if ($test instanceof TestCase) {
            $this->_fixtureManager->unload($test);
        }
    }
}
