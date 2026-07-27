//// Globals the administration backend expects.
//
// Loaded as `exports.bundle.js` by plugins/Admin/templates/layout/admin.php and
// nowhere else. The admin pages are classic server-rendered templates that call
// jQuery directly (see AdminHelper::jqueryTable) and use Bootstrap's own
// components, so these three have to exist on `window` before they run.
//
// Marionette used to be here too, for the retired Backbone frontend. Nothing in
// the admin area referenced it — checked before removing.
import $ from 'jquery';
import _ from 'underscore';
import 'bootstrap';

window._ = _;
window.$ = window.jQuery = $;
