/**
 * Type declarations for the two packages that ship none.
 *
 * This file used to describe a different application. Until 8.3.0 it declared
 * shapes for TinyTimer, jQuery, Marionette, PNotify, backbone.localstorage,
 * moment, favico.js, `views/app` and imported `*.html` templates — the
 * single-page frontend that went in 8.1.0. None of those packages is installed
 * any more and nothing referenced the declarations, but a `.d.ts` costs nothing
 * to keep and says nothing when it goes stale, so they sat here describing a
 * frontend that no longer existed. Three more went with them for the same
 * reason: `IStringable`, `Window.redirect` and the vendor-prefixed
 * `Document.msHidden`/`webkitHidden`, none of them referenced anywhere.
 *
 * What is left is what is actually imported. Keep it that way: an entry here is
 * a promise about someone else's API that nothing in the build verifies, so it
 * should be the narrowest thing that makes the import resolve.
 */

declare module 'htmx.org' {
    const htmx: any;
    export default htmx;
}

declare module 'alpinejs' {
    /**
     * What Alpine puts on a component's `this` at runtime.
     *
     * The package ships no typings of its own, so without this a component that
     * reads `this.$el` — which every one of them does — type-checks as an error.
     * That went unnoticed for as long as nothing ever ran the type-checker.
     *
     * Only the magics the code actually uses are declared. Adding one that is
     * never called would be a promise about Alpine's API that nothing here
     * verifies.
     */
    interface AlpineMagics {
        /** The element the component is mounted on. */
        readonly $el: HTMLElement;
        /** Elements carrying `x-ref` inside the component. */
        readonly $refs: Record<string, HTMLElement>;
        /** Fire a DOM event from `$el`, bubbling. */
        $dispatch(event: string, detail?: unknown): void;
        /** Run after Alpine has applied the current changes to the DOM. */
        $nextTick(callback?: () => void): Promise<void>;
    }

    const Alpine: {
        start(): void;
        /**
         * `ThisType` is what carries the magics into the object literal: inside
         * the factory's return value, `this` is the component *and* the magics,
         * which is exactly what Alpine provides.
         */
        data<T extends object>(name: string, factory: () => T & ThisType<T & AlpineMagics>): void;
        [key: string]: any;
    };
    export default Alpine;
}
