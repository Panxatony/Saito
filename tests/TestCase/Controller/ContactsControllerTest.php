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

    public function send(Message $message): array
    {
        self::$recipients[] = (string)array_key_first($message->getTo());

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
}
