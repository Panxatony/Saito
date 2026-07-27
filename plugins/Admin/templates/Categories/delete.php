<?php $this->Breadcrumbs->add(__('Categories'), '/admin/categories'); ?>
<?php $this->Breadcrumbs->add(__d('admin', 'cat.del.t', $category->get('category'))); ?>

<h1>
    <?= h(__d('admin', 'cat.del.t', $category->get('category'))) ?>
</h1>

<p>
    <?= h(__d('admin', 'cat.del.exp')) ?>
</p>

<div class="card border-primary mb-3">
    <div class="card-header bg-primary text-white">
        <?= h(__d('admin', 'cat.del.mbd.t')) ?>
    </div>
    <div class="card-body">
        <p>
            <?= h(__d('admin', 'cat.del.mbd.exp')) ?>
        </p>
        <?php
        echo $this->Form->create(null);
        echo $this->Form->control(
            'targetCategory',
            [
                'label' => __d('admin', 'cat.del.mbd.l'),
                'options' => $targetCategories,
                'type' => 'select',
            ]
        );
        echo $this->Form->hidden('mode', ['value' => 'move']);
        echo $this->Form->submit(
            __d('admin', 'cat.del.mbd.b'),
            ['class' => 'btn btn-primary']
        );
        echo $this->Form->end();
        ?>
    </div>
</div>

<div class="card border-danger">
    <div class="card-header bg-danger text-white">
        <?= h(__d('admin', 'cat.del.d.t')) ?>
    </div>
    <div class="card-body">
        <p>
            <?= h(__d('admin', 'cat.del.d.exp')) ?>
        </p>
        <?php // Alpine rather than Bootstrap's JavaScript; the backend no longer
              // carries jQuery. Without script the button is a plain link to the
              // section below, which is still readable and submittable. ?>
        <div x-data="adminModal">
            <a href="#deleteModal" class="btn btn-danger" x-on:click.prevent="open()">
                <?= h(__d('admin', 'cat.del.d.b')) ?>
            </a>

<div id="deleteModal" class="modal" tabindex="-1" role="dialog"
     x-show="isOpen" x-cloak
     x-on:keydown.escape.window="close()"
     style="display: block; background: rgba(0,0,0,.5)">
  <div class="modal-dialog" role="document" x-on:click.outside="close()">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">
            <?= h(__d('admin', 'cat.del.d.t')) ?>
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="alert alert-error">
            <?= h(__d('admin', 'cat.del.d.exp')) ?>
        </div>
      </div>
      <div class="modal-footer">
        <?php
        echo $this->Form->create();
        echo $this->Form->hidden('mode', ['value' => 'delete']);
        echo $this->Form->button(
            __d('admin', 'cancel'),
            ['class' => 'btn', 'x-on:click.prevent' => 'close()']
        );
        echo ' ';
        echo $this->Form->button(
            __d('admin', 'cat.del.d.b'),
            ['type' => 'submit', 'class' => 'btn btn-danger', 'data-autofocus' => true]
        );
        echo $this->Form->end();
        ?>
      </div>
    </div>
  </div>
</div>
        </div><?php // /x-data ?>
    </div>
</div>
