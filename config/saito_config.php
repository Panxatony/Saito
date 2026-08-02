<?php

/**
 * Saito Enduser Configuration
 */
$config = [
    'Saito' => [
        /**
         * Is the forum installed? Runs installer if not. Default: run installer.
         */
        'installed' => filter_var(
            env('INSTALLED', !file_exists(CONFIG . '/installer')),
            FILTER_VALIDATE_BOOLEAN
        ),
        /**
         * Is the forum up-to-date? Run updater if not. Default: run updater.
         */
        'updated' => filter_var(env('UPDATED', false), FILTER_VALIDATE_BOOLEAN),
        /**
         * Setting default language (mandatory)
         *
         * Compatibel to PHP's Locale. Implemented localizations:
         *
         * - de German
         * - en English
         *
         * @see http://php.net/manual/en/intro.intl.php
         * @see https://r12a.github.io/app-subtags/
         */
        'language' => env('SAITO_LANGUAGE', 'en'),

        /**
         * Marks a test/beta deployment.
         *
         * Deliberately separate from `frontend`: the beta ribbon and the
         * "do not send email" default used to hang off `frontend === 'island'`,
         * which meant a live install would inherit both the moment it switched
         * to the island frontend — a corner ribbon reading "Beta" and, far
         * worse, silently stopped sending registration and password-reset mail.
         *
         * Off by default, so production is clean without anybody having to
         * remember an environment variable. Set SAITO_BETA=true on a beta.
         */
        'beta' => filter_var(env('SAITO_BETA', false), FILTER_VALIDATE_BOOLEAN),

        /**
         * Ask search engines not to index this install (robots noindex).
         *
         * Set SAITO_NOINDEX=true on non-public deployments such as the beta, so
         * the test frontend (running on a clone of the live content) is kept out
         * of search results. Belt-and-suspenders with an nginx `X-Robots-Tag`
         * header on the beta vhost. Default: indexable.
         */
        'noindex' => filter_var(env('SAITO_NOINDEX', false), FILTER_VALIDATE_BOOLEAN),

        /**
         * Trust a reverse proxy's X-Forwarded-* headers (real client IP + https
         * scheme). Enable (SAITO_TRUST_PROXY=true) ONLY when the app sits behind
         * a trusted proxy such as the beta edge — a directly-reachable install
         * must leave this off, or clients could spoof their IP (throttle bypass).
         */
        'trustProxy' => filter_var(env('SAITO_TRUST_PROXY', false), FILTER_VALIDATE_BOOLEAN),

        /**
         * Bearer token a Prometheus scraper has to send to /metrics.
         *
         * Empty — the default — means the endpoint answers 404, so an
         * installation that has not asked for it is indistinguishable from one
         * that never had it. Set SAITO_METRICS_TOKEN to something long and
         * random, and restrict the address to the monitoring network at the web
         * server as well: the exposition tells a stranger how many members the
         * forum has, how busy it is and which version it runs.
         */
        'metricsToken' => (string)env('SAITO_METRICS_TOKEN', ''),

        /**
         * Imprint / legal notice (Impressum)
         *
         * Trusted HTML rendered on the /pages/impressum page (linked from the
         * disclaimer footer, like contact and RSS feeds). Environment-specific:
         * set it here per installation. An empty value shows a
         * "not configured" notice instead of the page content.
         */
        'imprint' => '',

        /**
         * Privacy policy (Datenschutzerklärung)
         *
         * Trusted HTML rendered on the /pages/privacy page and linked from the
         * disclaimer footer. Environment-specific like the imprint — what a
         * given installation has to declare depends on its hosting, its
         * analytics and its admin settings (e.g. whether store_ip is on).
         * docs/privacy-policy-template.md lists what Saito itself processes as
         * a starting point. Empty shows a "not configured" notice.
         */
        'privacy' => '',

        /**
         * Custom HTML injected into every page's <head> (e.g. a privacy-friendly
         * analytics snippet). Trusted, operator-controlled — set it per
         * installation. Empty by default (nothing injected).
         */
        'headHtml' => '',

        /**
         * Custom HTML placed between the header bar and the page content, in a
         * `div.ads_top` — the banner slot. Meant for an ad tag, a donation
         * banner or a notice an installation wants above everything else.
         *
         * Trusted, operator-controlled, and rendered unescaped: set it per
         * installation, never from user input. Empty by default, and the
         * container is omitted entirely when it is empty, so an installation
         * that wants no banner gets no stray markup.
         *
         * The class name is `ads_top` because that is what installations
         * carrying a banner already use; keeping it means existing ad code and
         * any CSS written against it continues to work.
         */
        'bannerHtml' => '',

        /**
         * Show the notice above the page explaining the modernised frontend
         * (or, on a beta installation, that this is a throwaway copy).
         *
         * It is meant for the weeks around a switch, when people arrive with a
         * stale cache and a frontend they have never seen. Once that has passed
         * — or on an installation using the beta to try something unrelated,
         * where the notice is just in the way — set this to false.
         */
        'notice' => true,

        /**
         * The second line of that notice: "new here? the help icon is up there".
         *
         * Kept apart from `notice` above on purpose. That one describes a change
         * that happened and stops being true; this one has no expiry date, and
         * when both shared a switch, turning off the stale message took the
         * pointer for newcomers with it.
         *
         * Absent means shown, same as above — leaving the key out is not the
         * same as setting it to false.
         */
        'noticeHelp' => true,

        /**
         * Show the front page's widget rail — who is online, recent postings —
         * to visitors who are not signed in.
         *
         * True keeps what the island frontend has always done. False makes the
         * rail a members-only feature: the rail is not rendered for a guest and
         * the fragment endpoint answers them with nothing, so who is online
         * cannot be read by fetching it directly either. "Your postings" is
         * unaffected — it has always needed an account.
         */
        'widgetsForGuests' => true,

        /**
         * Draw the accent rail beside unread thread lines.
         *
         * A signed-in member's unread postings are marked three ways: the
         * subject keeps the theme's accent colour, the line is set in bold, and
         * a short vertical bar sits at its left edge. The bar is the youngest of
         * the three and the only one some installations never had — a forum
         * whose readers are used to colour alone reads it as clutter.
         *
         * False drops the bar and leaves colour and weight untouched, so unread
         * postings stay just as recognisable. The space the bar occupied is
         * kept transparent, which means switching this does not shift the thread
         * list sideways.
         */
        'unreadRail' => true,

        'Settings' => [
            /**
             * Sets the markup parser
             *
             * Parser hould be placed in app/Plugin/<name>Parser
             */
            'ParserPlugin' => \Plugin\BbcodeParser\src\Lib\Markup::class,
            /**
             * Upload directory root with trailing slash
             */
            'uploadDirectory' => WWW_ROOT . 'useruploads' . DIRECTORY_SEPARATOR,
            /**
             * Category-select in posting-form is prepopulated with a category
             *
             * - true - The first available category is preselected as default.
             * - false - The User is forced to select a category.
             */
            'answeringAutoSelectCategory' => false,
        ],

        /**
         * Themes are plugins located in the plugins/ folder
         *
         * @see http://book.cakephp.org/3.0/en/views/themes.html
         */
        'themes' => [
            /**
             * Sets the default theme
             *
             * Nova is the current default: a modern take on Bota (same
             * Bootstrap base and partials) with a reworked visual layer. Bota
             * ships alongside it and stays selectable — existing installs keep
             * whatever their own config says, this only sets what a fresh
             * installation starts with.
             */
            'default' => 'Nova',

            /**
             * Array with additional themes available for all users
             */
            //'available' => ['MyTheme'],

            /**
             * Sets additional themes available for specific users only
             *
             * [<user-ID> => ['<theme name>', …], …]
             */
            // 'users' => [1 => ['TestTheme']]
        ],

        /**
         * Sets the X-Frame-Options header send with each request
         */
        'X-Frame-Options' => 'SAMEORIGIN',

        'Globals' => [
            /**
             * Empiric number matching the average number of postings per thread
             */
            'postingsPerThread' => 10
        ],
        'debug' => [
            /**
             * Log emails in debug.log instead of sending them.
             *
             * Safety default for a beta (see 'beta' above): it runs on a clone
             * of the live database with real addresses, so email defaults to OFF
             * there — register, notifications and password reset never reach
             * real users. Override with SAITO_DEBUG_EMAIL if a beta really
             * should send. Live installs send (default false).
             */
            'email' => filter_var(
                env('SAITO_DEBUG_EMAIL', env('SAITO_BETA', false)),
                FILTER_VALIDATE_BOOLEAN
            ),
            /**
             * Log additional non-error information in info.log
             */
            'logInfo' => false,
        ],
    ]
];

