<?php
declare(strict_types=1);

namespace Webhooks\Test\TestCase\Lib;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use RuntimeException;
use Webhooks\Lib\UserEventPayload;
use Webhooks\Lib\WebhookDispatcher;

/**
 * @covers \Webhooks\Lib\WebhookDispatcher
 * @covers \Webhooks\Lib\UserEventPayload
 */
class WebhookDispatcherTest extends TestCase
{
    private function payload(string $event = 'register'): UserEventPayload
    {
        return new UserEventPayload($event, 4711, 'jane', '2026-08-02T18:30:00Z');
    }

    /**
     * @return void
     */
    public function testSendsNothingWithoutUrl(): void
    {
        Configure::write('Saito.webhooks.user', ['url' => '']);

        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('post');

        $this->assertFalse((new WebhookDispatcher($client))->send($this->payload()));
    }

    /**
     * An installation that subscribed to two events must not receive the third.
     *
     * @return void
     */
    public function testHonoursTheEventSubscription(): void
    {
        Configure::write('Saito.webhooks.user', [
            'url' => 'https://example.org/hook',
            'events' => ['register', 'activate'],
        ]);

        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('post');

        $this->assertFalse((new WebhookDispatcher($client))->send($this->payload('delete')));
    }

    /**
     * The body and the signature the receiver has to verify against.
     *
     * @return void
     */
    public function testPostsSignedPayload(): void
    {
        Configure::write('Saito.webhooks.user', [
            'url' => 'https://example.org/hook',
            'secret' => 'geheim',
        ]);

        $expectedBody = '{"event":"register","user":{"id":4711,"username":"jane"}'
            . ',"occurredAt":"2026-08-02T18:30:00Z"}';
        $expectedSig = 'sha256=' . hash_hmac('sha256', $expectedBody, 'geheim');

        $response = $this->createStub(Response::class);
        $response->method('isOk')->willReturn(true);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                'https://example.org/hook',
                $expectedBody,
                $this->callback(function (array $options) use ($expectedSig): bool {
                    return ($options['headers']['X-Saito-Signature'] ?? null) === $expectedSig
                        && ($options['headers']['Content-Type'] ?? null) === 'application/json';
                }),
            )
            ->willReturn($response);

        $this->assertTrue((new WebhookDispatcher($client))->send($this->payload()));
    }

    /**
     * No secret configured, no signature header — rather than a signature over
     * an empty string, which a receiver might mistake for a valid one.
     *
     * @return void
     */
    public function testOmitsSignatureWithoutSecret(): void
    {
        Configure::write('Saito.webhooks.user', ['url' => 'https://example.org/hook']);

        $response = $this->createStub(Response::class);
        $response->method('isOk')->willReturn(true);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn(array $o): bool => !isset($o['headers']['X-Saito-Signature'])),
            )
            ->willReturn($response);

        (new WebhookDispatcher($client))->send($this->payload());
    }

    /**
     * **The property this whole design exists for.** A member registering must
     * not be told the registration failed because a phone app is unreachable.
     *
     * @return void
     */
    public function testSwallowsATransportFailure(): void
    {
        Configure::write('Saito.webhooks.user', ['url' => 'https://example.org/hook']);

        $client = $this->createStub(Client::class);
        $client->method('post')->willThrowException(new RuntimeException('connection refused'));

        $result = (new WebhookDispatcher($client))->send($this->payload());

        $this->assertFalse($result, 'a failed delivery reports false');
    }

    /**
     * A 500 from the receiver is a failed delivery, not a crash.
     *
     * @return void
     */
    public function testReportsAnErrorResponseAsFailure(): void
    {
        Configure::write('Saito.webhooks.user', ['url' => 'https://example.org/hook']);

        $response = $this->createStub(Response::class);
        $response->method('isOk')->willReturn(false);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn($response);

        $this->assertFalse((new WebhookDispatcher($client))->send($this->payload()));
    }

    /**
     * The payload must not grow an email address by accident: this is the test
     * that fails if somebody adds one to `UserEventPayload` later.
     *
     * @return void
     */
    public function testPayloadCarriesNothingBeyondIdAndName(): void
    {
        $data = $this->payload()->toArray();

        $this->assertSame(['event', 'user', 'occurredAt'], array_keys($data));
        $this->assertSame(['id', 'username'], array_keys($data['user']));
    }

    /**
     * The deprecated fields, when an installation asks for them.
     *
     * @return void
     */
    public function testLegacyContactFieldsAreCarriedWhenGiven(): void
    {
        $payload = new UserEventPayload(
            'register',
            4711,
            'jane',
            '2026-08-02T18:30:00Z',
            ['email' => 'jane@example.org', 'ip' => '198.51.100.7'],
        );

        $user = $payload->toArray()['user'];

        $this->assertSame(['id', 'username', 'email', 'ip'], array_keys($user));
        $this->assertSame('jane@example.org', $user['email']);
        $this->assertSame('198.51.100.7', $user['ip']);
    }

    /**
     * They must not push the id or the name out of the way, whatever a caller
     * hands over — a receiver reading `user.id` has to keep finding it there.
     *
     * @return void
     */
    public function testLegacyFieldsCannotOverwriteTheCoreOnes(): void
    {
        $payload = new UserEventPayload(
            'register',
            4711,
            'jane',
            '2026-08-02T18:30:00Z',
            ['id' => 99, 'username' => 'somebody else', 'email' => 'jane@example.org'],
        );

        $user = $payload->toArray()['user'];

        $this->assertSame(4711, $user['id']);
        $this->assertSame('jane', $user['username']);
        $this->assertSame('jane@example.org', $user['email']);
    }
}
