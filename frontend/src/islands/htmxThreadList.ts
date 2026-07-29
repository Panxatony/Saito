/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The forum's htmx + Alpine island bundle.
 *
 * Pages are rendered by the server; this adds the behaviour that needs a
 * browser. It replaced the Backbone/Marionette application, and grew to carry
 * most of the interface — at which point one file was the wrong shape for it,
 * so each feature now lives on its own and this file only names them.
 *
 * Order matters in exactly two places. `runtime` publishes the libraries and
 * registers the htmx hook that puts the CSRF token on every request, so it is
 * first — and because a module is evaluated before whoever imports it, every
 * feature can rely on that having happened. `Alpine.start()` is last, after all
 * components are registered.
 *
 * Adding a feature means adding a file and a line here.
 */
import { Alpine } from './runtime';

import './features/threads';
import './features/postings';
import './features/editor';
import './features/uploads';
import './features/smartInsert';
import './features/subjectCount';
import './features/embeds';
import './features/spoiler';
import './features/widgets';
import './features/categoryFilter';
import './features/headerActions';
import './features/modals';
import './features/flash';
import './features/appearance';
import './features/userColors';

Alpine.start();
