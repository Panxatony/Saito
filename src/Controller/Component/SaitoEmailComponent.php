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
            ->setSubject($params['subject'])
            ->viewBuilder()->setTemplate($params['template']);

        $params['viewVars']['message'] = $params['message'];
        $email->setViewVars($params['viewVars'] + $defaults['viewVars']);

        if ($params['ccsender']) {
            $this->_sendCopyToOriginalSender($email);
        }
        $this->_send($email);
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
        $email = clone $email;
        $to = new SaitoEmailContact($email->getTo());
        // getOriginalSubject(), not getSubject(): the latter returns the already
        // MIME-encoded header value ("=?UTF-8?…?=" for a non-ASCII subject).
        // Embedding that inside the copy's quotes yields an encoded-word glued
        // to a '"', which violates RFC 2047 so mail clients show it raw instead
        // of the decoded text. Build the copy from the readable subject and let
        // setSubject() encode the whole header once.
        $subject = $email->getOriginalSubject();
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
            $email->transport($transport);
        };

        $sender = (new SaitoEmailContact('system'))->toCake();
        if ($email->getFrom() !== $sender) {
            $email->setSender($sender);
        }
        $result = $email->send();

        if ($debug) {
            $this->log($result, 'debug');
        }
    }
}
