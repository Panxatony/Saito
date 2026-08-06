<?php
/**
 * The terms themselves: a forum's own `Saito.tos`, or the shipped default.
 *
 * Its own element because two places show the same text — the terms page
 * (/pages/tos) and the re-consent interstitial a member sees after the operator
 * raises `tos_version`. Agreeing to one thing and reading another would be a
 * bug of exactly the kind nobody notices.
 *
 * @var \App\View\AppView $this
 */
use Cake\Core\Configure;

$tos = (string)Configure::read('Saito.tos');
if ($tos !== '') {
    // Trusted, operator-set HTML — the same contract as the imprint.
    echo $tos;
} else {
    echo $this->element('pages/tos_default');
}
