<?php

use Cake\Routing\Router;

$feed = $this->Feeds->getFeed();

// Full-base URL (no trailing slash) for absolutizing site-relative links below.
$base = rtrim(Router::url('/', true), '/');

foreach ($entries as $entry) {
    // Absolute URL (fullBase): RSS item links/guids must be fully-qualified.
    $url = Router::url('/entries/htmx-posting/' . $entry->get('id'), true);
    // Render the body as HTML (delivered as CDATA) so feed readers show
    // embedded images. In text mode jBBCode's getAsText() strips every tag to
    // its inner text, so an uploaded image ([img src=upload]<file>[/img])
    // collapsed to the bare filename. Uploaded images resolve to a full-base
    // /useruploads/ URL, so they load in a reader.
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

    $subject = trim(html_entity_decode((string)$entry->get('subject'), ENT_NOQUOTES, 'UTF-8'));
    $body = trim($body);

    // The writer refuses an empty title *and* an empty description, where the
    // previous library wrote both as empty elements. Real postings hit this: a
    // reply commonly has no subject. RSS asks for a title *or* a description,
    // so each is set only when present — and an item with neither says nothing
    // a reader could show, so it is left out rather than made to throw.
    if ($subject === '' && $body === '') {
        continue;
    }

    $item = $feed->createEntry();
    if ($subject !== '') {
        $item->setTitle($subject);
    }
    if ($body !== '') {
        $item->setDescription($body);
    }
    $item->setLink($url);
    // The item identity subscribers are keyed on. It has been the posting URL
    // since the feed existed and must stay byte-identical: change it and every
    // reader re-announces every posting it already showed.
    $item->setId($url);
    $item->setDateModified($entry->get('time')->getTimestamp());
    // Only when there is one. The writer refuses an empty author name, where
    // the previous library emitted an empty `dc:creator` element instead —
    // which no reader could do anything with either.
    $name = trim((string)$entry->get('name'));
    if ($name !== '') {
        $item->addAuthor(['name' => $name]);
    }
    $feed->addEntry($item);
}

echo $this->Feeds->render();
