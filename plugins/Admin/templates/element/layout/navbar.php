<?php // Alpine rather than Bootstrap's JavaScript — the backend no longer loads
      // jQuery. Every entry is a real link, so without script the menus render
      // open and the navigation still works. ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark" x-data="adminNav"
     x-on:click.outside="openMenu = ''">
    <?= $this->Html->link(__('Forum'), '/', ['class' => 'navbar-brand']); ?>
    <button class="navbar-toggler" type="button" x-on:click="toggle()"
            x-bind:aria-expanded="expanded ? 'true' : 'false'"
            aria-controls="navbar-toggle" aria-label="<?= h(__('Menu')) ?>">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbar-toggle"
         x-bind:class="expanded ? 'show' : ''">
        <ul class="navbar-nav">
            <li class="nav-item <?= preg_match('/\/admin$/', $this->request->getRequestTarget()) ? 'active' : '' ?>">
                <?= $this->Html->link(__('Overview'), '/admin/', ['class' => 'nav-link']) ?>
            </li>
            <li class="nav-item <?= stristr($this->request->getRequestTarget(), 'settings') ? 'active' : '' ?>">
                <?= $this->Html->link(__('Settings'), '/admin/settings/index', ['class' => 'nav-link']); ?>
            </li>
            <li class="nav-item dropdown <?= stristr($this->request->getRequestTarget(), 'users') ? 'active' : '' ?>">
                <?php
                echo $this->Html->link(
                    __('Users'),
                    '/admin/users/index',
                    [
                        'class' => 'nav-link dropdown-toggle',
                        'x-on:click.prevent' => "menu('users')",
                        'x-bind:aria-expanded' => "isOpen('users') ? 'true' : 'false'",
                        'aria-haspopup' => 'true',
                    ]
                );
                echo $this->Html->nestedList(
                    [
                        $this->Html->link(
                            __('Users'),
                            '/admin/users/index',
                            ['class' => 'dropdown-item']
                        ),
                        $this->Html->link(
                            __('user.block.history'),
                            '/admin/users/block',
                            ['class' => 'dropdown-item']
                        ),
                    ],
                    ['class' => 'dropdown-menu', 'x-bind:class' => "isOpen('users') ? 'show' : ''"]
                );
                ?>
            </li>
            <li class="nav-item <?= stristr($this->request->getRequestTarget(), 'categories') ? 'active' : '' ?>">
                <?= $this->Html->link(__('Categories'), '/admin/categories/index', ['class' => 'nav-link']); ?>
            </li>
            <li class="nav-item <?= stristr($this->request->getRequestTarget(), 'smilies') ? 'active' : '' ?>">
                <?= $this->Html->link(__('Smilies'), '/admin/smilies/index', ['class' => 'nav-link']) ?>
            </li>
            <?php
            //= plugins
            $items = $SaitoEventManager->dispatch('saito.plugin.admin.plugins.request');
            if ($items) {
                $dropdown = $this->Html->link(
                    __('Plugins'),
                    '#',
                    [
                        'class' => 'nav-link dropdown-toggle',
                        'x-on:click.prevent' => "menu('plugins')",
                        'x-bind:aria-expanded' => "isOpen('plugins') ? 'true' : 'false'",
                        'aria-haspopup' => 'true',
                    ]
                );

                $plugins = [];
                $plugins[] = $this->Html->link(
                    __('Plugins'),
                    '/admin/plugins',
                    ['class' => 'dropdown-item']
                );
                $plugins[] = '<div class="dropdown-divider"></div>';
                foreach ($items as $item) {
                    $plugins[] = $this->Html->link(
                        $item['title'],
                        $item['url'],
                        ['class' => 'dropdown-item']
                    );
                }
                $dropdown .= $this->Html->nestedList(
                    $plugins,
                    ['class' => 'dropdown-menu', 'x-bind:class' => "isOpen('plugins') ? 'show' : ''"]
                );

                $active = stristr($this->request->getRequestTarget(), 'plugin') ? ' active' : '';
                $dropdown = $this->Html->tag('li', $dropdown, ['class' => 'dropdown' . $active]);
                echo $dropdown;
            }
            ?>
        </ul>
    </div>
</nav>
