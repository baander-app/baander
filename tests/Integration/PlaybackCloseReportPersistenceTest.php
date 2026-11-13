<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Session\PlaybackPersistenceScenarios;
use PHPUnit\Framework\TestCase;

/** An endpoint reports that it is going away and that its players have stopped. */
final class PlaybackCloseReportPersistenceTest extends TestCase
{
    use PlaybackPersistenceScenarios;

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $checkpoint
     * @return array<string,mixed>
     */
    private function close(PlaybackCoordinator $service, Uuid $owner, array $endpoint, array $checkpoint): array
    {
        return $service->execute($owner, PlaybackOperation::Close, ['incarnationId' => $endpoint['incarnationId'], 'checkpoint' => $checkpoint], $endpoint['capability']);
    }

    /**
     * The cursor a page held, with the session it last saw.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function checkpoint(array $snapshot, float $position): array
    {
        return ['revision' => $snapshot['revision'], 'media' => $snapshot['media'], 'currentTrackIndex' => $snapshot['currentTrackIndex'], 'position' => $position];
    }

    public function testAPlayingRunnerThatReportsItsCloseLeavesTheSessionPausedAtItsCursorForTheNextTab(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $runner);

        $closed = $this->close($service, $owner, $runner, $this->checkpoint($playing, 97.25));

        self::assertNull($closed['activeIncarnationId']);
        self::assertNull($closed['activeDestinationId']);
        self::assertNull($closed['selectedDestinationId']);
        self::assertSame('paused', $closed['desiredState']);
        self::assertSame('paused', $closed['observedState']);
        self::assertSame(97.25, $closed['position']);
        self::assertSame($playing['revision'] + 1, $closed['revision']);
        self::assertSame([], $closed['commands']);
        self::assertArrayNotHasKey('endpoint', $closed);
        self::assertArrayNotHasKey('requestResult', $closed);
        // The destination is unavailable at once: nobody waits for its registration to lapse.
        self::assertSame([false], array_column(array_filter($closed['destinations'], static fn(array $destination): bool => $destination['incarnationId'] === $runner['incarnationId']), 'available'));
        self::assertSame(['observed_state' => 'stopped', 'position' => 97.25, 'retired' => true, 'available' => false], $this->incarnation($runner['incarnationId']));

        // A tab opened afterwards is shown no runner, and its Play starts through the ordinary path.
        $next = $service->execute($owner, PlaybackOperation::Endpoint, $this->tabOnAPage($owner));
        self::assertNull($next['activeIncarnationId']);
        self::assertSame('paused', $next['observedState']);
        self::assertSame(97.25, $next['position']);
        self::assertSame($closed['revision'], $next['revision']);
        $play = $this->controlFrom($service, $owner, $next['endpoint'], $next['revision'], 'play');
        self::assertSame('retained', $play['requestResult']['outcome']);
        $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $play['revision'], 'targetIncarnationId' => $next['endpoint']['incarnationId']]);
        self::assertNull($transfer['transfer']['sourceIncarnationId']);
        self::assertSame(97.25, $transfer['position']);
    }

    public function testTheReportedCursorIsUsedOnlyWhereItDescribesTheSessionAsItStands(): void
    {
        $service = $this->coordinator();
        $track = ['kind' => 'song', 'publicId' => 'closeReportSong000001'];
        /** @var array<string,\Closure(array<string,mixed>): array<string,mixed>> $checkpoints */
        $checkpoints = [
            'an earlier revision' => fn(array $playing): array => ['revision' => $playing['revision'] - 1] + $this->checkpoint($playing, 97.25),
            'other media' => fn(array $playing): array => ['media' => $track] + $this->checkpoint($playing, 97.25),
            'another queue position' => fn(array $playing): array => ['currentTrackIndex' => 3] + $this->checkpoint($playing, 97.25),
        ];
        foreach ($checkpoints as $case => $checkpoint) {
            $owner = $this->createUser();
            $runner = $this->endpoint($service, $owner);
            $playing = $this->playing($service, $owner, $runner);

            $closed = $this->close($service, $owner, $runner, $checkpoint($playing));

            // The session is released all the same, at the cursor it last recorded.
            self::assertNull($closed['activeIncarnationId'], $case);
            self::assertSame('paused', $closed['observedState'], $case);
            self::assertSame(42.5, $closed['position'], $case);
            self::assertSame($playing['revision'] + 1, $closed['revision'], $case);
            // The incarnation's own row keeps what it reported.
            self::assertSame(97.25, $this->incarnation($runner['incarnationId'])['position'], $case);
        }
    }

    public function testARunnerAProofReleasesIsLeftAsTheProofLeavesIt(): void
    {
        $service = $this->coordinator();

        // A confirmed pause: the proof releases it and the session keeps the cursor of the pause.
        $owner = $this->createUser();
        $runner = $this->endpoint($service, $owner);
        $paused = $this->paused($service, $owner, $runner);
        $closed = $this->close($service, $owner, $runner, $this->checkpoint($paused, 44.0));
        self::assertNull($closed['activeIncarnationId']);
        self::assertSame('paused', $closed['desiredState']);
        self::assertSame('paused', $closed['observedState']);
        self::assertSame(43.0, $closed['position']);
        self::assertSame($paused['revision'] + 1, $closed['revision']);

        // A confirmed stop stays a stop.
        $owner = $this->createUser();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->stopped($service, $owner, $runner);
        $closed = $this->close($service, $owner, $runner, $this->checkpoint($stopped, 0.0));
        self::assertNull($closed['activeIncarnationId']);
        self::assertSame('stopped', $closed['desiredState']);
        self::assertSame('stopped', $closed['observedState']);
        self::assertSame(42.5, $closed['position']);
        self::assertSame($stopped['revision'] + 1, $closed['revision']);
    }

    public function testARunnerWithAnUnansweredStartOrAStopOwedIsReleasedAndOwedNothingMore(): void
    {
        $service = $this->coordinator();
        foreach (['a start it was shown', 'a stop it is owed after a start expired'] as $case) {
            $owner = $this->createUser();
            $runner = $this->endpoint($service, $owner);
            $stopped = $this->stopped($service, $owner, $runner);
            $before = $this->controlFrom($service, $owner, $runner, $stopped['revision'], 'play');
            self::assertSame(['play'], array_column($before['commands'], 'type'), $case);
            if ($case === 'a stop it is owed after a start expired') {
                $this->expireCommands($owner);
                $before = $service->execute($owner, PlaybackOperation::Snapshot, []);
                self::assertSame('unknown', $before['observedState'], $case);
                self::assertSame([['stop', $runner['incarnationId']]], $this->unanswered($owner), $case);
            }

            $closed = $this->close($service, $owner, $runner, $this->checkpoint($before, 12.0));

            self::assertNull($closed['activeIncarnationId'], $case);
            self::assertSame('paused', $closed['desiredState'], $case);
            self::assertSame('paused', $closed['observedState'], $case);
            self::assertSame(12.0, $closed['position'], $case);
            self::assertSame($before['revision'] + 1, $closed['revision'], $case);
            self::assertSame([], $this->unanswered($owner), $case);
            self::assertSame(0, (int) $this->observer->fetchOne("SELECT count(*) FROM playback_commands WHERE user_id=? AND outcome IS NULL", [$owner->toString()]), $case);
            // Nothing is issued again for the gone page.
            $this->expireCommands($owner);
            $later = $service->execute($owner, PlaybackOperation::Snapshot, []);
            self::assertSame($closed['revision'], $later['revision'], $case);
            self::assertSame([], $this->unanswered($owner), $case);
        }
    }

    public function testATransferParticipantThatReportsItsCloseSettlesTheTransferByItsRole(): void
    {
        $service = $this->coordinator();
        /** @var list<array{string,bool,string,string,?string,string}> $cases The reporter's role, whether the transfer has a source, its state, the transfer afterwards, the runner afterwards and what the session knows. */
        $cases = [
            ['target', false, 'preparing', 'failed', null, 'unknown'],
            ['target', false, 'starting', 'failed', null, 'paused'],
            ['target', false, 'uncertain', 'failed', null, 'paused'],
            ['target', true, 'quiescing', 'failed', 'source', 'unknown'],
            ['target', true, 'starting', 'failed', 'source', 'paused'],
            ['target', true, 'uncertain', 'failed', 'source', 'paused'],
            ['source', true, 'preparing', 'failed', null, 'paused'],
            ['source', true, 'quiescing', 'failed', null, 'paused'],
            ['source', true, 'starting', 'starting', 'source', 'paused'],
            ['source', true, 'uncertain', 'uncertain', 'source', 'unknown'],
        ];
        foreach ($cases as [$role, $withSource, $state, $transfer, $runner, $observed]) {
            $case = "the {$role} of a transfer " . ($withSource ? 'with' : 'without') . " a source, {$state}";
            $owner = $this->createUser();
            $built = $this->transferBetweenPages($service, $owner, $withSource, $state);
            $before = $built['snapshot'];

            $closed = $this->close($service, $owner, $built[$role], $this->checkpoint($before, 61.0));

            self::assertSame($transfer === 'failed' ? null : $transfer, $closed['transfer']['state'] ?? null, $case);
            self::assertSame($transfer, $service->execute($owner, PlaybackOperation::Transfer, $built['request'])['transferResult']['state'], $case);
            self::assertSame($runner === null ? null : $built[$runner]['incarnationId'], $closed['activeIncarnationId'], $case);
            self::assertSame($observed, $closed['observedState'], $case);
            self::assertSame($transfer === 'failed' ? $before['revision'] + 1 : $before['revision'], $closed['revision'], $case);
            self::assertSame(['observed_state' => 'stopped', 'position' => 61.0, 'retired' => true, 'available' => false], $this->incarnation($built[$role]['incarnationId']), $case);
            // A source that never quiesced is owed a stop; a transfer that goes on keeps its step; nothing else is outstanding.
            self::assertSame(match (true) {
                $role === 'target' && $state === 'quiescing' => [['stop', $built['source']['incarnationId']]],
                $transfer === 'starting' => [['start', $built['target']['incarnationId']]],
                $transfer === 'uncertain' => [['stop', $built['target']['incarnationId']]],
                default => [],
            }, $this->unanswered($owner), $case);
        }
    }

    public function testASourceThatReportedItsCloseWhileItsTargetStartsDoesNotGetTheSessionBack(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['source' => $source, 'sourceRegistration' => $registration, 'target' => $target, 'snapshot' => $starting] = $this->transferBetweenPages($service, $owner, true, 'starting');
        $this->close($service, $owner, $source, $this->checkpoint($starting, 50.0));

        // The page comes back, as from the back/forward cache, and registers as the same page: it
        // takes its earlier incarnation's place, holds no player and is an ordinary runner again.
        $restored = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
        $successor = $restored['endpoint'];
        self::assertSame($successor['incarnationId'], $restored['activeIncarnationId']);
        self::assertSame($successor['incarnationId'], $restored['transfer']['sourceIncarnationId']);
        self::assertSame($starting['revision'], $restored['revision']);

        // The target refuses its start, so the transfer fails back to the restored page, whose own
        // stop report then releases the session.
        $failed = $this->answer($service, $owner, $target, 'rejected');
        self::assertNull($failed['transfer']);
        self::assertSame($successor['incarnationId'], $failed['activeIncarnationId']);
        $released = $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $successor['incarnationId'], 'expectedRevision' => $failed['revision'], 'observedState' => 'stopped', 'position' => 50.0], $successor['capability']);
        self::assertNull($released['activeIncarnationId']);
        self::assertSame('paused', $released['observedState']);
    }

    public function testAReportForAnIncarnationTheSessionDoesNotNameChangesOnlyThatIncarnation(): void
    {
        $service = $this->coordinator();

        // Another tab than the runner.
        $owner = $this->createUser();
        $runner = $this->endpoint($service, $owner);
        $bystander = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $runner);
        $closed = $this->close($service, $owner, $bystander, $this->checkpoint($playing, 5.0));
        self::assertSame($runner['incarnationId'], $closed['activeIncarnationId']);
        self::assertSame('playing', $closed['observedState']);
        self::assertSame(42.5, $closed['position']);
        self::assertSame($playing['revision'], $closed['revision']);
        self::assertSame(['observed_state' => 'stopped', 'position' => 5.0, 'retired' => true, 'available' => false], $this->incarnation($bystander['incarnationId']));
        self::assertTrue($this->incarnation($runner['incarnationId'])['available']);

        // A runner whose page registered again: the report names the incarnation that was succeeded.
        $owner = $this->createUser();
        $registration = $this->tabOnAPage($owner);
        $earlier = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $playing = $this->playing($service, $owner, $earlier);
        $pause = $this->controlFrom($service, $owner, $this->endpoint($service, $owner), $playing['revision'], 'pause');
        $successor = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $closed = $this->close($service, $owner, $earlier, $this->checkpoint($playing, 5.0));
        self::assertSame($successor['incarnationId'], $closed['activeIncarnationId']);
        self::assertSame('playing', $closed['observedState']);
        self::assertSame($pause['revision'], $closed['revision']);
        self::assertTrue($this->incarnation($successor['incarnationId'])['available']);
        // The succession retired the earlier incarnation without recording it stopped, so its first report still records its cursor.
        self::assertSame(['observed_state' => 'stopped', 'position' => 5.0, 'retired' => true, 'available' => false], $this->incarnation($earlier['incarnationId']));
        // The pause that followed the page to its new incarnation is still there to be answered.
        self::assertSame([['pause', $successor['incarnationId']]], $this->unanswered($owner));
        self::assertSame('paused', $this->answer($service, $owner, $successor, 'paused', 43.0)['observedState']);
    }

    public function testAReportFromAPageThatWasSucceededSinceSpeaksForItsSuccessorWhileTheSessionStandsWhereItLeftIt(): void
    {
        $service = $this->coordinator();
        foreach (['the successor did nothing since', 'the successor answered a seek since'] as $case) {
            $owner = $this->createUser();
            $registration = $this->tabOnAPage($owner);
            $earlier = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
            $playing = $this->playing($service, $owner, $earlier);
            // The socket was replaced and the same page registered again: its successor took the
            // earlier incarnation's place at the same revision.
            $succeeded = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
            $successor = $succeeded['endpoint'];
            self::assertSame([$successor['incarnationId'], $playing['revision']], [$succeeded['activeIncarnationId'], $succeeded['revision']], $case);
            $before = $playing;
            if ($case === 'the successor answered a seek since') {
                $this->controlFrom($service, $owner, $successor, $playing['revision'], 'seek', ['position' => 70.0]);
                $before = $this->answer($service, $owner, $successor, 'applied', 70.0);
                self::assertGreaterThan($playing['revision'], $before['revision'], $case);
            }

            // The page went away, and the report that reaches the server names the earlier incarnation.
            $closed = $this->close($service, $owner, $earlier, $this->checkpoint($playing, 61.0));

            if ($case === 'the successor did nothing since') {
                self::assertNull($closed['activeIncarnationId'], $case);
                self::assertSame('paused', $closed['desiredState'], $case);
                self::assertSame('paused', $closed['observedState'], $case);
                self::assertSame(61.0, $closed['position'], $case);
                self::assertSame($playing['revision'] + 1, $closed['revision'], $case);
                self::assertSame(['observed_state' => 'stopped', 'position' => 61.0, 'retired' => true, 'available' => false], $this->incarnation($successor['incarnationId']), $case);
                // Another tab then plays without a refusal.
                $next = $this->endpoint($service, $owner);
                self::assertSame('retained', $this->controlFrom($service, $owner, $next, $closed['revision'], 'play')['requestResult']['outcome'], $case);
            } else {
                // The session moved on after the report's checkpoint, so the report speaks only for the earlier incarnation.
                self::assertSame($successor['incarnationId'], $closed['activeIncarnationId'], $case);
                self::assertSame('playing', $closed['observedState'], $case);
                self::assertSame(70.0, $closed['position'], $case);
                self::assertSame($before['revision'], $closed['revision'], $case);
                self::assertTrue($this->incarnation($successor['incarnationId'])['available'], $case);
            }
            self::assertSame([], $this->unanswered($owner), $case);
        }
    }

    public function testATargetThatReportsItsCloseAfterTheSourcePageWasSucceededLeavesTheSuccessorPausedAndOwedNothing(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['source' => $source, 'sourceRegistration' => $registration, 'target' => $target, 'snapshot' => $starting] = $this->transferBetweenPages($service, $owner, true, 'starting');
        // The source answered its quiesce; then its page registered again and its successor took the source's role.
        $succeeded = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
        $successor = $succeeded['endpoint'];
        self::assertSame($successor['incarnationId'], $succeeded['transfer']['sourceIncarnationId']);
        self::assertNotSame($source['incarnationId'], $successor['incarnationId']);

        $closed = $this->close($service, $owner, $target, $this->checkpoint($starting, 61.0));

        self::assertNull($closed['transfer']);
        self::assertSame($successor['incarnationId'], $closed['activeIncarnationId']);
        self::assertSame('paused', $closed['desiredState']);
        self::assertSame('paused', $closed['observedState']);
        // The quiesce the page answered before its succession is the proof that it is silent: no stop is owed.
        self::assertSame([], $this->unanswered($owner));
    }

    public function testARepeatedReportChangesNothingAndAPageThatReturnsFindsNothingToSucceed(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $registration = $this->tabOnAPage($owner);
        $runner = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $playing = $this->playing($service, $owner, $runner);
        $checkpoint = $this->checkpoint($playing, 97.25);
        $first = $this->close($service, $owner, $runner, $checkpoint);
        $after = $this->committed($owner);

        $again = $this->close($service, $owner, $runner, $checkpoint);

        self::assertSame($first, $again);
        self::assertSame($after, $this->committed($owner));

        // A later report at another cursor, as from the page restored from the back/forward cache,
        // changes nothing either: the incarnation is already retired as stopped.
        $later = $this->close($service, $owner, $runner, $this->checkpoint($playing, 12.0));

        self::assertSame($first, $later);
        self::assertSame($after, $this->committed($owner));

        // The same page registers again, as after a restore from the back/forward cache. The session
        // no longer names its earlier incarnation, so it is an ordinary destination.
        $restored = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
        self::assertNull($restored['activeIncarnationId']);
        self::assertNull($restored['transfer']);
        self::assertSame('paused', $restored['observedState']);
        self::assertSame(97.25, $restored['position']);
        self::assertSame($first['revision'], $restored['revision']);
    }

    public function testALocalRecoveryForAnIncarnationThatReportedItsCloseAnswersRecoveredAndReleasesNothingAgain(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner, ['localRecoveryEligible' => true, 'tabId' => new Uuid()->toString()]);
        $playing = $this->playing($service, $owner, $runner);
        $recovery = ['incarnationId' => $runner['incarnationId'], 'tabId' => $runner['recoveryTabId'], 'recoveryCredential' => $runner['recoveryCredential'],
            'checkpoint' => $this->checkpoint($playing, 99.0)];
        // The page reports its close as it unloads; the page loaded next in the same browser then
        // posts the local recovery proof it holds for that incarnation.
        $closed = $this->close($service, $owner, $runner, $this->checkpoint($playing, 97.25));
        $after = $this->committed($owner);

        $recovered = $service->execute($owner, PlaybackOperation::RecoverLocal, $recovery);

        $result = ['incarnationId' => $runner['incarnationId'], 'outcome' => 'recovered', 'revision' => $closed['revision']];
        self::assertSame($result, $recovered['recoveryResult']);
        self::assertNull($recovered['activeIncarnationId']);
        self::assertSame('paused', $recovered['observedState']);
        self::assertSame(97.25, $recovered['position']);
        self::assertSame($closed['revision'], $recovered['revision']);
        self::assertSame($after, $this->committed($owner));

        // Another tab takes playback. The same proof again answers with its recorded result and leaves that tab its session.
        $next = $this->endpoint($service, $owner);
        $taken = $this->playing($service, $owner, $next);
        $replayed = $service->execute($owner, PlaybackOperation::RecoverLocal, $recovery);
        self::assertEquals($result, $replayed['recoveryResult']);
        self::assertSame($next['incarnationId'], $replayed['activeIncarnationId']);
        self::assertSame('playing', $replayed['observedState']);
        self::assertSame($taken['revision'], $replayed['revision']);

        // In the other order the recovery releases the session and retires the incarnation as stopped,
        // so the close report that follows changes nothing, as a repeated report does.
        $owner = $this->createUser();
        $runner = $this->endpoint($service, $owner, ['localRecoveryEligible' => true, 'tabId' => new Uuid()->toString()]);
        $playing = $this->playing($service, $owner, $runner);
        $recovery = ['incarnationId' => $runner['incarnationId'], 'tabId' => $runner['recoveryTabId'], 'recoveryCredential' => $runner['recoveryCredential'],
            'checkpoint' => $this->checkpoint($playing, 99.0)];
        $recovered = $service->execute($owner, PlaybackOperation::RecoverLocal, $recovery);
        self::assertNull($recovered['activeIncarnationId']);
        self::assertSame(99.0, $recovered['position']);
        $after = $this->committed($owner);
        $closed = $this->close($service, $owner, $runner, $this->checkpoint($playing, 97.25));
        self::assertNull($closed['activeIncarnationId']);
        self::assertSame(99.0, $closed['position']);
        self::assertSame($recovered['revision'], $closed['revision']);
        self::assertSame(['observed_state' => 'stopped', 'position' => 42.5, 'retired' => true, 'available' => false], $this->incarnation($runner['incarnationId']));
        self::assertSame($after, $this->committed($owner));
        // The same proof once more answers with its recorded result and changes nothing.
        $replayed = $service->execute($owner, PlaybackOperation::RecoverLocal, $recovery);
        self::assertSame('recovered', $replayed['recoveryResult']['outcome']);
        // The stored result comes back from a JSON column, whose key order is its own.
        self::assertEquals($recovered['recoveryResult'], $replayed['recoveryResult']);
        self::assertSame($after, $this->committed($owner));
    }

    public function testAReportWithoutTheAuthorityOfItsIncarnationIsRefusedAndChangesNothing(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $other = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $runner);
        $report = ['incarnationId' => $runner['incarnationId'], 'checkpoint' => $this->checkpoint($playing, 97.25)];
        $before = $this->committed($owner);

        $this->assertRefused('An endpoint capability is required.', $service, $owner, PlaybackOperation::Close, $report);
        $this->assertRefused('An endpoint capability is required.', $service, $owner, PlaybackOperation::Close, $report, ['capability' => '']);
        $this->assertRefused('An endpoint capability is required.', $service, $owner, PlaybackOperation::Close, ['checkpoint' => $report['checkpoint']], $runner);
        $this->assertRefused('Endpoint authority is invalid.', $service, $owner, PlaybackOperation::Close, $report, ['capability' => str_repeat('0', 64)]);
        $this->assertRefused('Endpoint authority is invalid.', $service, $owner, PlaybackOperation::Close, $report, $other);
        $this->assertRefused('Endpoint authority is invalid.', $service, $this->createUser(), PlaybackOperation::Close, $report, $runner);

        self::assertSame($before, $this->committed($owner));
        self::assertSame($runner['incarnationId'], $service->execute($owner, PlaybackOperation::Snapshot, [])['activeIncarnationId']);
    }

    public function testAReportThatIsInvalidOrWhoseTransactionFailsLeavesNoTrace(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $runner);
        $before = $this->committed($owner);

        foreach ([null, ['revision' => -1] + $this->checkpoint($playing, 1.0), ['position' => -1.0] + $this->checkpoint($playing, 1.0),
            array_diff_key($this->checkpoint($playing, 1.0), ['media' => true])] as $checkpoint) {
            try {
                $service->execute($owner, PlaybackOperation::Close, ['incarnationId' => $runner['incarnationId'], 'checkpoint' => $checkpoint], $runner['capability']);
                self::fail('A close report without a usable checkpoint was accepted.');
            } catch (InvalidInputException) {
                self::assertSame($before, $this->committed($owner));
            }
        }

        // The retirement, the release and the superseded commands commit or roll back as one.
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        self::assertNull($this->close($service, $owner, $runner, $this->checkpoint($playing, 97.25))['activeIncarnationId']);
        $connection->rollBack();
        self::assertSame($before, $this->committed($owner));
        self::assertSame($runner['incarnationId'], $service->execute($owner, PlaybackOperation::Snapshot, [])['activeIncarnationId']);
    }
}
