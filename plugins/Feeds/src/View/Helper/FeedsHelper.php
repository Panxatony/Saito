<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\View\Helper;

use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Routing\Router;
use Cake\View\Helper;
use DOMDocument;
use DOMText;
use DOMXPath;
use Laminas\Feed\Writer\Feed;

/**
 * Builds the feed document the RSS templates fill with postings.
 *
 * There is no separate channel object here: in `Laminas\Feed\Writer` the feed
 * *is* the channel, and items are created from it. The previous library,
 * `suin/php-rss-writer`, modelled the two separately — hence the shape of the
 * templates before 8.4.10.
 */
class FeedsHelper extends Helper
{
    /** Namespace of the comment-count extension the writer insists on emitting. */
    private const SLASH_NS = 'http://purl.org/rss/1.0/modules/slash/';

    private ?Feed $feed = null;

    /**
     * @inheritDoc
     */
    public function beforeRender(EventInterface $event, $viewFile)
    {
        $this->feed = new Feed();
        $this->feed->setEncoding('UTF-8');
        $this->feed->setTitle((string)$this->getView()->get('titleForLayout'));
        $this->feed->setLink(Router::url('/', true));
        // Absolute, unlike before: RSS puts this in `atom:link rel="self"`, and
        // a reader has no base URI to resolve a path against. The old library
        // took whatever it was handed and wrote a bare `/feeds/...` path.
        $this->feed->setFeedLink(
            Router::url($this->getView()->getRequest()->getRequestTarget(), true),
            'rss',
        );
        $this->feed->setLanguage((string)Configure::read('Saito.language'));
        // RSS requires a channel description and the writer refuses to export
        // without one. The old library emitted an empty `<description/>`, which
        // is invalid; the forum title is the honest answer and costs nothing.
        $this->feed->setDescription((string)$this->getView()->get('titleForLayout'));
        // Without this the writer advertises itself and its major version. Name
        // the forum instead, and deliberately without a version: the same
        // reasoning that turned `expose_php` off and `server_tokens` on the
        // edge — a public document should not announce what it is built from.
        $this->feed->setGenerator('Saito');
    }

    /**
     * The finished RSS document.
     *
     * Exports the feed and takes `slash:comments` back out again. The writer
     * emits that element for every item whether or not a comment count was
     * ever set, falling back to `0` — so leaving it in would have the forum
     * telling every reader that every posting has no replies, which is simply
     * untrue. There is no way to decline the extension: `registerCoreExtensions()`
     * runs in the constructors of the feed, the entry *and* the renderer, so
     * anything unregistered is registered again before the document is written.
     *
     * Setting a real count was the other option and was dropped: postings carry
     * no reply counter, and in a feed of individual postings the number would
     * mean nothing anyway.
     *
     * @return string
     */
    public function render(): string
    {
        $dom = new DOMDocument();
        $dom->loadXML($this->getFeed()->export('rss'));

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('slash', self::SLASH_NS);
        /** @var \DOMNode $node */
        foreach (iterator_to_array($xpath->query('//slash:comments') ?: []) as $node) {
            $parent = $node->parentNode;
            if ($parent === null) {
                continue;
            }
            // The indentation in front of it goes too, or every item keeps a
            // blank line where the element used to be.
            $before = $node->previousSibling;
            if ($before instanceof DOMText && trim($before->textContent) === '') {
                $parent->removeChild($before);
            }
            $parent->removeChild($node);
        }

        $dom->documentElement?->removeAttributeNS(self::SLASH_NS, 'slash');

        return (string)$dom->saveXML();
    }

    /**
     * The feed being built, creating it if a template asks before beforeRender.
     *
     * @return \Laminas\Feed\Writer\Feed
     */
    public function getFeed(): Feed
    {
        if ($this->feed === null) {
            $this->beforeRender(new Event('View.beforeRender'), null);
        }

        /** @var \Laminas\Feed\Writer\Feed */
        return $this->feed;
    }
}
