<?php

namespace App\Test\TestCase\Controller;

use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Mailer;
use Cake\Mailer\Message;
use Cake\Mailer\TransportFactory;
use Saito\Test\IntegrationTestCase;

/**
 * Records the recipient of every message it is asked to send. Unlike a PHPUnit
 * mock it snapshots the real `To` at send time, so it detects a mail whose
 * address was mutated by a shared Message.
 */
class RecordingMailTransport extends AbstractTransport
{
    /** @var array<int, string> recipient email of each sent message, in order */
    public static array $recipients = [];

    /** @var array<int, string> the full header block of each sent message */
    public static array $headers = [];

    public function send(Message $message): array
    {
        self::$recipients[] = (string)array_key_first($message->getTo());
        self::$headers[] = $message->getHeadersString(
            ['from', 'sender', 'replyTo', 'readReceipt', 'returnPath', 'to', 'cc', 'bcc', 'subject']
        );

        return [];
    }
}

class ContactsControllerTest extends IntegrationTestCase
{

    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.User',
        'app.UserBlock',
        'app.UserOnline',
        'app.UserIgnore',
        'app.UserRead',
        'app.Setting',
    ];

    public function testContactUserByAnon()
    {
        $url = '/contacts/user/3';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }


    /**
     * The overlay posts by htmx, so success has to come back as an HX-Redirect
     * header — htmx cannot follow a 302 into a modal.
     *
     * This failed silently for a long time: `_contact()` built the response and
     * every caller dropped it. The mail went out, the overlay stayed open, and
     * the flash message sat in the session until the visitor clicked something
     * else — which is exactly how it was reported.
     *
     * @return void
     */
    public function testHtmxContactUserReturnsRedirectHeaderOnSuccess()
    {
        $this->mockSecurity();
        $this->_loginUser(2);
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->post('/contacts/htmx-contact-user/3', [
            'subject' => 'Betreff',
            'text' => 'Nachricht',
            'cc' => 0,
        ]);

        $this->assertHeader('HX-Redirect', '/');
    }

    /**
     * Without htmx the same action redirects normally — also a response that was
     * being dropped, so the standalone contact page re-rendered its own form
     * after sending instead of moving on.
     *
     * @return void
     */
    public function testContactUserRedirectsOnSuccessWithoutHtmx()
    {
        $this->mockSecurity();
        $this->_loginUser(2);
        $this->post('/contacts/htmx-contact-user/3', [
            'subject' => 'Betreff',
            'text' => 'Nachricht',
            'cc' => 0,
        ]);

        $this->assertRedirect('/');
    }

    /**
     * A validation error must NOT redirect — the form has to come back so the
     * sender can fix it. Without this the fix above could "work" by redirecting
     * unconditionally.
     *
     * @return void
     */
    public function testContactUserWithoutSubjectDoesNotRedirect()
    {
        $this->mockSecurity();
        $this->_loginUser(2);
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->post('/contacts/htmx-contact-user/3', [
            'subject' => '',
            'text' => 'Nachricht',
            'cc' => 0,
        ]);

        $this->assertResponseOk();
        $this->assertFalse(
            $this->_response->hasHeader('HX-Redirect'),
            'a validation error redirected instead of returning the form'
        );
    }

    /**
     * The public contact form's timing guard.
     *
     * htmxContactOwner is reachable without an account, so it is one of the few
     * doors an unattended script can knock on. It defends itself by recording
     * when the form was fetched and refusing a submission that arrives less than
     * five seconds later — a human reads and types, a script posts immediately.
     *
     * Nothing tested this. The guard could have been removed, or its comparison
     * inverted, and every suite would still have passed.
     *
     * @return void
     */
    public function testContactOwnerRejectsAnAnonymousSubmissionThatArrivesTooFast()
    {
        $this->mockSecurity();

        // GET first: that is what stamps the session with the load time.
        $this->get('/contacts/htmx-contact-owner');
        $this->assertResponseOk();

        $this->post('/contacts/htmx-contact-owner', [
            'subject' => 'Betreff',
            'text' => 'Nachricht',
            'sender_contact' => 'bot@example.com',
            'cc' => 0,
        ]);

        // Bounced back to the form, not delivered.
        $this->assertRedirect('/contacts/htmx-contact-owner');
    }

    /**
     * The same submission goes through once enough time has passed. Without this
     * counterpart the test above would also pass if the form were broken
     * outright.
     *
     * @return void
     */
    public function testContactOwnerAcceptsAnAnonymousSubmissionAfterTheDelay()
    {
        $this->mockSecurity();

        $this->get('/contacts/htmx-contact-owner');
        // Backdate the stamp rather than sleeping: the guard reads a timestamp,
        // so moving it is the honest way to represent "the visitor took a while".
        $this->session(['Contact' => ['formLoadTime' => time() - 60]]);

        $this->post('/contacts/htmx-contact-owner', [
            'subject' => 'Betreff',
            'text' => 'Nachricht',
            'sender_contact' => 'mensch@example.com',
            'cc' => 0,
        ]);

        $this->assertRedirect('/');
    }

    /**
     * A signed-in member is not subject to the delay — they are already known,
     * and making them wait would be a pointless obstacle.
     *
     * @return void
     */
    /**
     * A newline in the subject must not be able to start a header of its own.
     *
     * The subject reaches the mailer straight from this form, and CakePHP
     * passes a plain-ASCII header value through unchanged — measured against
     * `Message::getHeadersString()`, a subject of "Hallo\r\nBcc: …" came out as
     * two header lines, the second a real Bcc. That is a relay: the forum would
     * deliver to whoever the sender named, from its own domain and with its SPF
     * and DKIM behind it.
     *
     * @return void
     */
    public function testContactSubjectCannotInjectAHeader()
    {
        RecordingMailTransport::$recipients = [];
        RecordingMailTransport::$headers = [];
        TransportFactory::drop('recording');
        TransportFactory::setConfig('recording', ['className' => RecordingMailTransport::class]);
        $profile = (array)Mailer::getConfig('saito');
        Mailer::drop('saito');
        Mailer::setConfig('saito', ['transport' => 'recording'] + $profile);

        $this->mockSecurity();
        $this->_loginUser(2);

        $this->post('/contacts/htmx-contact-owner', [
            'subject' => "Betreff\r\nBcc: opfer@example.com",
            'text' => 'Nachricht',
            'cc' => 0,
        ]);

        // Two layers, and the test wants both. The form validator rejects the
        // subject, so nothing is sent at all -- and if a caller ever reaches
        // the mailer past the form, the component still strips the break, so no
        // message may carry an injected header either way.
        $this->assertSame([], RecordingMailTransport::$headers, 'nothing may be sent at all');

        foreach (RecordingMailTransport::$headers as $headers) {
            $this->assertSame(
                0,
                preg_match('/^Bcc:/mi', $headers),
                'a line break in the subject must not become a header'
            );
        }
    }

    public function testContactOwnerDoesNotDelaySignedInMembers()
    {
        $this->mockSecurity();
        $this->_loginUser(2);

        $this->post('/contacts/htmx-contact-owner', [
            'subject' => 'Betreff',
            'text' => 'Nachricht',
            'cc' => 0,
        ]);

        $this->assertRedirect('/');
    }

    /**
     * The "send me a copy" box must not deliver to an address the forum has
     * not verified.
     *
     * For an anonymous sender that address is simply whatever was typed in, so
     * honouring the box made the form an open relay: name your victim as the
     * sender, tick it, and the forum mails your text to them — from its own
     * domain, with its SPF and DKIM behind it.
     *
     * @return void
     */
    public function testContactOwnerDoesNotCopyAnUnverifiedSenderAddress()
    {
        RecordingMailTransport::$recipients = [];
        TransportFactory::drop('recording');
        TransportFactory::setConfig('recording', ['className' => RecordingMailTransport::class]);
        $profile = (array)Mailer::getConfig('saito');
        Mailer::drop('saito');
        Mailer::setConfig('saito', ['transport' => 'recording'] + $profile);

        $this->mockSecurity();
        $this->get('/contacts/htmx-contact-owner');
        $this->session(['Contact' => ['formLoadTime' => time() - 60]]);

        $this->post('/contacts/htmx-contact-owner', [
            'subject' => 'Betreff',
            'text' => 'Nachricht',
            'sender_contact' => 'opfer@example.com',
            'cc' => 1,
        ]);

        $this->assertNotContains(
            'opfer@example.com',
            RecordingMailTransport::$recipients,
            'the forum delivered to an address it never verified'
        );
        $this->assertCount(
            1,
            RecordingMailTransport::$recipients,
            'only the forum owner should have been written to'
        );
    }

    /**
     * Reachable without an account at all — that is the point of it.
     *
     * @return void
     */
    public function testContactOwnerIsPublic()
    {
        $this->get('/contacts/htmx-contact-owner');

        $this->assertResponseOk();
    }
}
