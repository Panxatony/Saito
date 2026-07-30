<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Plugin\BbcodeParser\src\Lib\jBBCode\Definitions;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Plugin\BbcodeParser\src\Lib\Helper\Message;
use Plugin\BbcodeParser\src\Lib\Helper\UrlParserTrait;
use Plugin\BbcodeParser\src\Lib\Http\SsrfGuard;
use Plugin\BbcodeParser\src\Lib\Http\SsrfGuardedClient;
use Saito\DomainParser;

/**
 * Class Email handles [email]foo@bar.com[/email]
 *
 * @package Saito\Jbb\CodeDefinition
 */
class Email extends CodeDefinition
{
    use UrlParserTrait;

    protected $_sParseContent = false;

    protected $_sTagName = 'email';

    /**
     * {@inheritDoc}
     */
    protected function _parse($url, $attributes, \JBBCode\ElementNode $node)
    {
        return $this->_email($url);
    }
}

/**
 * Class EmailWithAttributes handles [email=foo@bar.com]foobar[/email]
 *
 * @package Saito\Jbb\CodeDefinition
 */
//@codingStandardsIgnoreStart
class EmailWithAttributes extends Email
//@codingStandardsIgnoreEnd
{
    protected $_sUseOptions = true;

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        return $this->_email($attributes['email'], $content);
    }
}

