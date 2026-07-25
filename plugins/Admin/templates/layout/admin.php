<!DOCTYPE html>
<html>
<head>
    <title><?= h($titleForLayout) ?></title>
    <?php
    echo $this->Html->charset();

    // exports.bundle.js is the self-contained Vite bundle: it sets up the
    // jQuery/Bootstrap/underscore globals the admin backend needs. (The old
    // webpack vendor.bundle.js split no longer exists under Vite.)
    echo $this->Html->script([
        'exports.bundle.js',
    ]);

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
