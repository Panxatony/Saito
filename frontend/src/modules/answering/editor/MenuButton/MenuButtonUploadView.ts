/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

import { Collection, Model } from 'backbone';
import { View } from 'backbone.marionette';
import BbcodeTag from 'lib/saito/Editor/Bbcode/BbcodeTag';
import App from 'models/app';
import ModalDialog from 'modules/modalDialog/modalDialog';
import UploadsCollection from 'modules/uploader/collections/uploads';
import UploaderVw from 'modules/uploader/uploader';
import { defaults, template } from 'underscore';
import { AbstractMenuButtonView } from './AbstractMenuButtonView';

/**
 * Build the upload BBCode tag ([img]/[audio]/[video]/[file]) for an upload.
 *
 * @param model upload model (needs `mime` and `name`)
 */
const uploadTag = (model: Model): BbcodeTag => {
    const mime: string = model.get('mime').match('^(.*)?/')[1];
    let tag: string;

    switch (mime) {
        case('audio'):
        case('video'):
            tag = mime;
            break;
        case('image'):
            tag = 'img';
            break;
        default:
            tag = 'file';
    }

    return new BbcodeTag({
        attributes: 'src=upload',
        content: model.get('name'),
        tag,
    });
};

/**
 * Per-upload checkbox that marks an upload for insertion into the posting.
 * Toggling it only sets a `selected` flag on the model; the actual insertion
 * of every selected upload happens once, from UploadInserterVw.
 */
class InsertSelectVw extends View<Model> {
    /**
     * Constructor
     *
     * @param options marionette init
     */
    public constructor(options: object = {}) {
        options = defaults(options, {
            className: 'imageUploader-select',
            events: { 'change @ui.checkbox': 'onToggle' },
            tagName: 'label',
            template: template(`
                <input type="checkbox" class="js-select"<% if (selected) { %> checked<% } %>>
                <%- $.i18n.__('upl.btn.insert') %>`),
            templateContext() {
                return { selected: Boolean((this as View<Model>).model.get('selected')) };
            },
            ui: { checkbox: '.js-select' },
        });
        super(options);
    }

    /**
     * Store the checkbox state on the model.
     */
    private onToggle() {
        this.model.set('selected', (this.getUI('checkbox')[0] as HTMLInputElement).checked);
    }
}

/**
 * Wraps the uploader with an "insert selected" action bar. Uploads ticked via
 * their checkbox are inserted into the posting together, then the dialog
 * closes. Reused so several images can be embedded in one posting at once.
 */
class UploadInserterVw extends View<Model> {
    private channel: { request(event: string, tag: BbcodeTag): void };

    /**
     * Constructor
     *
     * @param options channel to insert into + the account uploads belong to
     */
    public constructor(options: { channel: { request(event: string, tag: BbcodeTag): void }, userId: string }) {
        super(defaults(options, {
            className: 'imageUploader-inserter',
            collection: new UploadsCollection(),
            events: { 'click @ui.insertBtn': 'onInsertSelected' },
            regions: { uploaderRg: '.js-uploaderRg' },
            template: template(`
                <div class="imageUploader-inserter-bar card-footer">
                    <button class="btn btn-primary js-insertBtn" disabled>
                        <%- $.i18n.__('upl.btn.insertSelected') %>
                    </button>
                </div>
                <div class="js-uploaderRg"></div>`),
            ui: { insertBtn: '.js-insertBtn' },
        }));
        this.channel = options.channel;
    }

    /**
     * Ma onRender callback
     */
    public onRender() {
        this.showChildView('uploaderRg', new UploaderVw({
            collection: this.collection,
            permission: {
                'saito.plugin.uploader.add': true,
                'saito.plugin.uploader.delete': true,
                'saito.plugin.uploader.view': true,
            },
            userId: this.getOption('userId'),
        }));

        this.listenTo(this.collection, 'change:selected', this.refreshButton);
    }

    /**
     * The uploads currently ticked for insertion.
     */
    private selected(): Model[] {
        return (this.collection as Collection).filter((model: Model) => model.get('selected'));
    }

    /**
     * Enable/label the insert button according to the selection count.
     */
    private refreshButton() {
        const count = this.selected().length;
        const label = $.i18n.__('upl.btn.insertSelected');
        this.getUI('insertBtn')
            .prop('disabled', count === 0)
            .text(count > 0 ? `${label} (${count})` : label);
    }

    /**
     * Insert every selected upload into the posting and close the dialog.
     */
    private onInsertSelected() {
        const selected = this.selected();
        if (selected.length === 0) {
            return;
        }
        selected.forEach((model) => this.channel.request('insert:text', uploadTag(model)));
        ModalDialog.hide();
    }
}

class MenuButtonUploadView extends AbstractMenuButtonView {
    protected handleButton() {
        // Each upload card offers a selection checkbox …
        App.eventBus.reply('uploader:item:action', () => new InsertSelectVw());
        // … and the wrapper's action bar inserts all ticked uploads at once.
        const inserter = new UploadInserterVw({
            channel: this.channel,
            userId: App.currentUser.get('id'),
        });

        ModalDialog.show(inserter, {
            title: $.i18n.__('upl.title'),
            trailing: true,
            width: 'max',
        });
    }
}

export { InsertSelectVw, MenuButtonUploadView, UploadInserterVw, uploadTag };
