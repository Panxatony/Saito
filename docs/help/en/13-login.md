## Signing in and your password

Reading is usually open to everyone; writing, uploading and changing your own
settings need an account. Accounts are normally created by the operator, or
through registration where it is enabled.

### Signing in

*Log in* at the top right asks for your username and password. A checkbox keeps
you signed in beyond the visit, so the next time you come back you are not asked
again — better left unchecked on a shared or borrowed device.

### Forgot your password?

If you no longer know your password, you no longer have to ask the operator. On
the login page **Forgot your password?** leads to a form that asks for the email
address on file. If it is known to the forum, a message arrives with a one-time
link for setting a new password; you are then taken straight to the login.

A few things worth knowing:

- The link is valid for **60 minutes** and can be used **once**.
- Requesting again voids the previous link — only the most recent one counts.
- The form answers **the same whether or not the address is on file**. That is
  deliberate: it means the form cannot be used to find out who has an account
  here. If no mail arrives, check the spam folder and that it really is the
  address the forum has for you.

### Changing your password

If you know your password and want to change it, do so in your own settings under
*Change password*. Your current password is asked once, as a safeguard.

### Other devices are signed out

Changing or resetting your password ends **every other sign-in on the account**.
The device you are using stays signed in; any other open session — the old
phone, the computer at the library — is signed out on its next request. That way
a reset password also ends exactly the access you were trying to protect
yourself against.

### Two-factor authentication

You can protect your account with a code from an authenticator app (Aegis,
1Password, Google Authenticator and others) on top of your password. It is
optional and off unless you turn it on.

**Setting it up** happens in your own settings under *Two-factor
authentication*: the forum shows a QR code for your app to scan, and you then
enter one code to prove it works. Only then does the protection go live — so
stopping half-way cannot lock you out.

**Signing in** then asks for the code, right in the login window. Without it
nobody gets in, your password included.

**Recovery codes.** You get ten when you set it up, each usable once, and they
are shown **that one time only**. Keep them somewhere safe — they are your way
back in if the phone is gone: enter one instead of the app's code. You can
generate a fresh set in your settings at any time, which retires the old ones.

**Locked out completely?** If both the device and the codes are gone, an
administrator can reset two-factor authentication for your account. You then
sign in with your password alone and set it up again.

### Passkeys: confirm with Touch ID, Face ID or Windows Hello

Instead of typing the six-digit code, you can confirm the second step with the
device in your hand. Set it up in the same settings under *Passkeys*, once
two-factor authentication is on.

**Your fingerprint never leaves the device.** The operating system checks you
locally and sends the forum a signature. The forum sees neither fingerprint nor
face, and cannot.

Three things that surprise people who have not been told:

- **A passkey belongs to the device.** The one on your Mac is not on your phone.
  Apple and Google do sync passkeys now, but do not rely on it: register every
  device you use, and **keep your recovery codes**.
- **A passkey works for one address only.** One registered on a test forum will
  not work on the real one. That is not a fault, it is the protection itself —
  it is why a fake site cannot harvest your passkey.
- **The code stays.** Passkeys need JavaScript and a suitable sensor. Without
  either, the code field is exactly where it was, and the button does not appear
  at all if your device cannot do it.

Remove a device you no longer use in your settings. Switching two-factor
authentication off removes every passkey with it.

**"Stay signed in" still works.** Tick the box with your password and, once the
code checks out, the forum remembers *that device* for 30 days — you come back
without a password and without a code. It applies only to devices where you
actually entered the second factor; a standing pass from before you set it up is
refused, because it would otherwise walk straight past the second factor.

Signing out somewhere forgets only *that* device; the others stay signed in.
Switching two-factor authentication off, changing your password, or an
administrator resetting the second factor withdraws the trust from **every**
device, and each has to prove itself again.

**When the forum requires it.** Some forums require two-factor authentication of
moderators and administrators. If that applies to you and you have not set it up,
every request lands on a page asking you to, instead of where you were going. Set
it up once and it goes away. For ordinary members it stays optional.

If your authenticator app is out of reach, you can log out from that page; whoever
looks after the server can reset the second factor if it comes to that.

### When the terms of service change

If the terms change materially, you are asked about it on your next visit: instead
of the page you wanted, the new terms appear with a button to agree to them. Only
then does everything carry on as usual — that is not a glitch, it is the agreement
§ 7 of the terms asks for.

You stay signed in, and you are asked **once per change**, not on every visit.

Nobody who does not want to agree is locked in: the terms themselves, the imprint
and the privacy policy stay readable, downloading your own data still works, and
you can log out at any time — the notice carries its own link for that.
