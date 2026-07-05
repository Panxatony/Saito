import { Model } from 'backbone';
import { View } from 'backbone.marionette';
import * as _ from 'underscore';

class NoContentView extends View<Model> {
    public constructor(options: Record<string, unknown> = {}) {
        _.defaults(options, {
            template: _.template('<div class="no-content-yet"><%- content %></div>'),
        });
        super(options);
    }
}

export { NoContentView };
