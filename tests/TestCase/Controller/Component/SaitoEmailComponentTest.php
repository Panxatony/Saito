<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\SaitoEmailComponent;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * The component is the single point every mail the forum sends passes through,
 * so the header guard belongs here rather than only in the one form that is
 * known to reach it today.
 */
class SaitoEmailComponentTest extends TestCase
{
    protected SaitoEmailComponent $Component;

    public function setUp(): void
    {
        parent::setUp();
        $registry = new ComponentRegistry(new Controller(new ServerRequest()));
        $this->Component = new SaitoEmailComponent($registry);
    }

    /**
     * Everything that could end a header line and begin another one.
     *
     * CakePHP hands a plain-ASCII header value through unchanged, so
     * "Hallo\r\nBcc: victim@example.com" came out of
     * `Message::getHeadersString()` as two header lines, the second a real Bcc.
     *
     * @return void
     */
    public function testStripsEverythingThatCouldStartAHeader()
    {
        $cases = [
            "Hallo\r\nBcc: opfer@example.com" => 'HalloBcc: opfer@example.com',
            "Hallo\nBcc: opfer@example.com" => 'HalloBcc: opfer@example.com',
            "Hallo\rBcc: opfer@example.com" => 'HalloBcc: opfer@example.com',
            "Hallo\0Bcc: opfer@example.com" => 'HalloBcc: opfer@example.com',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->Component->sanitizeHeaderValue($input));
        }
    }

    /**
     * And an ordinary subject is left alone — including non-ASCII, which the
     * mailer encodes itself.
     *
     * @return void
     */
    public function testLeavesAnOrdinarySubjectAlone()
    {
        $subject = 'Rückfrage zu „Größe" – bitte um Antwort';
        $this->assertSame($subject, $this->Component->sanitizeHeaderValue($subject));
    }
}
