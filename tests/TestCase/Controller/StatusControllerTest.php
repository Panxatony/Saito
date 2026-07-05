<?php

namespace App\Test\TestCase\Controller;

use Saito\Test\IntegrationTestCase;

class StatusControllerTest extends IntegrationTestCase
{

    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserBlock',
        'app.UserOnline',
        'app.UserRead',
        'plugin.Bookmarks.Bookmark',
    ];

    public function testStatusMustBeAjax()
    {
        $this->expectException(
            'Cake\Http\Exception\BadRequestException'
        );
        $this->get('/status/status');
    }

    public function testStatusSuccess()
    {
        $this->_setAjax();
        $this->_setJson();
        $this->mockSecurity();

        $this->get('/status/status');

        $this->assertResponseOk();
        $this->assertNoRedirect();

        $expected = json_encode([]);
        $this->assertResponseContains($expected);
    }

    public function testStatusAsEventStream()
    {
        $this->mockSecurity();
        $this->configRequest(['headers' => ['Accept' => 'text/event-stream']]);

        $this->get('/status/status');

        $this->assertResponseOk();
        $this->assertContentType('text/event-stream');
        $this->assertResponseContains('data: ');
        $this->assertResponseContains('retry: ');
    }
}