//@codingStandardsIgnoreStart
class Embed extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    protected $_sTagName = 'embed';

    protected $_sParseContent = false;

    /**
     * {@inheritDoc}
     */
    protected function _parse($url, $attributes, \JBBCode\ElementNode $node)
    {
        if (!$this->_sOptions->get('content_embed_active')) {
            return $this->_notEmbedded($url);
        }

        // A) Optional provider allowlist. When configured, only listed hosts
        // (and their sub-domains) are embedded; anything else falls back to a
        // link. Empty (default) imposes no host restriction — the guarded
        // dispatcher below still blocks internal targets, so SSRF is closed
        // either way.
        if (!$this->_isHostAllowlisted($url)) {
            return $this->_notEmbedded($url);
        }

        $loader = function () use ($url) {
            $embed = ['url' => $url];

            // SSRF guard: never let the server fetch a URL that points at an
            // internal/loopback/link-local address (cloud metadata, intranet,
            // localhost services). On rejection we return the bare URL unfetched.
            if (!self::_isFetchableUrl($url)) {
                return $embed;
            }

            try {
                // B) Fetch through the SSRF-guarded PSR-18 client: it follows
                // redirects manually, re-validates and IP-pins every hop, and
                // so closes the DNS-rebinding / redirect-to-internal gaps that
                // the up-front _isFetchableUrl() check alone cannot cover. In
                // embed v4 the client is injected via the Crawler and handles
                // both the page fetch and the preview-image fetches.
                $embedder = new \Embed\Embed(
                    new \Embed\Http\Crawler(new SsrfGuardedClient())
                );
                $info = $embedder->get($url);

                // v4 exposes typed values (EmbedCode / UriInterface); cast to
                // strings for the JSON payload the front-end consumes.
                $code = $info->code;
                $embed = [
                    'html' => $code !== null ? $code->html : '',
                    'providerIcon' => (string)($info->favicon ?? ''),
                    'providerName' => (string)($info->providerName ?? ''),
                    'providerUrl' => (string)($info->providerUrl ?? ''),
                    'title' => (string)($info->title ?? ''),
                    'url' => (string)($info->url ?? $url),
                ];

                if ($this->_sOptions->get('content_embed_text')) {
                    $embed['description'] = (string)($info->description ?? '');
                }

                if ($this->_sOptions->get('content_embed_media')) {
                    $embed['image'] = (string)($info->image ?? '');
                }
            } catch (\Throwable $e) {
            }

            return $embed;
        };

        $callable = \Closure::fromCallable($loader);

        $uid = 'embed-' . md5($url); // DOM id for the embed, not password hashing skipcq: PHP-A1004
        $info = Cache::remember($uid, $callable, 'bbcodeParserEmbed');

        return $this->_sHelper->Html->div('js-embed', '', ['id' => $uid, 'data-embed' => json_encode($info)]);
    }

    /**
     * The non-embedded rendering of a URL: an autolink, or the bare URL when
     * autolinking is off. Shared by the "embeds disabled" and "host not
     * allowlisted" paths.
     *
     * @param string $url the URL
     * @return string
     */
    private function _notEmbedded(string $url): string
    {
        if (!$this->_sOptions->get('autolink')) {
            return $url;
        }

        return $this->Html->link($url, $url, ['target' => '_blank']);
    }

    /**
     * Whether the URL's host is permitted by the optional embed allowlist.
     *
     * The allowlist is read from the `Saito.embedAllowedHosts` config (an array
     * of host names). Empty — the default — permits every host, leaving the
     * guarded dispatcher as the sole SSRF control. When set, only a listed host
     * or a sub-domain of one is embedded; everything else falls back to a link.
     *
     * @param string $url the [embed] URL
     * @return bool
     */
    private function _isHostAllowlisted(string $url): bool
    {
        $allowed = array_filter(
            array_map('trim', (array)Configure::read('Saito.embedAllowedHosts', []))
        );
        if (!$allowed) {
            return true;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach ($allowed as $entry) {
            $entry = strtolower(ltrim($entry, '.'));
            if ($host === $entry || str_ends_with($host, '.' . $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SSRF pre-check for the [embed] URL fetch.
     *
     * Rejects anything but an http(s) URL whose host resolves *exclusively* to
     * public IP addresses, so an author cannot make the server request internal
     * services or cloud-metadata endpoints (e.g. http://169.254.169.254/…,
     * http://127.0.0.1:6379/…) via [embed]. This is the fast up-front reject;
     * the per-hop, IP-pinning enforcement (incl. redirects and DNS-rebinding)
     * lives in {@see SsrfGuardedDispatcher}, through which the fetch runs.
     *
     * @param string $url user-supplied URL
     * @return bool true if the URL is safe to fetch server-side
     */
    private static function _isFetchableUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (
            $parts === false
            || empty($parts['scheme'])
            || empty($parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            return false;
        }

        return SsrfGuard::isPublicHost($parts['host']);
    }
}

//@codingStandardsIgnoreStart
class Iframe extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    use UrlParserTrait;

    protected $_sTagName = 'iframe';

    protected $_sParseContent = false;

    protected $_sUseOptions = true;

    /**
     * Array with domains from which embedding video is allowed
     *
     * array(
     *  'youtube' => 1,
     *  'vimeo' => 1,
     * );
     *
     * array('*' => 1) means every domain allowed
     *
     * @var array
     */
    protected $_allowedVideoDomains = null;

    /**
     * {@inheritDoc}
     */
    protected function _parse($url, $attributes, \JBBCode\ElementNode $node)
    {
        if (empty($attributes['src'])) {
            return false;
        }

        unset($attributes['iframe']);

        // The host allowlist is not a scheme check, and it steps aside entirely
        // when an admin sets `video_domains_allowed` to `*` — a documented
        // value. Without this, `[iframe src=javascript:…]` on such an install
        // renders a frame that runs in the forum's own origin. `[img]` and
        // `[url]` have had this guard for a while; the frame tags were missed.
        if (!$this->_hasSafeUrlScheme($attributes['src'])) {
            return false;
        }

        $allowed = $this->_checkHostAllowed($attributes['src']);
        if ($allowed !== true) {
            return $allowed;
        }

        if (strpos($attributes['src'], '?') === false) {
            $attributes['src'] .= '?';
        }
        $attributes['src'] .= '&amp;wmode=Opaque';

        // Emit only a fixed allowlist of iframe attributes. Attribute *values*
        // are h()-escaped upstream (BbcodePreparePreprocessor), but the BBCode
        // author can also inject arbitrary attribute *names* — e.g. an
        // "onmouseover" event handler, which needs no HTML-special characters
        // and would otherwise pass through verbatim as stored XSS. Anything not
        // on the allowlist (and any on*-handler) is dropped.
        $allowedAttributes = [
            'src', 'width', 'height', 'frameborder',
            'allow', 'allowfullscreen', 'title',
        ];
        $atrStr = '';
        foreach ($attributes as $attributeName => $attributeValue) {
            if (!in_array(strtolower((string)$attributeName), $allowedAttributes, true)) {
                continue;
            }
            $atrStr .= "$attributeName=\"$attributeValue\" ";
        }
        $atrStr = rtrim($atrStr);

        $html = <<<eof
<div class="embed-responsive embed-responsive-16by9">
    <iframe class="embed-responsive-item" {$atrStr}></iframe>
</div>
eof;

        return $html;
    }

    /**
     * get allowed domains
     *
     * @return array
     */
    protected function _allowedDomains()
    {
        if ($this->_allowedVideoDomains !== null) {
            return $this->_allowedVideoDomains;
        }

        $ad = explode('|', $this->_sOptions->get('video_domains_allowed'));
        $trim = function ($v) {
            return trim($v);
        };
        $this->_allowedVideoDomains = array_fill_keys(array_map($trim, $ad), 1);

        return $this->_allowedVideoDomains;
    }

    /**
     * Check host allowed
     *
     * @param string $url url
     *
     * @return bool|string
     */
    protected function _checkHostAllowed($url)
    {
        $allowedDomains = $this->_allowedDomains();
        if (empty($allowedDomains)) {
            return false;
        }

        if ($allowedDomains === ['*' => 1]) {
            return true;
        }

        $host = DomainParser::domain($url);
        if ($host && isset($allowedDomains[$host])) {
            return true;
        }

        $message = sprintf(
            __('Domain <strong>%s</strong> not allowed for embedding video.'),
            $host
        );

        return Message::format($message);
    }
}

//@codingStandardsIgnoreStart
class Flash extends Iframe
//@codingStandardsIgnoreEnd
{
    protected $_sTagName = 'flash_video';

    protected $_sParseContent = false;

    protected $_sUseOptions = false;

    protected static $_flashVideoDomainsWithHttps = [
        'vimeo' => 1,
        'youtube' => 1,
    ];

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        $match = preg_match(
            "#(?P<url>.+?)\|(?P<width>.+?)\|(?<height>\d+)#is",
            $content,
            $matches
        );
        if (!$match) {
            return Message::format(__('No Flash detected.'));
        }

        $height = $matches['height'];
        $url = $matches['url'];
        $width = $matches['width'];

        $allowed = $this->_checkHostAllowed($url);
        if ($allowed !== true) {
            return $allowed;
        }

        if (env('HTTPS')) {
            $host = DomainParser::domain($url);
            if (isset(self::$_flashVideoDomainsWithHttps[$host])) {
                $url = str_ireplace('http://', 'https://', $url);
            }
        }

        // $url and $width are already HTML-encoded by BbcodePreparePreprocessor (h())
        // $height is already constrained to digits by the regex (?<height>\d+)
        $out = '<object classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000" width="' . $width . '" height="' . $height . '">
									<param name="movie" value="' . $url . '"></param>
									<embed src="' . $url . '" width="' . $width . '" height="' . $height . '" type="application/x-shockwave-flash" wmode="opaque" style="width:' . $width . 'px; height:' . $height . 'px;" id="VideoPlayback" flashvars=""> </embed> </object>';

        return $out;
    }
}

//@codingStandardsIgnoreStart
class FileWithAttributes extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    use UrlParserTrait;

    protected $_sTagName = 'file';

    protected $_sParseContent = false;

    protected $_sUseOptions = true;

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        if (empty($attributes['src']) || $attributes['src'] !== 'upload') {
            $message = sprintf(__('File not allowed.'));

            return Message::format($message);
        }

        $url = $this->_linkToUploadedFile($content);

        return $this->_sHelper->Html->link($content, $url, ['target' => '_blank']);
    }
}

//@codingStandardsIgnoreStart
class Image extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    use UrlParserTrait;

    protected $_sTagName = 'img';

    protected $_sParseContent = false;

    /**
     * {@inheritDoc}
     */
    protected function _parse($url, $attributes, \JBBCode\ElementNode $node)
    {
        // image is internaly uploaded
        if (!empty($attributes['src']) && $attributes['src'] === 'upload') {
            $url = $this->_linkToUploadedFile($url);
        }

        // process [img=(parameters)]
        $options = [];
        if (!empty($attributes['img'])) {
            $default = trim($attributes['img']);
            switch ($default) {
                default:
                    preg_match(
                        '/(\d{0,3})(?:x(\d{0,3}))?/i',
                        $default,
                        $dimension
                    );
                    // $dimension for [img=50] or [img=50x100]
                    // [0] (50) or (50x100)
                    // [1] (50)
                    // [2] (100)
                    if (!empty($dimension[1])) {
                        $options['width'] = $dimension[1];
                        if (!empty($dimension[2])) {
                            $options['height'] = $dimension[2];
                        }
                    }
            }
        }

        $url = $this->_urlToHttps($url);

        // Reject dangerous schemes (javascript:, data:, …). A top-level [img]
        // wraps the image in a link whose href is this URL, so an unvalidated
        // "javascript:" URL would be click-to-XSS.
        if (!$this->_hasSafeUrlScheme($url)) {
            return '';
        }

        $image = $this->Html->image($url, $options);

        if ($node->getParent()->getTagName() === 'Document') {
            $image = $this->_sHelper->Html->link(
                $image,
                $url,
                ['escape' => false, 'target' => '_blank']
            );
        }

        return $image;
    }
}

