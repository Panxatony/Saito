<!DOCTYPE html>
<html>
<head>
    <title><?= h($titleForLayout) ?></title>
    <?php
    echo $this->Html->charset();

    // admin.bundle.js is Alpine plus the four behaviours this backend needs:
    // a sortable/filterable table, the confirmation overlay and the two menus.
    // It replaced jQuery, DataTables and Bootstrap's JavaScript — Bootstrap's
    // *stylesheet* stays, which is why the pages still look the same.
    echo $this->Html->script(['admin.bundle.js'], ['defer' => true]);

    echo $this->Html->css([
        'stylesheets/bootstrap.min',
        'Admin.admin.css',
    ]);
    ?>
</head>
<body>

<?= $this->element('Admin.layout/navbar') ?>

<div class="container">
    <?php
    $breadcrumbs = $this->Breadcrumbs
        ->render(['class' => 'breadcrumb']);
    echo $this->Html->tag('nav', $breadcrumbs);

    echo $this->Flash->render();

    echo $this->fetch('content');
    ?>
</div>
<?php
echo $this->fetch('script');
?>
</body>
</html>
