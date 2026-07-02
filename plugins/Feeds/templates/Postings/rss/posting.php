<?php

use Cake\Routing\Router;
use Suin\RSSWriter\Item;

$channel = $this->Feeds->getChannel();
$feed = $this->Feeds->getFeed();

// Full-base URL (no trailing slash) for absolutizing site-relative links below.
$base = rtrim(Router::url('/', true), '/');

foreach ($entries as $entry) {
    // Absolute URL (fullBase): RSS item links/guids must be fully-qualified.
    $url = Router::url('/entries/view/' . $entry->get('id'), true);
    // Render the body as HTML (delivered as CDATA, see preferCdata below) so
    // feed readers show embedded images. In text mode jBBCode's getAsText()
    // strips every tag to its inner text, so an uploaded image
    // ([img src=upload]<file>[/img]) collapsed to the bare filename. Uploaded
    // images resolve to a full-base /useruploads/ URL, so they load in a reader.
    $body = $this->Parser->parse($entry->get('text'), ['return' => 'html']);
    // A feed reader has no site to resolve root-relative URLs against, so make
    // src/href that start with a single "/" absolute (smilies, @user/#id links,
    // relative [img]s). Protocol-relative (//) and absolute URLs are untouched.
    $body = preg_replace_callback(
        '#(\b(?:src|href)=)(["\'])(/(?!/)[^"\']*)\2#i',
        function (array $m) use ($base): string {
            return $m[1] . $m[2] . $base . $m[3] . $m[2];
        },
        $body
    );
    (new Item())
        ->title(html_entity_decode($entry->get('subject'), ENT_NOQUOTES, 'UTF-8'))
        ->description($body)
        ->url($url)
        ->creator($entry->get('name'))
        ->pubDate(strtotime($entry->get('time')))
        ->pubDate($entry->get('time')->getTimestamp())
        ->guid($url, true)
        ->preferCdata(true)
        ->appendTo($channel);
}

echo $feed->render();
