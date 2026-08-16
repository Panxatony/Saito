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

    // Flash → visible HTML. Saito's flash elements render nothing: they only
    // push into the JsData store the retired SPA used to read and turn into
    // toasts. This layout called render() and then never emptied that store, so
    // every message the backend produced was discarded unseen — 35 Flash->set()
    // calls in this plugin, from "Caches cleared." to a failed save.
    //
    // Deliberately without x-cloak: an alert must be readable even if Alpine
    // never loads. It starts visible and is only ever hidden from here.
    $this->Flash->render();
    $flashClass = ['error' => 'danger', 'success' => 'success', 'warning' => 'warning', 'notice' => 'info'];
    foreach ($this->JsData->notifications()->getAll() as $flashMsg) :
        $cls = $flashClass[$flashMsg['type']] ?? 'info';
        // Good news fades by itself; errors and warnings wait to be read.
        $auto = in_array($flashMsg['type'], ['success', 'notice'], true);
        ?>
        <div class="alert alert-<?= h($cls) ?>" role="alert" x-data="{ show: true }" x-show="show"
             <?= $auto ? 'x-init="setTimeout(() => show = false, 5000)"' : '' ?>>
            <?= h($flashMsg['message']) ?>
            <button type="button" class="btn-close" x-on:click="show = false" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php
    endforeach;

    echo $this->fetch('content');
    ?>
</div>
<?php
echo $this->fetch('script');
?>
</body>
</html>
