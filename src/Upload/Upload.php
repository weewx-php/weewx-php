<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

/**
 * Readings out to one service.
 *
 * `post` takes records oldest first. Usually it is one, the interval that
 * just closed. It is a list because a connection that was down for ten
 * minutes has two choices when it comes back: send the newest and pretend
 * the rest never happened, or send them all. A service that takes a
 * timestamp with the reading gets them all; one that does not, says so
 * through its {@see Kind::backfill()} and is handed only the newest.
 */
interface Upload
{
    public function kind(): Kind;

    /**
     * Send records, oldest first.
     *
     * Records the service refused go into {@see Posted::$failures}.
     * Throwing means nothing was sent and why; a permanent {@see Rejected}
     * switches the upload off until somebody looks at it.
     *
     * @param list<array<string, mixed>> $records
     *
     * @throws Rejected
     */
    public function post(array $records): Posted;

    /**
     * Try the service without posting a reading, and say what happened.
     * Most of these services answer a wrong password with a cheerful 200
     * and a word in the body, so finding out at setup time is worth a great
     * deal more than finding out never.
     */
    public function check(): string;

    /**
     * What is safe to print about this upload: hosts and ids, never a key.
     *
     * @return array<string, mixed>
     */
    public function describe(): array;
}
