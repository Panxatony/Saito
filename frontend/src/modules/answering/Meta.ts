/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

import { JsonApiModel } from 'lib/backbone/jsonApi';

/** An editor markup button; `type` selects the concrete button view. */
interface IEditorButton {
    type: string;
    className?: string;
    title?: string;
}

/** A category option for the posting's category <select>. */
interface ICategoryOption {
    id: number;
    title: string;
}

/** A smiley the editor can insert; `icon` is unique per code. */
interface ISmiley {
    code: string;
    icon: string;
    type?: string;
}

interface IAnswerMetaData {
    draft?: {
        id: number,
        subject: string|null,
        text: string|null,
    };
    editor: {
        buttons: IEditorButton[],
        categories: ICategoryOption[],
        smilies: ISmiley[],
    };
    meta: {
        autoselectCategory: boolean,
        info: string,
        isEdit: boolean,
        last: string,
        quoteSymbol: string,
        subject?: string,
        text?: string|null,
        subjectMaxLength: number,
    };
    posting: object;
}

class MetaModel extends JsonApiModel {
    public attributes!: IAnswerMetaData;

    protected saitoUrl = 'postingmeta/';
}

export { MetaModel };
