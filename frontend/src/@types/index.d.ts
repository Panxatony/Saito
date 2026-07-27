declare module TinyTimer {
    interface TinyTimerCallbackArgs {
        /** Seconds */
        s: number,
        /** Minutes */
        m: number,
        /** Hours */
        h: number,
        /** Days */
        d: number,
        /** Total seconds */
        S: number,
        /** Total minutes */
        M: number,
        /** Total hours */
        H: number,
        /** Total days */
        D: number,
        /** Text representation */
        text: string,
    }

    interface TinyTimerOptions {
        format: string,
        from?: Date | string,
        onEnd?: (args: TinyTimerCallbackArgs) => {},
        onTick?: (args: TinyTimerCallbackArgs) => {},
        to: Date | string,
    }
}

declare namespace JQuery {
    interface jqXHR {
        // Official but missing in official jQuery definitions
        crossDomain: boolean;
    }
}

interface JQueryStatic {
    i18n: any;
    isReady: boolean;
}

interface JQuery {
    scrollIntoView(method: string): any;
    textrange(method: string | object, arg1?: number | string, arg2?: number | string, arg3?: number | string): { position: number, start: number, end: number, length: number, text: string }
    tinyTimer(options: TinyTimer.TinyTimerOptions): void;
}

declare namespace Marionette {
    interface Application {
        onStart(app: any, options: Record<string, unknown>): void;
    }
}

declare module '*.html' {
    const content: string;
    export default content;
}

interface PNotify {

}

declare module 'backbone.localstorage' {
    class LocalStorage {
        public constructor(key: string);
    }

    export { LocalStorage };
}

// moment ts file is fucked up: https://github.com/moment/moment/issues/3763
declare module 'moment' {
    interface MomentStatic {
        (): any
        (date: number): any
        (date: string): any
        (date: string, time: string): any
        (date: Date): any
        (date: string, formats: string[]): any
        (date: number[]): any

        locale(locale: string): any
        unix(timestamp: number): any

    }

    var moment: MomentStatic;

    export default moment;
}

// favico.js ships as a CommonJS/UMD module, but @types/favico.js only declares
// a global `Favico` (no module shape), so a bare `import ... from 'favico.js'`
// cannot resolve it. Give it a default export so the ESM import resolves; the
// value stays `any` exactly as the previous `require('favico.js')` did (the
// @types' `badge(number)` signature is narrower than the real API, which also
// accepts a string badge — the runtime call is unchanged).
declare module 'favico.js' {
    const Favico: any;
    export default Favico;
}

// PoC island libraries. tsc uses classic module resolution here (see the
// moment / backbone.localstorage shims above), so the packages' own bundled
// typings are not picked up — declare the shapes the island uses.
declare module 'htmx.org' {
    const htmx: any;
    export default htmx;
}

declare module 'alpinejs' {
    const Alpine: { start(): void; [key: string]: any };
    export default Alpine;
}

interface IStringable {
    toString(): string;
}

/**
 * Helper declaration for .js files and TS strict
 */
declare module 'views/app';

/**
 * Browser-vendor specific properties on the global document object
 */
interface Document {
    msHidden: boolean | undefined;
    webkitHidden: boolean | undefined;
}

interface Window {
    /**
     * Redirects the browser to a new URL.
     *
     * @param url URL to redirect to.
     */
    redirect: (url: string) => void;
}
