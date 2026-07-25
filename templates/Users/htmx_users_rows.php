<?php
/**
 * htmx fragment: just the member-list table rows, for in-place sorting.
 * Rendered without a layout (see UsersController::htmxUsers()).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User[] $users
 */

echo $this->element('users/htmx_user_rows', ['users' => $users]);