//@codingStandardsIgnoreStart
class ImageWithAttributes extends Image
//@codingStandardsIgnoreEnd
{
    protected $_sUseOptions = true;
}

/**
 * Class UlList handles [list][*]…[/list]
 *
 * @see https://gist.github.com/jbowens/5646994
 * @package Saito\Jbb\CodeDefinition
 */
//@codingStandardsIgnoreStart
class UlList extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    protected $_sTagName = 'list';

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        $listPieces = explode('[*]', $content);
        unset($listPieces[0]);
        $listPieceProcessor = function ($li) {
            return '<li>' . $li . '</li>' . "\n";
        };
        $listPieces = array_map($listPieceProcessor, $listPieces);

        return '<ul>' . implode('', $listPieces) . '</ul>';
    }
}

//@codingStandardsIgnoreStart
class Spoiler extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    protected $_sTagName = 'spoiler';

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        $length = mb_strlen(strip_tags($content));
        $minLenght = mb_strlen(__('Spoiler')) + 4;
        if ($length < $minLenght) {
            $length = $minLenght;
        }

        $title = $this->_mbStrpad(
            ' ' . __('Spoiler') . ' ',
            $length,
            '▇',
            STR_PAD_BOTH
        );

        // Escape HTML-special chars to prevent injection. What is revealed is
        // therefore the literal text of the spoiler, nested markup included —
        // long-standing behaviour, kept.
        $spoilerContent = htmlentities($content);

        // The content travels in a data attribute and is revealed by a delegated
        // handler in the island, not by an inline `onclick`. Both the handler and
        // the `display: inline` used to be inline attributes, which only worked
        // because the CSP still allows 'unsafe-inline'; that allowance is due to
        // go, and a spoiler that silently stopped opening would have been a hard
        // bug to trace back to a header change.
        //
        // Written raw, not through h(): htmlentities() has already escaped the
        // quotes, so it cannot break out of the attribute — and escaping it a
        // second time would survive one decode too many and show the reader
        // `&quot;` where the author wrote `"`.
        $out = <<<EOF
