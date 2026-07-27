<?php
use Cake\Utility\Inflector;

if (empty($UserBlock)) {
    echo $this->element(
        'generic/no-content-yet',
        ['message' => __('ncy.aub')]
    );

    return;
}
$mode = $mode ?? 'profile';
$format = ($mode === 'full') ? 'eng' : 'normal';
?>
<table id="blocklist"
       class="table <?= ($mode === 'full') ? 'table-striped' : '' ?>">
    <?php
    $headers = [
        __('user.block.active'),
        __('user.block.reason'),
        __('user.block.start'),
        __('user.block.ended'),
        __('user.block.ends'),
    ];
    if ($mode === 'full') {
        array_unshift($headers, __('user_name'));
    }
    $headers[] = __('user.block.unblock');
    echo $this->Html->tag('thead', $this->Html->tableHeaders($headers));

    $cells = [];
    foreach ($UserBlock as $block) {
        $domain = null;
        $reason = (string)$block->get('reason');
        if (strpos($reason, '.') !== false) {
            list($domain, $reason) = explode('.', $reason);
            $domain = Inflector::underscore($domain);
        }
        if ($domain) {
            $reason = __d($domain, "user.block.reason.{$reason}");
        } else {
            $by = $this->User->linkToUserProfile(
                $block->get('blocked_by'),
                $CurrentUser
            );
            $reason = __('user.block.reason.1', $by);
        }

        $cell = [
            empty($block['ended']) ? '✓' : '–',
            $reason,
            $this->TimeH->formatTime($block->get('created'), $format),
            empty($block['ended']) ? '' : $this->TimeH->formatTime(
                $block->get('ended'),
                $format
            ),
            empty($block['ends']) ? '' : $this->Time->timeAgoInWords(
                $block->get('ends'),
                [
                    'accuracy' => 'hour',
                    'relativeStringFuture' => __d('cake', 'in %s'),
                ]
            ),
        ];

        if ($mode === 'full') {
            array_unshift(
                $cell,
                $this->User->linkToUserProfile(
                    $block->get('user'),
                    $this->get('CurrentUser')
                )
            );
        }

        $unblock = '';
        if (empty($block['ended'])) {
            // `plugin => false`, not `admin => false`. This element is rendered
            // both on the profile page and inside the Admin plugin's block
            // list; the old key is a CakePHP 2/3 prefix idiom that stopped
            // resetting anything, so from the backend the link pointed at
            // /admin/users/unlock/<id> — an action that does not exist there.
            // Unblocking from the backend was quietly broken.
            $unblock = $this->Form->postLink(
                __('user.block.unblock'),
                [
                    'plugin' => false,
                    'controller' => 'Users',
                    'action' => 'unlock',
                    $block['id'],
                ]
            );
        }
        $cell[] = $unblock;

        $cells[] = $cell;
    }
    echo $this->Html->tag('tbody', $this->Html->tableCells($cells));
    ?>
</table>
