import { Model } from 'backbone';
import BookmarksCl from '../modules/bookmarks/collections/bookmarksCl';

interface IGetBookmarksOptions {
    success?: (collection: BookmarksCl, response?: unknown, options?: unknown) => void;
    error?: (collection: BookmarksCl, response?: unknown, options?: unknown) => void;
    context?: unknown;
}

export default class extends Model {
    private bookmarks!: BookmarksCl;

    /**
     * Gets users bookmarks.
     *
     * Fetches the bookmarks from the server
     *
     * @param {object} options
     * - {callback} success
     * - {callback} error
     * @returns {Backbone.Collection} bookmarks collection
     */
    public getBookmarks(options: IGetBookmarksOptions = {}) {
        if (!this.bookmarks) {
            this.bookmarks = new BookmarksCl();
            this.bookmarks.fetch({
                error: options.error,
                success: options.success,
            });
        } else {
            options.success?.call(options.context, this.bookmarks, null, options);
        }
        return this.bookmarks;
    }

    public isLoggedIn() {
        return this.get('id') > 0;
    }

}
