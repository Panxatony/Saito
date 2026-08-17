<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Config;

use Cake\TestSuite\TestCase;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

/**
 * The `.env` reader refuses input the previous one accepted, and it does so
 * during bootstrap — before CakePHP has an error page, so a log entry is all an
 * operator gets.
 *
 * Production found this out on 2026-08-17: `EMAIL_FROM_NAME=macnemo Forum`, a
 * value written without quotes and read without complaint for years, took the
 * site down for five minutes behind a stack trace that named neither the file
 * nor the fix.
 *
 * `config/bootstrap.php` cannot be exercised directly — it runs once, before
 * the test suite exists. So this holds down the two things the message is built
 * on: that the reader really does refuse the input, and that its own words do
 * not tell an operator what to do. If either stops being true, the wrapping in
 * bootstrap is no longer earning its place and should be revisited.
 */
class DotenvFailureMessageTest extends TestCase
{
    private string $dir = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = TMP . 'dotenv-test-' . uniqid();
        mkdir($this->dir);
    }

    public function tearDown(): void
    {
        $file = $this->dir . DS . '.env';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->dir . DS . '.env', $contents);
    }

    /**
     * The shape that broke production. Quoted is fine, unquoted is refused.
     *
     * @return void
     */
    public function testAnUnquotedValueWithASpaceIsRefused(): void
    {
        $this->writeEnv("export EMAIL_FROM_NAME=macnemo Forum\n");

        $this->expectException(InvalidFileException::class);
        Dotenv::createArrayBacked($this->dir, '.env')->load();
    }

    /**
     * @return void
     */
    public function testTheSameValueQuotedIsAccepted(): void
    {
        $this->writeEnv("export EMAIL_FROM_NAME=\"macnemo Forum\"\n");

        $values = Dotenv::createArrayBacked($this->dir, '.env')->load();

        $this->assertSame('macnemo Forum', $values['EMAIL_FROM_NAME']);
    }

    /**
     * The reason `config/bootstrap.php` wraps the exception at all: the
     * library names the offending value, which is genuinely useful, but says
     * nothing about which file it read or what would fix it.
     *
     * @return void
     */
    public function testTheLibraryMessageDoesNotSayWhatToDo(): void
    {
        $this->writeEnv("export EMAIL_FROM_NAME=macnemo Forum\n");

        try {
            Dotenv::createArrayBacked($this->dir, '.env')->load();
            $this->fail('expected the reader to refuse this');
        } catch (InvalidFileException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('macnemo Forum', $message);
            $this->assertStringNotContainsString('.env', $message, 'the file is not named');
            $this->assertStringNotContainsString('quote', strtolower($message), 'the remedy is not given');
        }
    }
}
