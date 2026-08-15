<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Admin\Test\TestCase\Controller;

use Admin\Controller\SettingsController;
use Cake\I18n\I18n;
use Cake\I18n\Parser\PoFileParser;
use Cake\TestSuite\TestCase;
use ReflectionProperty;

/**
 * Every setting shown to an administrator has to be readable.
 *
 * A missing translation is not an error in the technical sense — CakePHP hands
 * back the key and everything keeps working — so nothing fails, nothing logs,
 * and the only way it surfaces is somebody looking at the screen. That is how
 * `2fa_required_from_role` reached the test system showing
 * `2fa_required_from_role`, `2fa_required_from_role_exp` and
 * `2fa_required_from_role.off` as its label, its explanation and its only
 * option: the strings existed, in `default.po`, and the admin area reads the
 * `nondynamic` domain.
 *
 * The explanation is the part most easily forgotten, because nothing references
 * it in code — the settings table appends `_exp` to the name and prints
 * whatever comes back. For a setting whose consequences include locking the
 * operator out of their own forum, that paragraph is not decoration.
 */
class SettingsTranslationTest extends TestCase
{
    /**
     * The settings the admin index offers, straight from the controller so this
     * cannot drift from what is actually shown.
     *
     * @return array<string, mixed>
     */
    private function shownSettings(): array
    {
        // The declared default, not an instance: a controller needs a request
        // to be constructed, and this list is a constant in all but name.
        $property = new ReflectionProperty(SettingsController::class, 'settingsShownInAdminIndex');

        /** @var array<string, mixed> $settings */
        $settings = $property->getDefaultValue();

        return $settings;
    }

    /**
     * @param string $locale language
     * @return array<string, mixed> the parsed nondynamic catalogue
     */
    private function catalogue(string $locale): array
    {
        return (new PoFileParser())->parse(ROOT . DS . 'src' . DS . 'Locale' . DS . $locale . DS . 'nondynamic.po');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function localeProvider(): array
    {
        return [['de'], ['en']];
    }

    /**
     * Both the label and the explanation, for every setting and every language
     * the forum ships.
     *
     * Reported as one list rather than failing on the first, so adding a
     * setting and running this once tells you everything that is missing
     * instead of one key at a time.
     *
     * @param string $locale language
     * @return void
     * @dataProvider localeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testEverySettingHasALabelAndAnExplanation(string $locale): void
    {
        $catalogue = $this->catalogue($locale);
        $missing = [];

        foreach (array_keys($this->shownSettings()) as $name) {
            foreach ([$name, $name . '_exp'] as $key) {
                if (!isset($catalogue[$key])) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "These keys are missing from src/Locale/%s/nondynamic.po, so the admin\n"
                . "settings page will print the key itself where the text belongs:\n  %s",
                $locale,
                implode("\n  ", $missing),
            ),
        );
    }

    /**
     * A setting offered as a list needs a readable label per option.
     *
     * `off`, `mod` and `admin` say nothing on their own — least of all whether
     * `mod` means "moderators only" or "moderator and above", which is exactly
     * the question somebody is answering when they open that menu.
     *
     * @param string $locale language
     * @return void
     * @dataProvider localeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testEveryChoiceInASelectHasALabel(string $locale): void
    {
        $catalogue = $this->catalogue($locale);
        $missing = [];

        foreach ($this->shownSettings() as $name => $config) {
            if (!is_array($config) || ($config['type'] ?? null) !== 'select') {
                continue;
            }
            foreach ($config['options'] ?? [] as $option) {
                $key = $name . '.' . $option;
                if (!isset($catalogue[$key])) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing, implode(', ', $missing));
    }

    /**
     * The catalogues have to agree with each other, or a setting is readable in
     * one language and raw in the other — which is how this class of fault
     * hides: the person who added it reads the language they tested in.
     *
     * @return void
     */
    public function testTheLanguagesCoverTheSameSettings(): void
    {
        $de = $this->catalogue('de');
        $en = $this->catalogue('en');

        $names = array_keys($this->shownSettings());
        $onlyEnglish = [];
        foreach ($names as $name) {
            foreach ([$name, $name . '_exp'] as $key) {
                if (isset($en[$key]) && !isset($de[$key])) {
                    $onlyEnglish[] = $key;
                }
            }
        }

        $this->assertSame([], $onlyEnglish, implode(', ', $onlyEnglish));
    }

    public function tearDown(): void
    {
        I18n::setLocale(I18n::getDefaultLocale());
        parent::tearDown();
    }
}
