<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Test\Thread;

use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Saito\Posting\Posting;

class ThreadTest extends TestCase
{
    /**
     * Build one posting row.
     *
     * @param int $id posting id
     * @param int $pid parent id, 0 for a root
     * @param string $lastAnswer last-answer stamp
     * @param array $children child rows
     * @return array
     */
    private function row(int $id, int $pid, string $lastAnswer, array $children = []): array
    {
        return [
            'id' => $id,
            'pid' => $pid,
            'tid' => 1,
            'subject' => "posting $id",
            'text' => '',
            'user' => ['id' => 1, 'username' => 'someone'],
            'last_answer' => new DateTime($lastAnswer),
            '_children' => $children,
        ];
    }

    /**
     * The ordinary case: the root has the smallest id, as it does in a thread
     * nobody has merged.
     *
     * @return void
     */
    public function testRootIsTheParentlessPosting(): void
    {
        $posting = new Posting($this->row(10, 0, '2020-01-02 00:00:00', [
            $this->row(11, 10, '2020-01-01 00:00:00'),
        ]));

        $this->assertSame(10, $posting->getThread()->get('root')->get('id'));
    }

    /**
     * The case the old rule got wrong.
     *
     * Merging re-parents an older thread onto a newer one, so the smallest id in
     * a thread can belong to a child. The root used to be taken as the smallest
     * id, which then named a reply — and the last-answer stamp that decides
     * whether cached thread lines are still valid was read off that reply, which
     * carries whatever date it was written with.
     *
     * @return void
     */
    public function testRootIsFoundWhenAChildHasASmallerId(): void
    {
        $posting = new Posting($this->row(500, 0, '2020-06-01 12:00:00', [
            // id 7 is older than its own thread root — what a merge produces.
            $this->row(7, 500, '2011-01-01 00:00:00'),
        ]));

        $thread = $posting->getThread();

        $this->assertSame(500, $thread->get('root')->get('id'));
        $this->assertSame(
            (new DateTime('2020-06-01 12:00:00'))->getTimestamp(),
            $thread->getLastAnswer(),
            'the last answer comes off the root, not off the oldest posting'
        );
    }

    /**
     * A subtree can be built on its own, without the root that belongs to it.
     * get('root') still has to answer, so the old smallest-id guess stays as the
     * fallback for exactly that case.
     *
     * @return void
     */
    public function testFallsBackToTheSmallestIdWithoutARoot(): void
    {
        $posting = new Posting($this->row(20, 5, '2020-01-01 00:00:00', [
            $this->row(30, 20, '2020-01-01 00:00:00'),
        ]));

        $this->assertSame(20, $posting->getThread()->get('root')->get('id'));
    }

    /**
     * The fallback must not win over a real root that arrives later — children
     * are added after their parent, but a merge means a child can carry the
     * smaller id.
     *
     * @return void
     */
    public function testARealRootIsNotOverriddenByTheFallback(): void
    {
        $posting = new Posting($this->row(900, 0, '2020-01-01 00:00:00', [
            $this->row(2, 900, '2020-01-01 00:00:00'),
            $this->row(3, 900, '2020-01-01 00:00:00'),
        ]));

        $this->assertSame(900, $posting->getThread()->get('root')->get('id'));
    }
}
