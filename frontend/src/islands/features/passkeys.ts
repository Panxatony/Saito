/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Passkeys as a second factor (#81).
 *
 * Two ceremonies, both the same shape: ask the server for options, hand them to
 * `navigator.credentials`, post back what the operating system signed. The
 * server decides everything; this file only translates between JSON and the
 * ArrayBuffers the browser API insists on.
 *
 * ## Why every buffer is converted by hand
 *
 * WebAuthn options travel as JSON but the API takes `ArrayBuffer` for the
 * challenge, the user id and every credential id — and hands back `ArrayBuffer`
 * again in the response. JSON has no byte array, so both directions go through
 * base64url. Not plain base64: `+` and `/` are not URL-safe and the padding `=`
 * is not either, and a single mismatched character produces a signature that
 * fails to verify with no clue as to why.
 *
 * `navigator.credentials` also refuses to run outside a secure context, so none
 * of this works over plain http — which is correct, and worth knowing before
 * debugging it on a development machine.
 *
 * ## Why the code field never goes away
 *
 * This whole file needs JavaScript and a platform authenticator. The six-digit
 * code needs neither. Everything here is offered *beside* the code field, never
 * instead of it, so a browser without support, a device without a sensor, or
 * JavaScript switched off all still reach the same second step.
 */

import { csrfToken } from '../lib/dom';

/**
 * The two endpoints a button needs, taken off the button itself.
 *
 * The template renders them with the application's webroot already applied.
 * Saito runs in a subdirectory on some installations, so a path hard-coded here
 * with a leading slash would post the ceremony next to the forum rather than
 * into it — and a ceremony that 404s looks exactly like a rejected passkey.
 *
 * @param button the pressed button
 */
function endpoints(button: HTMLElement): { options: string; verify: string } {
    return {
        options: button.dataset.optionsUrl ?? '',
        verify: button.dataset.verifyUrl ?? '',
    };
}

/**
 * base64url -> bytes.
 *
 * @param value base64url, unpadded
 */
function fromBase64Url(value: string): Uint8Array {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(padded + '=='.slice(0, (4 - (padded.length % 4)) % 4));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
}

/**
 * bytes -> base64url.
 *
 * @param buffer what the authenticator returned
 */
function toBase64Url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 1) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** A credential descriptor as the server sends it. */
interface JsonDescriptor {
    type: PublicKeyCredentialType;
    id: string;
    transports?: AuthenticatorTransport[];
}

/**
 * Turn the descriptor list the server sent into what the API wants.
 *
 * @param list descriptors, ids in base64url
 */
function toDescriptors(list: JsonDescriptor[] | undefined): PublicKeyCredentialDescriptor[] {
    return (list ?? []).map((d) => ({
        type: d.type,
        id: fromBase64Url(d.id) as unknown as BufferSource,
        transports: d.transports,
    }));
}

/**
 * Ask the server for something, expecting JSON back.
 *
 * @param url absolute path, rendered by the template
 * @param body posted as JSON when given
 */
async function call(url: string, body?: unknown): Promise<Record<string, unknown>> {
    const response = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'same-origin',
    });

    const data = (await response.json().catch(() => ({}))) as Record<string, unknown>;
    if (!response.ok) {
        throw new Error(typeof data.error === 'string' ? data.error : 'request failed');
    }
    return data;
}

/**
 * Is this browser able to do any of it?
 *
 * Both halves matter: the API can exist while the machine has no sensor at all,
 * and offering a button that cannot work is worse than not offering one.
 */
