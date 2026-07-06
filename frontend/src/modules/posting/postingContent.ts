import { View } from 'backbone.marionette';
import * as _ from 'underscore';
import { PostingModel } from './models/PostingModel';
import { PostingRichtextView } from './postingRichtext';

class PostingContentView extends View<PostingModel> {
    public constructor(options: Record<string, unknown> = {}) {
        _.defaults(options, {
            regions: {
                postingBody: '.postingBody-text',
            },
        });
        super(options);
        this.listenTo(this.model, 'change:isAnsweringFormShown', this._toggleAnsweringForm);
        this.listenTo(this.model, 'change:html', this.render);

        this.initRichtext();
    }

    public onRender() {
        this.$el.html(this.model.get('html'));
        this.initRichtext();
    }

    private initRichtext() {
        const richtextView = new PostingRichtextView({ el: this.$('.postingBody-text .richtext') });
        this.showChildView('postingBody', richtextView);
        richtextView.render();
    }

    private _toggleAnsweringForm() {
        if (this.model.get('isAnsweringFormShown')) {
            // hide signature
            this.$('.postingBody-signature').slideUp('fast');
        } else {
            // show signature
            this.$('.postingBody-signature').slideDown('fast');
        }
    }
}

export { PostingContentView };