<div class="richtext-spoiler">
	<a href="#" class="richtext-spoiler-link js-spoiler" data-spoiler="$spoilerContent">
		$title
	</a>
</div>
EOF;

        return $out;
    }

    /**
     * Strpad
     *
     * @see http://www.php.net/manual/en/function.str-pad.php#111147
     *
     * @param string $str string
     * @param int $padLen length
     * @param string $padStr padding
     * @param int $dir direction
     *
     * @return null|string
     */
    protected function _mbStrpad(
        $str,
        $padLen,
        $padStr = ' ',
        $dir = STR_PAD_RIGHT
    ) {
        $strLen = mb_strlen($str);
        $padStrLen = mb_strlen($padStr);
        if (!$strLen && ($dir == STR_PAD_RIGHT || $dir == STR_PAD_LEFT)) {
            $strLen = 1; // @debug
        }
        if (!$padLen || !$padStrLen || $padLen <= $strLen) {
            return $str;
        }

        $result = null;
        $repeat = (int)ceil($strLen - $padStrLen + $padLen);
        if ($dir == STR_PAD_RIGHT) {
            $result = $str . str_repeat($padStr, $repeat);
            $result = mb_substr($result, 0, $padLen);
        } else {
            if ($dir == STR_PAD_LEFT) {
                $result = str_repeat($padStr, $repeat) . $str;
                $result = mb_substr($result, -$padLen);
            } else {
                if ($dir == STR_PAD_BOTH) {
                    $length = ($padLen - $strLen) / 2;
                    $repeat = (int)ceil($length / $padStrLen);
                    $result = mb_substr(str_repeat($padStr, $repeat), 0, (int)floor($length)) .
                        $str .
                        mb_substr(str_repeat($padStr, $repeat), 0, (int)ceil($length));
                }
            }
        }

        return $result;
    }
}

