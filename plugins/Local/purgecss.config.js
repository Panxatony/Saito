// PurgeCSS config for the Local (macnemo) theme.
// Run from the plugin dir (see sass.sh); content globs are relative to it.
// The Bota base is Bootstrap-based, of which the forum only uses a fraction —
// purging unused selectors roughly halves theme.css / night.css.
//
// The safelist keeps selectors that are only ever added at runtime (Bootstrap
// JS toggles, Saito state classes, BBCode/richtext output, smilies). Font
// Awesome (fa-*) lives in its own stylesheet and is not touched here.
module.exports = {
  content: [
    '../../templates/**/*.php',
    '../../plugins/**/templates/**/*.php',
    '../../src/**/*.php',
    '../../plugins/**/src/**/*.php',
    '../../frontend/src/**/*.ts',
    '../../frontend/src/**/*.js',
  ],
  css: ['webroot/css/theme.css', 'webroot/css/night.css'],
  safelist: {
    standard: ['active', 'show', 'showing', 'fade', 'in', 'open', 'collapse',
      'collapsing', 'collapsed', 'disabled', 'modal-open', 'modal-backdrop',
      'was-validated', 'headerClosed', 'night', 'shake'],
    greedy: [/^fa/, /^is-/, /^has-/, /^saito-/, /^js-/, /^embed-responsive/,
      /geshi/, /^hl-/, /^richtext/, /modal/, /dropdown/, /tooltip/, /popover/,
      /collaps/, /fade/, /-open$/, /active$/, /show$/, /disabled$/, /^col-/,
      /^offcanvas/, /^carousel/],
  },
};
