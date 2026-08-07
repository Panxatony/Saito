<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;

/**
 * The avatar upload (#84).
 *
 * The only endpoint in Saito that takes a file from a member and keeps it, and
 * it had no test at all. Uploads are the classic route to remote code
 * execution, so "nothing is broken here" was plausible but unevidenced.
 *
 * It turns out to be well guarded, and deeper than the validator suggests. Four
 * rules run before anything is stored — extension whitelist, size, the *real*
 * mime type read off the file rather than the declared one, and
 * `getimagesize()` — and behind them the thumbnailer refuses to process what is
 * not an image. That last layer was found by deleting two of the four and
 * watching a disguised script still fail to be stored.
 *
 * The tests exercise each rule separately rather than through one happy path,
 * because the rules overlap: a `.php` file trips at least three of them, so a
 * single "it is refused" test would stay green with two removed.
 * `testAValidImageIsAccepted` guards the opposite failure — without it every
 * refusal here could pass simply because nothing ever stores an avatar at all.
 */
class AvatarUploadTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.Smiley',
        'app.SmileyCode',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    /** Ulysses. */
    private const USER_ID = 3;

    /** Another member, to prove the endpoint is owner-scoped. */
    private const OTHER_USER_ID = 5;

    /** @var list<string> files to remove afterwards */
    private array $tmpFiles = [];

    public function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    /**
     * A real image of a given size, on disk.
     *
     * Built here rather than through `mockMediaFile()`, which makes a single
     * pixel — below the 100×100 the avatar rules require, so every upload would
     * have failed the dimension check and told us nothing about the others.
     *
     * @param string $name file name, its extension decides the format
     * @param int $width pixels
     * @param int $height pixels
     * @return string the path
     */
    private function makeImage(string $name, int $width = 200, int $height = 200): string
    {
        $path = TMP . $name;
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 60, 90));

        if (str_ends_with($name, '.png')) {
            imagepng($image, $path);
        } else {
            imagejpeg($image, $path);
        }
        imagedestroy($image);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * A file that is not an image at all, whatever it claims to be.
     *
     * @param string $name file name
     * @param string $content what is actually inside
     * @return string the path
     */
    private function makeFile(string $name, string $content): string
    {
        $path = TMP . $name;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * Post a file at the avatar endpoint the way a browser does.
     *
     * `$declaredMime` is separate from the file's real content on purpose:
     * the browser supplies that header and an attacker controls it, so the
     * tests below hand over deliberate lies.
     *
     * @param string $path file on disk
     * @param string $declaredMime what the request claims the type is
     * @param int|null $userId whose avatar (defaults to the test member)
     * @return void
     */
    private function uploadAvatar(string $path, string $declaredMime, ?int $userId = null): void
    {
        $userId ??= self::USER_ID;
        $this->mockSecurity();
        $this->post('/users/htmx-avatar/' . $userId, [
            'avatar' => new UploadedFile(
                $path,
                filesize($path),
                UPLOAD_ERR_OK,
                basename($path),
                $declaredMime,
            ),
        ]);
    }

    /**
     * @return string|null what the account currently has stored
     */
    private function storedAvatar(): ?string
    {
        $value = TableRegistry::getTableLocator()->get('Users')
            ->get(self::USER_ID)->get('avatar');

        return $value === null ? null : (string)$value;
    }

    public function testAValidImageIsAccepted(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->uploadAvatar($this->makeImage('avatar-good.png'), 'image/png');

        $this->assertRedirect();
        $this->assertNotEmpty($this->storedAvatar(), 'a valid avatar has to actually be stored');
    }

    /**
     * The one that matters: a script with an image's name and an image's
     * declared type.
     *
     * @return void
     */
    public function testAPhpScriptDisguisedAsAnImageIsRefused(): void
    {
        $this->_loginUser(self::USER_ID);
        $path = $this->makeFile('avatar-evil.php', "<?php echo 'pwned'; ?>\n");
        $this->uploadAvatar($path, 'image/png');

        $this->assertNull($this->storedAvatar(), 'a .php upload must never be stored');
    }

    /**
     * The same script wearing an image's extension, so the whitelist cannot be
     * what refuses it. This is the case that proves the content is inspected
     * rather than the file name trusted.
     *
     * @return void
     */
    public function testAScriptRenamedToPngIsStillRefused(): void
    {
        $this->_loginUser(self::USER_ID);
        $path = $this->makeFile('avatar-evil.png', "<?php echo 'pwned'; ?>\n");
        $this->uploadAvatar($path, 'image/png');

        $this->assertNull(
            $this->storedAvatar(),
            'the extension whitelist alone is not enough — the file content has to be checked',
        );
    }

    /**
     * An image that is genuinely an image, but of a type not allowed. Refused
     * by content, not by name: the extension says png.
     *
     * @return void
     */
    public function testAnImageOfADisallowedTypeIsRefused(): void
    {
        $this->_loginUser(self::USER_ID);
        // A one-pixel GIF, byte for byte.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $path = $this->makeFile('avatar-actually-gif.png', $gif);
        $this->uploadAvatar($path, 'image/png');

        $this->assertNull($this->storedAvatar());
    }

    public function testAnImageBelowTheMinimumSizeIsRefused(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->uploadAvatar($this->makeImage('avatar-tiny.png', 20, 20), 'image/png');

        $this->assertNull($this->storedAvatar());
    }

    public function testAnImageAboveTheMaximumSizeIsRefused(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->uploadAvatar($this->makeImage('avatar-huge.png', 1600, 1600), 'image/png');

        $this->assertNull($this->storedAvatar());
    }

    /**
     * The endpoint takes an id, so it has to be scoped to its owner — otherwise
     * anybody could set anybody's avatar.
     *
     * @return void
     */
    public function testAMemberCannotSetSomebodyElsesAvatar(): void
    {
        $this->_loginUser(self::USER_ID);

        $this->expectException(SaitoForbiddenException::class);
        $this->uploadAvatar($this->makeImage('avatar-other.png'), 'image/png', self::OTHER_USER_ID);
    }

    /**
     * Removing an avatar clears both columns — the file reference and the
     * directory. Leaving `avatar_dir` behind would point at a file that is no
     * longer named anywhere.
     *
     * @return void
     */
    public function testDeletingAnAvatarClearsIt(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->uploadAvatar($this->makeImage('avatar-to-delete.png'), 'image/png');
        $this->assertNotEmpty($this->storedAvatar());

        $this->mockSecurity();
        $this->post('/users/htmx-avatar/' . self::USER_ID, ['avatarDelete' => '1']);

        $this->assertNull($this->storedAvatar());
        $this->assertNull(
            TableRegistry::getTableLocator()->get('Users')->get(self::USER_ID)->get('avatar_dir'),
        );
    }

    /**
     * Deleting an avatar that is not there is a no-op, not an error. The button
     * is shown from a cached fragment, so it can be pressed twice.
     *
     * @return void
     */
    public function testDeletingAnAbsentAvatarIsHarmless(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->assertNull($this->storedAvatar());

        $this->mockSecurity();
        $this->post('/users/htmx-avatar/' . self::USER_ID, ['avatarDelete' => '1']);

        $this->assertRedirect();
        $this->assertNull($this->storedAvatar());
    }
}
