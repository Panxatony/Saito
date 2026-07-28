<?php

use Cake\Utility\Text;

?>
<p>
    <?php
    $loggedin = $Stats->getNumberOfRegisteredUsersOnline();
    if ($CurrentUser->isLoggedIn()) {
        // The statistics line sits in the footer of both frontends, so the
        // member-list link has to follow whichever one is running.
        $loggedin = $this->Html->link($loggedin, '/users/htmx-users');
    }
    echo Text::insert(
        __('discl.status'),
        [
            'entries' => number_format(
                $Stats->getNumberOfPostings(),
                0,
                ',',
                '.'
            ),
            'threads' => number_format(
                $Stats->getNumberOfThreads(),
                0,
                ',',
                '.'
            ),
            'registered' => number_format(
                $Stats->getNumberOfRegisteredUsers(),
                0,
                ',',
                '.'
            ),
            'loggedin' => $loggedin,
            'anon' => $Stats->getNumberOfAnonUsersOnline(),
            'bots' => $Stats->getNumberOfBotsOnline(),
        ]
    );

    ?>
</p>
<p>
    <?php
    $user = $Stats->getLatestUser();
    $user = $this->User->linkToUserProfile($user, $CurrentUser);
    echo __('discl.newestMember', $user);
    ?>
</p>
