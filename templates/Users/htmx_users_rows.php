<?php
/**
 * htmx fragment: the whole sortable member list (sort menu + table), swapped
 * into #js-userList on a sort click. Rendered without a layout (see
 * UsersController::htmxUsers()).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User[] $users
 * @var array $menuItems
 */

echo $this->element('users/htmx_user_list', compact('users', 'menuItems'));
