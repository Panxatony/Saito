import Backbone, { Model } from 'backbone';

interface ICakeRest {
    read: string;
    create: string;
    update: string;
    delete: string;
}

export default abstract class CakeRestModel extends Model {
    public methodToCakePhpUrl!: ICakeRest;

    public webroot!: string;

    public initialize(_attributes: Record<string, unknown>, _options: Record<string, unknown>) { // skipcq: JS-0356 - params required by the subclass initialize() override contract
        this.methodToCakePhpUrl = {
            create: 'add',
            delete: 'delete',
            read: 'view',
            update: 'edit',
        };
    }

    public sync(method: string, model: Model, options: Record<string, unknown> = {}): JQueryXHR {
        this.urlRoot = this.webroot;
        options = options || {};
        const key: keyof ICakeRest = method.toLocaleLowerCase() as keyof ICakeRest;
        let url = this.urlRoot + this.methodToCakePhpUrl[key];
        if (!this.isNew()) {
            url += (url.charAt(url.length - 1) === '/' ? '' : '/') + this.id;
        }
        options.url = url;

        return Backbone.sync(method, model, options);
    }
}
