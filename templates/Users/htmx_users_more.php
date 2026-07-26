<?php
/**
 * htmx fragment for "load more" on the member list: the next page's rows plus a
 * fresh control for the page after. Replaces the previous control in place, so
 * the pages stack into one continuous table.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User[] $users
 */

echo $this->element('users/htmx_user_rows', compact('users'));
echo $this->element('users/htmx_user_more', compact('users'));
