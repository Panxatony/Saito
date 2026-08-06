<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

use Saito\User\Permission\Resource;
use Saito\User\Permission\ResourceAC;
use Saito\User\Permission\Resources;
use Saito\User\Permission\Roles;

/**
 * Create roles and assign which permissions from other roles are allowed too
 *
 * Add translations in nondynamic.po as 'permission.role.<ID-number>'
 */
$config['Saito']['Permission']['Roles'] = (new Roles())
    // Non logged-in visitors
    ->add('anon', 0)
    // Registered and logged-in users
    ->add('user', 1, ['anon'])
    // Moderators
    ->add('mod', 2, ['user', 'anon'])
    // Administrators
    ->add('admin', 3, ['mod', 'user', 'anon'])
    // Owners
    ->add('owner', 4, ['admin', 'mod', 'user', 'anon']);

/**
 * Permissions
 *
 * everbody > owner > role
 */
/*
 * Every resource below is checked somewhere. Three were not, and they are gone
 * as of 8.3.6: `saito.core.user.email.set`, `…name.set` and `…lock.view`. All
 * three were live in 5.7.1 — the first two on the SPA profile's edit page, the
 * third in the forum's own UsersController — and were left behind when the SPA
 * was removed in 98e0a1b48.
 *
 * Nothing ran unguarded because of them: there is no path that changes another
 * member's address or name. The harm was to the reader — this file is where one
 * looks up what an administrator may do, and it promised three things that were
 * not there. `saito.core.user.password.set` was in the same state and was
 * answered the other way, by building the feature back.
 *
 * If a permission here stops being checked, delete it or restore what checked
 * it. A declaration nobody reads is a description of a forum that does not
 * exist.
 */
$config['Saito']['Permission']['Resources'] = (new Resources())
    /***********************************************************
     * Core                                                    *
     ***********************************************************/
    // Access to the administration backend
    ->add((new Resource('saito.core.admin.backend'))
        ->allow((new ResourceAC())->asRole('admin')))
    // Exempt from the per-member posting rate limit. Moderators and above
    // clean up and answer in bursts; the throttle is aimed at a script running
    // through a single confirmed account, not at them.
    ->add((new Resource('saito.core.posting.unthrottled'))
        ->allow((new ResourceAC())->asRole('mod')))
    // Pin or lock a posting
    ->add((new Resource('saito.core.posting.pinAndLock'))
        ->allow((new ResourceAC())->asRole('mod')))
    // Delete a posting
    ->add((new Resource('saito.core.posting.delete'))
        ->allow((new ResourceAC())->asRole('mod')))
    // Allow user to edit their own postings
    ->add((new Resource('saito.core.posting.edit'))
        ->allow((new ResourceAC())->onOwn()))
    // "Moderator" mode. Restricted to other user and accessible categories
    ->add((new Resource('saito.core.posting.edit.restricted'))
        ->allow((new ResourceAC())->asRole('mod')))
    // Allows unrestricted editing of postings
    ->add((new Resource('saito.core.posting.edit.unrestricted'))
        ->allow((new ResourceAC())->asRole('admin')))
    // Show user's IP address if available
    ->add((new Resource('saito.core.posting.ip.view'))
        ->allow((new ResourceAC())->asRole('mod')))
    // Merge postings
    ->add((new Resource('saito.core.posting.merge'))
        ->allow((new ResourceAC())->asRole('mod')))
    // Allow posting to be marked as solution
    ->add((new Resource('saito.core.posting.solves.set'))
        ->allow((new ResourceAC())->onOwn()))
    // Show a user's activation status
    ->add((new Resource('saito.core.user.activate.view'))
        ->allow((new ResourceAC())->asRole('admin')))
    // Contact a user no matter their contact settings
    ->add((new Resource('saito.core.user.contact'))
        ->allow((new ResourceAC())->asRole('admin')))
    // Delete a user account. Deliberately not granted to moderators: removing
    // an account is irreversible for everything except the postings, and it is
    // the one moderation act with no lesser version to reach for first — a
    // moderator who needs it can ask an admin.
    ->add((new Resource('saito.core.user.delete'))
        ->allow((new ResourceAC())->asRole('admin')->onRoles('mod', 'user'))
        ->allow((new ResourceAC())->asRole('owner')))
    // Edit a user's profile page
    ->add((new Resource('saito.core.user.edit'))
        ->allow((new ResourceAC())->onOwn())
        ->allow((new ResourceAC())->asRole('admin')))
    // Show last login date
    ->add((new Resource('saito.core.user.lastLogin.view'))
        ->allow((new ResourceAC())->asRole('admin')))
    // Allows locking-out of users
    ->add((new Resource('saito.core.user.lock.set'))
        ->allow((new ResourceAC())->asRole('mod')->onRole('user'))
        ->allow((new ResourceAC())->asRole('admin')->onRoles('mod', 'user'))
        ->allow((new ResourceAC())->asRole('owner')))
    // Change a user's password
    ->add((new Resource('saito.core.user.password.set'))
        ->allow((new ResourceAC())->asRole('admin')->onRoles('mod', 'user'))
        ->allow((new ResourceAC())->asRole('owner')))
    // Use the register form
    ->add((new Resource('saito.core.user.register'))
        ->allow((new ResourceAC())->asEverybody()))
    // Change a user's role. Allowed ranks: all the current user has but not
    // their own rank.
    ->add((new Resource('saito.core.user.role.set.restricted'))
        ->allow((new ResourceAC())->asRole('admin')->onRoles('mod', 'user')))
    // Change a user's role. Allowed ranks: all the current user has including
    // their own rank.
    ->add((new Resource('saito.core.user.role.set.unrestricted'))
        ->allow((new ResourceAC())->asRole('owner')))

    /***********************************************************
     * Bookmarks                                               *
     ***********************************************************/
    // Deleting bookmarks
    ->add((new Resource('saito.plugin.bookmarks.delete'))
        ->allow((new ResourceAC())->onOwn()))

    /***********************************************************
     * Uploader                                                *
     ***********************************************************/
    // Allow uploads
    ->add((new Resource('saito.plugin.uploader.add'))
        ->allow((new ResourceAC())->onOwn()))
    // Allow deleting uploads
    ->add((new Resource('saito.plugin.uploader.delete'))
        ->allow((new ResourceAC())->asRole('admin'))
        ->allow((new ResourceAC())->onOwn()))
    // View the uploader
    ->add((new Resource('saito.plugin.uploader.view'))
        ->allow((new ResourceAC())->asRole('admin'))
        ->allow((new ResourceAC())->onOwn()));

return $config;
