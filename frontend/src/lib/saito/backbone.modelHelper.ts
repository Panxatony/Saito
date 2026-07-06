import { Model } from 'backbone';

declare module 'backbone' {
    interface Model {
        toggle(key: string): void;
    }
}

/**
 * Bool toggle attribute of model
 *
 * @param attribute
 */
Model.prototype.toggle = function(attribute) {
    this.set(attribute, !this.get(attribute));
};
