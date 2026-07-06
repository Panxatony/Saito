import { View } from 'backbone.marionette';
import { Model } from 'backbone';
import * as Tpl from '../templates/bookmarkCommentTpl.html';

/**
 * Comment as input
 */
export class CommentInputView extends View<Model> {
    constructor(options: Record<string, unknown>) {
        options.template = Tpl;
        options.className = 'm-1';
        options.ui = {
            text: 'input',
        };
        options.events = {
            'keyup @ui.text': 'handleKeypress',
        };
        super(options);
    }
    public onRender() {
        this.getUI('text').focus();
    }
    protected handleKeypress(event: Event) {
        event.preventDefault();
        this.model.set('comment', this.getUI('text').val());
    }
}
