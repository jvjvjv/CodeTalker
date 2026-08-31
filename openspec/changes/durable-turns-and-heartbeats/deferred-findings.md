# Deferred findings

Minor findings raised during review of this change and deliberately not fixed.
The whole-branch review triaged every one of them as safe to defer; they are
recorded here so they are not lost with the review workspace.

- (task 1) TeeingStream::record() has only indirect coverage via RawExchangeChatIntegrationTest; a direct unit test would be ~10 lines.
- (task 1) the processTextStream() Heartbeat-forwarding branch is untested; reordering the loop body so the error sniff runs first would throw on a Heartbeat (no ArrayAccess).
- (task 1) heartbeats are not yet visible end-to-end (that is Task 2's runner hop).
- (task 2) drainAndDecode() maps ANY ':'-prefixed line to a heartbeat; a future second comment-frame type would be miscounted. ': ping' would be tighter.
- (task 2) the new d.ts JSDoc block floats unattached between PageReloadEvent and the union's own docblock. Stylistic; typecheck passes.
- (task 3) no 'sequence' => 'integer' cast on AiTurnEvent; under PDO::ATTR_EMULATE_PREPARES it returns a string. Siblings do cast their ints.
- (task 3) ai_turn_events.created_at is nullable with an app-level default, where the sibling ai_conversation_messages uses ->useCurrent(). A raw DB::table insert would leave NULL.
- (task 3) neither foreignId is constrained(), so deleting a run leaves orphan events at the DB level. Task 8's prune command already deletes events by hand, consistent with the package's existing doctrine.
- (task 4) touchPoll()/requestCancel() use query-builder update(), so the caller's in-memory $run keeps pre-update attributes. Internally harmless — shouldStop()/stopStatusFor() always fresh().
- (task 4) stopStatusFor() returns Abandoned for a deleted run and for a healthy run called without a prior shouldStop() === true. Consistent with shouldStop() and carried by the docblock contract.
- (task 4) an externally written cancel is seen by the job's store only on its next re-read, so cancel latency is bounded at the throttle interval rather than zero.
- (task 5) the success-path finish() is unguarded, so in the outrun-retry_after degraded mode a run failed by failed() can be flipped back to Completed by the still-streaming first worker. Status flaps; no sequence corruption.
- (task 5) the post-loop shouldStop() re-read can mislabel a stop-vs-complete race in two narrow windows (abandoned-then-polled reads as Completed; completed-then-threshold-crossed reads as Abandoned). Label only — events and the recorded message are intact either way.
- (task 6) a run deleted mid-stream ends as a cleanly finished turn with [DONE] and no error frame. Matches the design doc's error-handling table; revisit if pruning ever races live readers.
- (task 6) $event->payload + ['_seq' => ...] is an array union, so a stored payload containing a '_seq' key would override the real sequence. Writer-controlled today; theoretical.
- (task 6) the stub's eventsAfter ignores its $sequence argument, so the new tests cannot catch a drain that fails to advance the cursor it passes to the store. Real-store termination argument still holds.
- (task 7) resumeTurn() is lazy — app(TurnEventStream::class) and the first touchPoll() do not happen until the caller advances the generator.
- (task 7) sync queue driver makes the returned $run stale (in-memory Queued, DB terminal).
- (task 7) test file import ordering broke the file's grouping. Cosmetic.
- (task 8) the two deletes are not wrapped in a transaction unlike the package's other hand-cascades. Partial failure self-heals because events are deleted first, so leftover runs are simply re-selected next sweep.
