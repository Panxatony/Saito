<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Core\Configure;
use Cake\Log\LogTrait;
use Cake\Mailer\Mailer;
use Cake\Mailer\Transport\DebugTransport;
use Cake\Routing\Router;
use Cake\Utility\Text;
use Saito\Contact\SaitoEmailContact;

class SaitoEmailComponent extends Component
{
    use LogTrait;

    /**
     * Strips anything that could end a header line and start another one.
     *
     * A subject reaches this component straight from the contact form, and
     * CakePHP hands header values through unchanged when they are plain ASCII:
     * `setSubject("Hallo\r\nBcc: victim@example.com")` produces two header
     * lines, the second a real Bcc. That turns the forum into a relay that
     * sends to whoever the attacker names, from the forum's own domain and with
     * its SPF and DKIM behind it -- measured against
     * `Message::getHeadersString()`, which emitted the Bcc line verbatim.
     *
     * Done here rather than only in the form validator on purpose: this is the
     * single point every mail the forum sends passes through, so a caller added
     * later cannot forget it. The validator says the same thing again, to give a
     * person a proper error instead of silently swallowing what they typed.
     *
     * @param string $value the header value
     * @return string the value with CR, LF and NUL removed
     */
    public function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * send email
     *
     * @param array $params params
     * - 'recipient' userId, predefined or User entity
     * - 'sender' userId, predefined or User entity
     * - 'ccsender' bool send carbon-copy to sender
     * - 'template' string
     * - 'message' string
     * - 'viewVars' array
     * @return void
     */
    public function email($params = [])
    {
        $defaults = [
            'ccsender' => false,
            'message' => '',
            'sender' => 'system',
            'viewVars' => [
                'forumName' => Configure::read('Saito.Settings.forum_name'),
                'webroot' => Router::url('/', true),
            ],
        ];
        $params += $defaults;

        $from = new SaitoEmailContact($params['sender']);
        $systemFrom = new SaitoEmailContact('system');
        $to = new SaitoEmailContact($params['recipient']);

        // A person contacting via a form (member or anonymous visitor) must not
        // be used as the From address: the forum's server can't send as their
        // (external) domain, so SPF/DMARC would junk the mail at the recipient.
        // Send as the forum instead and carry the person in Reply-To so the
        // recipient can still reply to them. Predefined forum senders (system,
        // register, …) are legitimate From addresses and stay as-is.
        $fromContact = SaitoEmailContact::isPredefined($params['sender'])
            ? $from
            : $systemFrom;

        $email = new Mailer('saito');
        $email->setEmailFormat('text')
            ->setFrom($fromContact->toCake())
            ->setReplyTo($from->toCake())
            ->setTo($to->toCake())
            ->setSubject($this->sanitizeHeaderValue((string)$params['subject']))
            ->viewBuilder()->setTemplate($params['template']);

        $params['viewVars']['message'] = $params['message'];
        $email->setViewVars($params['viewVars'] + $defaults['viewVars']);

        // Send the main mail first, then the copy. _sendCopyToOriginalSender()
        // clones this mailer, and Cake's Mailer shares its Message object across
        // a clone, so re-addressing the copy also mutates this mailer. Sending
        // the main mail before that mutation happens keeps it going to the
        // recipient instead of the sender.
        $this->_send($email);
        if ($params['ccsender']) {
            $this->_sendCopyToOriginalSender($email);
        }
    }

    /**
     * Sends a copy of a completely configured email to the author
     *
     * @param Mailer $email email
     * @return void
     */
    protected function _sendCopyToOriginalSender(Mailer $email)
    {
        /* set new subject */
        // Cake's Mailer has no __clone(), so `clone` keeps a reference to the
        // SAME Message object: setTo()/setFrom()/setSubject() below are
        // delegated (via __call) to that shared Message and so also mutate the
        // caller's mailer. That is safe here because email() has already sent
        // the main mail before calling this method.
        $email = clone $email;
        $to = new SaitoEmailContact($email->getTo());
        // getOriginalSubject(), not getSubject(): the latter returns the already
        // MIME-encoded header value ("=?UTF-8?…?=" for a non-ASCII subject).
        // Embedding that inside the copy's quotes yields an encoded-word glued
        // to a '"', which violates RFC 2047 so mail clients show it raw instead
        // of the decoded text. Build the copy from the readable subject and let
        // setSubject() encode the whole header once.
        $subject = $email->getMessage()->getOriginalSubject();
        $data = ['subject' => $subject, 'recipient-name' => $to->getName()];
        $subject = __('Copy of your message: ":subject" to ":recipient-name"');
        $subject = Text::insert($subject, $data);
        $email->setSubject($subject);

        // The copy goes to the original sender, who is now carried in Reply-To
        // (From is the forum address, see email()).
        $email->setTo($email->getReplyTo());
        $from = new SaitoEmailContact('system');
        $email->setFrom($from->toCake());

        $this->_send($email);
    }

    /**
     * Sends the completely configured email
     *
     * @param Mailer $email email
     * @return void
     */
    protected function _send(Mailer $email)
    {
        $debug = Configure::read('Saito.debug.email');
        if ($debug) {
            $transport = new DebugTransport();
            $email->setTransport($transport);
        };

        $sender = (new SaitoEmailContact('system'))->toCake();
        if ($email->getFrom() !== $sender) {
            $email->setSender($sender);
        }
        $result = $email->send();

        if ($debug) {
            // Mailer::send() returns an array (headers + body); log() takes a
            // string, so with strict_types passing it raw raised a TypeError —
            // exactly in the debug-email mode this branch exists for.
            $this->log(print_r($result, true), 'debug');
        }
    }
}
