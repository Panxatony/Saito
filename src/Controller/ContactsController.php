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
        $this->Authentication->allowUnauthenticated(['owner', 'htmxContactOwner']);

        // FormProtection form tokens cause false-positives for anonymous users
        // (session timeouts, bots). We already have CSRF + honeypot + timing protection.
        if (
            in_array($this->getRequest()->getParam('action'), ['owner', 'htmxContactOwner'], true)
            && $this->components()->has('FormProtection')
        ) {
            $this->components()->unload('FormProtection');
        }
    }

    /**
     * Contacts forum's owner via contact address
     *
     * @return \Cake\Http\Response|void
     */
    public function owner()
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
                return $this->redirect(['action' => 'owner']);
            }
            $session->delete('Contact.formLoadTime');
        }

        if ($this->CurrentUser->isLoggedIn()) {
            $user = $this->CurrentUser;
            $sender = $user->getId();
            $this->request = $this->request->withData('sender_contact', $user->get('user_email'));
        } else {
            $senderContact = $this->request->getData('sender_contact');
            $sender = [$senderContact => $senderContact];
        }

        return $this->_contact(new ContactFormOwner(), $recipient, $sender);
    }

    /**
     * Contacts individual user
     *
     * @param string $id user-ID
     * @return \Cake\Http\Response|void
     * @throws \InvalidArgumentException
     * @throws BadRequestException
     */
    public function user($id = null)
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

        $this->set(
            'titleForPage',
            __('user_contact_title', $recipient->get('username'))
        );

        $sender = $this->CurrentUser->getId();

        return $this->_contact(new ContactForm(), $recipient, $sender);
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

        if ($this->request->is('post')) {
            $isValid = $contact->validate($this->request->getData());
            if ($isValid) {
                try {
                    $email = [
                        'recipient' => $recipient,
                        'sender' => $sender,
                        'subject' => $this->request->getData('subject'),
                        'message' => $this->request->getData('text'),
                        'template' => 'user_contact',
                        'ccsender' => (bool)$this->request->getData('cc'),
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
