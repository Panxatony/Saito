<?php

declare(strict_types=1);

namespace App\Test\TestCase\Routing;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The addresses the retired Backbone/Marionette frontend published.
 *
 * They outlived the code that served them: search engines indexed them,
 * members bookmarked them, other sites link to them. Removing the SPA must not
 * turn twenty years of those links into dead ends, so each one is redirected to
 * its island counterpart.
 *
 * This is easy to break without noticing — the catch-all `fallbacks()` route
 * matches `/entries/view/123` perfectly well and would answer with a
 * missing-action error, which looks like an ordinary 404 rather than a
 * regression. Hence a test per address.
 */
class LegacyUrlRedirectTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Legacy address → where it has to land.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function legacyUrlProvider(): array
    {
        return [
            'posting' => ['/entries/view/1', '/entries/htmx-posting/1'],
            'thread' => ['/entries/mix/1', '/entries/htmx-thread/1'],
            'front page' => ['/entries/index', '/entries/htmx-index'],
            'profile' => ['/users/view/2', '/users/htmx-profile/2'],
            'member list' => ['/users/index', '/users/htmx-users'],
            'registration' => ['/users/register', '/users/htmx-register'],
        ];
    }

    /**
     * @param string $legacy the published address
     * @param string $target where it must lead now
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('legacyUrlProvider')]
    public function testLegacyUrlRedirects(string $legacy, string $target): void
    {
        $this->get($legacy);

        $this->assertResponseCode(
            301,
            sprintf('%s must redirect permanently, so crawlers adopt the new address', $legacy)
        );
        $this->assertRedirect($target);
    }

    /**
     * The redirect must carry the ID through rather than dropping it — landing
     * everyone on posting 1 would be worse than a 404, because it looks like it
     * worked.
     *
     * @return void
     */
    public function testRedirectKeepsTheIdentifier(): void
    {
        $this->get('/entries/view/4711');
        $this->assertRedirect('/entries/htmx-posting/4711');

        $this->get('/users/view/23');
        $this->assertRedirect('/users/htmx-profile/23');
    }
}
