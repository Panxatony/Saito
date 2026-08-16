<?php
$this->Breadcrumbs->add(__('Users'), '/admin/users');
$this->Breadcrumbs->add(__('user.block.history'), false);
echo $this->Html->tag('h1', __('user.block.history'));

// The same element also renders on a member's profile, where it is a short
// list and needs no controls — so the sorting wrapper lives here, not in it.
?>
<div x-data="adminTable" data-sort="1:desc">
    <label class="admin-tableFilter">
        <span class="visually-hidden"><?= h(__('Search')) ?></span>
        <input type="search" class="form-control" x-model="query" x-on:input="apply()"
               placeholder="<?= h(__('Search')) ?>" autocomplete="off">
    </label>
    <?= $this->element('users/block-report', ['mode' => 'full', 'UserBlock' => $UserBlock]) ?>
</div>
<?php
