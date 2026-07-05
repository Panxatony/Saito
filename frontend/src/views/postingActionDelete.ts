import { Model } from 'backbone';
import { View } from 'backbone.marionette';
import $ from 'jquery';
import App from 'models/app';
import ModalDialog from 'modules/modalDialog/modalDialog';
import _ from 'underscore';

/**
 * Dialog for deleteing a posting.
 */
export default class extends View<Model> {
    public constructor(options: Record<string, unknown> = {}) {
        _.defaults(options, {
            events: {
                'click @ui.abort': '_onAbort',
                'click @ui.submit': '_onSubmit',
            },
            template: _.template(`
<div class="panel">
  <div class="panel-content">
      <p>
          <%- $.i18n.__('tree.delete.confirm') %>
      </p>
  </div>
  <div class="panel-footer panel-form">
      <button class="btn btn-primary js-abort"><%- $.i18n.__('posting.delete.abort.btn') %></button>
      &nbsp;
      <button class="btn btn-link js-delete"><%- $.i18n.__('posting.delete.title') %></button>
  </div>
</div>
  `),
            ui: {
                abort: '.js-abort',
                submit: '.js-delete',
            },

        });
        super(options);
    }

    public onRender() {
        ModalDialog.show(this, { title: $.i18n.__('posting.delete.title') });
    }

    private _onAbort(event: Event) {
        event.preventDefault();
        ModalDialog.hide();
    }

    private _onSubmit(event: Event) {
        event.preventDefault();
        const id = this.model.get('id');
        // Delete via the JWT API (DELETE /api/v2/postings/<id>) instead of a
        // GET redirect: a GET was CSRF-able. The global ajaxPrefilter adds the
        // bearer token; the endpoint enforces the delete permission.
        $.ajax({
            url: `${App.settings.get('apiroot')}postings/${id}`,
            method: 'DELETE',
        })
            .then(() => {
                ModalDialog.hide();
                // The posting (and, for a thread-root, the whole thread) is
                // gone — return to the front page.
                window.redirect(App.settings.get('webroot'));
            })
            .fail(() => {
                ModalDialog.hide();
                window.alert($.i18n.__('posting.delete.error'));
            });
    }

    private onBeforeClose() {
        this.destroy();
    }
}