/**
 * Uploader Configuration
 */

use ImageUploader\Lib\UploaderConfig;

$config['Saito']['Settings']['uploader'] = (new UploaderConfig())
    /**
     * Max number of uploads per user
     */
    ->setMaxNumberOfUploadsPerUser(5000)
    /**
     * Max file size
     */
    ->setDefaultMaxFileSize('8MB')
    /**
     * Threshold file size (and rough target) for resizing images (jpeg/png)
     */
    ->setDefaultMaxResize('650kB')
    /**
     * Image quality factor when resizing images (integer between 0 and 100)
     */
    ->setImageCompressionQuality(92)
    /**
     * Max allowed image resolution in pixels (width * height). Rejects
     * "decompression bomb" images that are small on disk but huge when
     * decoded. 40 MP covers ordinary camera/phone photos.
     */
    ->setMaxImagePixels(40000000)
    /**
     * Allowed mime/types
     */
    ->addType('audio/mpeg')
    ->addType('audio/mp4')
    ->addType('audio/ogg')
    ->addType('audio/opus')
    ->addType('audio/webm')
    ->addType('image/jpeg', '19MB')
    ->addType('image/png', '19MB')
    ->addType('image/webp')
    ->addType('text/plain')
    ->addType('video/mp4')
    ->addType('video/webm');

return $config;
