<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller;

use App\Form\ContactForm;
use App\Form\ContactFormOwner;
use Cake\Cache\Cache;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\Form\Form;
use Cake\Http\Exception\BadRequestException;
use Cake\ORM\TableRegistry;
use Saito\Exception\Logger\ExceptionLogger;

class ContactsController extends AppController
{

    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->set('showDisclaimer', true);
        $this->Authentication->allowUnauthenticated(['htmxContactOwner']);

        // FormProtection form tokens cause false-positives for anonymous users
        // (session timeouts, bots). We already have CSRF + honeypot + timing protection.
        if (
            $this->getRequest()->getParam('action') === 'htmxContactOwner'
            && $this->components()->has('FormProtection')
        ) {
            $this->components()->unload('FormProtection');
        }
    }

    /**
     * Contact a member via the htmx island — same logic as {@see user()} but
     * rendered standalone in the island layout. Login required.
     *
     * @param string|null $id user id
     * @return \Cake\Http\Response|void
     */
    public function htmxContactUser($id = null)
    {
        if (empty($id) || !$this->CurrentUser->isLoggedIn()) {
            throw new BadRequestException();
        }
        $Users = TableRegistry::getTableLocator()->get('Users');
        try {
            $recipient = $Users->get($id);
        } catch (RecordNotFoundException $e) {
            throw new BadRequestException();
        }
        $this->set('user', $recipient);

        if (
            !$recipient->get('personal_messages')
            && !$this->CurrentUser->permission('saito.core.user.contact')
        ) {
            throw new BadRequestException(null, 1562415010);
        }

        $this->set('titleForPage', __('user_contact_title', $recipient->get('username')));

        // The profile opens this in the shared contact overlay, so htmx gets the
        // bare form; a direct visit (or no JS) still gets the standalone page.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_contact_user_fragment');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_contact_user');
        }
        return $this->_contact(new ContactForm(), $recipient, $this->CurrentUser->getId());
    }

    /**
     * Contact the forum owner via the htmx island — same logic as {@see owner()}
     * (public; honeypot + timing guard for anonymous senders) rendered in the
     * island layout.
     *
     * @return \Cake\Http\Response|void
     */
    public function htmxContactOwner()
    {
        $recipient = 'contact';
        $session = $this->request->getSession();

        if ($this->request->is('get')) {
            $session->write('Contact.formLoadTime', time());
        }
        if ($this->request->is('post') && !$this->CurrentUser->isLoggedIn()) {
            $formLoadTime = (int)$session->read('Contact.formLoadTime');
            if ($formLoadTime === 0 || (time() - $formLoadTime) < 5) {
                $this->Flash->set(__('error_subject_empty'), ['element' => 'error']);

                return $this->redirect(['action' => 'htmxContactOwner']);
            }
            $session->delete('Contact.formLoadTime');
        }

        if ($this->CurrentUser->isLoggedIn()) {
            $sender = $this->CurrentUser->getId();
            $this->request = $this->request->withData('sender_contact', $this->CurrentUser->get('user_email'));
        } else {
            $senderContact = $this->request->getData('sender_contact');
            $sender = [$senderContact => $senderContact];
        }

        // htmx (footer overlay) gets just the form fragment; a direct visit gets
        // the standalone island page.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_contact_owner_fragment');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_contact_owner');
        }
        return $this->_contact(new ContactFormOwner(), $recipient, $sender);
    }

    /** @var int contact messages a client may send per window */
    private const CONTACT_MAX_MESSAGES = 5;

    /** @var int throttle window in seconds */
    private const CONTACT_THROTTLE_WINDOW = 3600;

    /**
     * Whether this client has used up its contact-form budget.
     *
     * The form is the one place an unauthenticated visitor can make the forum
     * send mail, and the honeypot field and five-second timer in front of it are
     * both trivially satisfied by a script. Login has had a per-IP throttle for
     * a while; this is the same idea with a slower budget.
     *
     * Counts on the way in, so a message that fails validation still costs — an
     * attempt is an attempt.
     *
     * @return bool true when the client should be turned away
     */
    private function isContactThrottled(): bool
    {
        $key = 'contact-throttle-' . $this->getRequest()->clientIp();
        $record = Cache::read($key);

        if (!is_array($record) || (time() - $record['first']) >= self::CONTACT_THROTTLE_WINDOW) {
            $record = ['count' => 0, 'first' => time()];
        }
        $record['count']++;
        Cache::write($key, $record);

        return $record['count'] > self::CONTACT_MAX_MESSAGES;
    }

    /**
     *  contact form validating and email sending
     *
     * @param Form $contact contact-form
     * @param mixed $recipient recipient
     * @param mixed $sender sender
     * @return \Cake\Http\Response|void
     */
    protected function _contact(Form $contact, $recipient, $sender)
    {
        // NOTE for callers: this returns a Response on success — an HX-Redirect
        // for the overlay, a 302 otherwise. Every call site must `return` it.
        // Dropping it silently sends the mail and then re-renders the form: the
        // overlay stays open, and the flash message sits in the session until
        // the visitor happens to navigate somewhere else.
        if ($this->request->is('get')) {
            if ($this->request->getData('cc') === null) {
                $this->request = $this->request->withData('cc', true);
            }
        }

        if ($this->request->is('post') && $this->isContactThrottled()) {
            $this->Flash->set(__('user.authe.throttled'), ['element' => 'error']);

            return $this->redirect('/');
        }

        if ($this->request->is('post')) {
            $isValid = $contact->validate($this->request->getData());
            if ($isValid) {
                try {
                    // The copy goes only to a sender the forum knows. An
                    // anonymous sender's address is whatever was typed into the
                    // form, so honouring `cc` for them turned this into an open
                    // relay: name the victim as sender, tick the box, and the
                    // forum mails your text to them from its own domain, with
                    // its SPF and DKIM behind it. Nothing is lost for a guest —
                    // they never had a mailbox here to copy to.
                    $ccSender = (bool)$this->request->getData('cc')
                        && $this->CurrentUser->isLoggedIn();

                    $email = [
                        'recipient' => $recipient,
                        'sender' => $sender,
                        'subject' => $this->request->getData('subject'),
                        'message' => $this->request->getData('text'),
                        'template' => 'user_contact',
                        'ccsender' => $ccSender,
                    ];
                    $this->SaitoEmail->email($email);
                    $message = __('Message was send.');
                    $this->Flash->set($message, ['element' => 'success']);

                    // htmx (overlay) can't follow a 302 into a modal — send the
                    // client-side redirect header so the page navigates to '/'.
                    if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
                        return $this->response->withHeader(
                            'HX-Redirect',
                            \Cake\Routing\Router::url('/')
                        );
                    }

                    return $this->redirect('/');
                } catch (\Exception $e) {
                    $Logger = new ExceptionLogger();
                    $Logger->write('Contact email failed', ['e' => $e]);
                    $message = $e->getMessage();
                    $message = __('Message couldn\'t be send: {0}', $message);
                    $this->Flash->set($message, ['element' => 'error']);
                }
            }
        }

        $this->set(compact('contact'));
    }
}
