<?php
/**
 * The thread, flattened ("mix" renderer), without any page furniture.
 *
 * Used when the mix button expands a thread in place: one request returns every
 * posting of the thread at once, instead of opening each posting inline with a
 * request of its own.
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface $entries
 */

echo $this->Posting->renderThread($entries, ['renderer' => 'mix']);