/**
 * Handles [upload]<image>[/upload]
 *
 * @deprecated since Saito 5.2 — nothing writes this tag any more. It is not
 *     removable, though, and "kept for backwards compatibility" undersells why:
 *     counted on the live forum on 2026-07-30, **10,901 postings** still contain
 *     `[upload]`, written between 2010 and 2020. Deleting this class does not
 *     tidy anything up; it stops eleven thousand postings from rendering their
 *     images.
 */
//@codingStandardsIgnoreStart
class Upload extends Image
//@codingStandardsIgnoreEnd
{
    protected $_sTagName = 'upload';

    /**
     * {@inheritDoc}
     */
    protected function _parse($content, $attributes, \JBBCode\ElementNode $node)
    {
        $attributes['src'] = 'upload';
        if (!empty($attributes['width'])) {
            $attributes['img'] = $attributes['width'];
        }
        if (!empty($attributes['height'])) {
            $attributes['img'] .= 'x' . $attributes['height'];
        }

        return parent::_parse($content, $attributes, $node);
    }
}

/**
 * Handles [upload width=<width> height=<height>]<image>[/upload]
 *
 * @deprecated since Saito 5.2 — the attribute form of the tag above. Rarer: one
 *     posting on the live forum carries it. Kept for the same reason, and
 *     because `Upload` extends it into existence anyway.
 */
//@codingStandardsIgnoreStart
class UploadWithAttributes extends Upload
//@codingStandardsIgnoreEnd
{
    protected $_sUseOptions = true;
}

/**
 * Class Url handles [url]http://example.com[/url]
 *
 * @package Saito\Jbb\CodeDefinition
 */
//@codingStandardsIgnoreStart
class Url extends CodeDefinition
//@codingStandardsIgnoreEnd
{
    use UrlParserTrait;

    protected $_sParseContent = false;

    protected $_sTagName = 'url';

    /**
     * {@inheritDoc}
     */
    protected function _parse($url, $attributes, \JBBCode\ElementNode $node)
    {
        $defaults = ['label' => true];
        // parser may return $attributes = null
        if (empty($attributes)) {
            $attributes = [];
        }
        $attributes = $attributes + $defaults;

        return $this->_getUrl($url, $attributes);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getUrl($content, $attributes)
    {
        $shortTag = true;

        return $this->_url($content, $content, $attributes['label'], $shortTag);
    }
}

/**
 * Class Link handles [link]http://example.com[/link]
 *
 * @package Saito\Jbb\CodeDefinition
 */
//@codingStandardsIgnoreStart
class Link extends Url
//@codingStandardsIgnoreEnd
{
    protected $_sTagName = 'link';
}

/**
 * Class UrlWithAttributes handles [url=http://example.com]foo[/url]
 *
 * @package Saito\Jbb\CodeDefinition
 */
//@codingStandardsIgnoreStart
class UrlWithAttributes extends Url
//@codingStandardsIgnoreEnd
{
    protected $_sParseContent = true;

    protected $_sUseOptions = true;

    /**
     * {@inheritDoc}
     */
    protected function _getUrl($content, $attributes)
    {
        $shortTag = false;
        $url = $attributes[$this->_sTagName];

        return $this->_url($url, $content, $attributes['label'], $shortTag);
    }
}

/**
 * Class LinkWithAttributes handles [link=http://example.com]foo[/link]
 *
 * @package Saito\Jbb\CodeDefinition
 */
//@codingStandardsIgnoreStart
class LinkWithAttributes extends UrlWithAttributes
//@codingStandardsIgnoreEnd
{
    protected $_sTagName = 'link';
}
