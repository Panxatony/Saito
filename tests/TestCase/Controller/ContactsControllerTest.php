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

}
