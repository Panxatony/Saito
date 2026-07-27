<?php
/**
 * htmx fragment: one thread as its tree of subject lines.
 *
 * What `.threadBox-threadTree` holds on the front page — the same renderer, no
 * surrounding box. Requested after a reply so the thread comes back the way it
 * was before, folded, rather than with every posting opened in full.
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface $entries
 */

echo $this->Posting->renderThread($entries, ['currentEntry' => null]);