async function isAvailable(): Promise<boolean> {
    if (!window.PublicKeyCredential || !window.isSecureContext) {
        return false;
    }
    try {
        return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch {
        return false;
    }
}

/**
 * Show a message in the block a button points at.
 *
 * @param owner the button
 * @param message what to say
 * @param kind styling
 */
function say(owner: HTMLElement, message: string, kind: 'danger' | 'success'): void {
    const target = document.querySelector<HTMLElement>(owner.dataset.status ?? '');
    if (!target) {
        return;
    }
    target.className = `alert alert-${kind}`;
    target.textContent = message;
    target.hidden = false;
}

/**
 * Register the device the member is sitting at.
 *
 * @param button the button that was pressed
 */
async function register(button: HTMLButtonElement): Promise<void> {
    const url = endpoints(button);
    const options = await call(url.options);

    const publicKey: PublicKeyCredentialCreationOptions = {
        ...(options as unknown as PublicKeyCredentialCreationOptions),
        challenge: fromBase64Url(options.challenge as string) as unknown as BufferSource,
        user: {
            ...(options.user as PublicKeyCredentialUserEntity),
            id: fromBase64Url(
                (options.user as unknown as { id: string }).id,
            ) as unknown as BufferSource,
        },
        excludeCredentials: toDescriptors(options.excludeCredentials as JsonDescriptor[]),
    };

    const credential = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential;
    if (!credential) {
        throw new Error('cancelled');
    }
    const response = credential.response as AuthenticatorAttestationResponse;

    await call(url.verify, {
        label: (document.querySelector<HTMLInputElement>('#js-passkeyLabel')?.value ?? '').trim(),
        credential: JSON.stringify({
            id: credential.id,
            rawId: toBase64Url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: toBase64Url(response.clientDataJSON),
                attestationObject: toBase64Url(response.attestationObject),
            },
        }),
    });

    say(button, button.dataset.done ?? '', 'success');
    window.location.reload();
}

/**
 * Confirm a pending login with an already-registered passkey.
 *
 * @param button the button that was pressed
 */
async function confirmLogin(button: HTMLButtonElement): Promise<void> {
    const url = endpoints(button);
    const options = await call(url.options);

    const publicKey: PublicKeyCredentialRequestOptions = {
        ...(options as unknown as PublicKeyCredentialRequestOptions),
        challenge: fromBase64Url(options.challenge as string) as unknown as BufferSource,
        allowCredentials: toDescriptors(options.allowCredentials as JsonDescriptor[]),
    };

    const credential = (await navigator.credentials.get({ publicKey })) as PublicKeyCredential;
    if (!credential) {
        throw new Error('cancelled');
    }
    const response = credential.response as AuthenticatorAssertionResponse;

    const result = await call(url.verify, {
        credentialId: credential.id,
        credential: JSON.stringify({
            id: credential.id,
            rawId: toBase64Url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: toBase64Url(response.clientDataJSON),
                authenticatorData: toBase64Url(response.authenticatorData),
                signature: toBase64Url(response.signature),
                userHandle: response.userHandle ? toBase64Url(response.userHandle) : null,
            },
        }),
    });

    window.location.href = (result.redirect as string) || '/';
}

/**
 * Wire one button up, once.
 *
 * @param button a `[data-passkey]` button
 */
function attach(button: HTMLButtonElement): void {
    if (button.dataset.passkeyReady === '1') {
        return;
    }
    button.dataset.passkeyReady = '1';

    // Hidden until we know the machine can actually do it, rather than shown
    // and failing on click. The note explaining the absence is the mirror of
    // it: exactly one of the two is ever visible, so the member is never left
    // with a button that cannot work *and* a paragraph saying it cannot.
    const note = button.dataset.note
        ? document.querySelector<HTMLElement>(button.dataset.note)
        : null;
    void isAvailable().then((ok) => {
        button.hidden = !ok;
        if (note) {
            note.hidden = ok;
        }
    });

    button.addEventListener('click', (event) => {
        event.preventDefault();
        button.disabled = true;

        // try/finally rather than Promise.finally: the build targets ES2017,
        // where that method does not exist, and re-enabling the button matters
        // enough not to lose it to a silent runtime difference.
        void (async () => {
            try {
                const run = button.dataset.passkey === 'register' ? register : confirmLogin;
                await run(button);
            } catch (error: unknown) {
                // A member who dismisses the system prompt has not hit an error
                // and must not be told they did — they simply changed their
                // mind, and the code field is still sitting right there.
                const name = (error as { name?: string })?.name;
                if (name !== 'NotAllowedError' && name !== 'AbortError') {
                    say(button, button.dataset.failed ?? 'Failed', 'danger');
                }
            } finally {
                button.disabled = false;
            }
        })();
    });
}

/**
 * @param root element to search
 */
function init(root: ParentNode): void {
    root.querySelectorAll<HTMLButtonElement>('button[data-passkey]').forEach(attach);
}

init(document);

// The login overlay swaps the second-factor form in after the password step, so
// the button arrives long after this file ran.
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    init(target ?? document);
});
