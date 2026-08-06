<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\User\Auth;

/**
 * What happened when somebody tried to log in.
 *
 * An enum rather than the `bool` this used to be, because two-factor
 * authentication introduced a third state, and the dangerous shape would be a
 * `false` that means "the password was right". A caller that forgot to ask the
 * follow-up question would run the failed-login path — counting the attempt
 * against the throttle and telling the member their credentials were wrong —
 * for a login that in fact only needed its second step. Three named cases
 * cannot be misread that way.
 */
enum LoginResult
{
    /** Wrong credentials, a locked account, nothing to continue with. */
    case Failed;

    /** Password verified and the identity is set: the member is in. */
    case LoggedIn;

    /**
     * Password verified, and that is all. The identity is deliberately **not**
     * set; the account carries a confirmed second factor and the request must
     * go to the challenge. See AuthUserComponent::login().
     */
    case SecondFactorRequired;
}
