## ADDED Requirements

### Requirement: An operator is a bounded, host-dispatched unit of work

The package SHALL provide an `AiOperator` configuration — an `AiSystem`, a `prompt_template`, and an allowed-tools list — for AI work that is not triggered by a human sending a chat message.

#### Scenario: Configuring an operator

- **WHEN** a host creates an `AiOperator` with a name, slug, `AiSystem`, and `prompt_template`
- **THEN** it is a distinct record from any `AiPersona`, with no conversation history of its own beyond what runs produce

#### Scenario: An operator run is bounded

- **WHEN** an operator is dispatched
- **THEN** it executes one interpolated prompt through the same agentic tool loop a chat turn uses, capped at the same step limit
- **AND** it does not continue running after that loop finishes, regardless of whether it accomplished a broader goal

### Requirement: Dispatch is entirely the host's concern

The package SHALL NOT own a trigger, event bus, or scheduling system for operators. Running one is a single job dispatch, the same shape the package already uses internally for post-conversation memory extraction.

#### Scenario: Any observer or listener can dispatch a run

- **WHEN** a host's Eloquent observer, event listener, console command, or the package's own code calls `dispatch(new RunAiOperatorJob($operator, $context))`
- **THEN** the operator runs — the package places no constraint on what triggered the dispatch

#### Scenario: No package-owned scheduling

- **WHEN** a host wants an operator to run on a schedule
- **THEN** the host registers that schedule itself (e.g. via Laravel's own `Schedule` facade) and dispatches the job from it — the package does not provide operator-specific scheduling

### Requirement: The prompt is built from a template and caller-supplied context

`AiOperator::prompt_template` SHALL support dotted placeholders resolved against the `$context` array passed at dispatch time.

#### Scenario: Interpolating nested context

- **WHEN** a template contains `{{order.total}}` and `$context` is `['order' => ['total' => 42]]`
- **THEN** the interpolated prompt contains `42` in place of the placeholder

#### Scenario: A placeholder with no matching context fails the dispatch

- **WHEN** a template references a placeholder that resolves to nothing in `$context`
- **THEN** the run fails rather than sending a prompt with the placeholder silently blanked out

### Requirement: Operator runs share the package's existing audit and cost infrastructure

An operator run SHALL be recorded as an `AiConversation` (feature `operator:{slug}`), so existing request/response logging, raw exchange capture, and usage tracking apply without new logging tables.

#### Scenario: A run is logged like a chat turn

- **WHEN** an operator runs
- **THEN** an `AiConversation` is created with `ai_operator_id` set and `ai_persona_id` null, and the run's request/response is recorded as an `AiLlmMessage` against it

#### Scenario: Usage rolls up the same way

- **WHEN** conversation usage is synced or backfilled
- **THEN** an operator's `AiConversation` rows are included exactly as a persona's are, with no operator-specific code path

### Requirement: Operators are managed through a service

The package SHALL expose `AiOperatorManager`, matching the shape of the package's other `Services/Management/` managers (create, update, delete, list, static `rules()`), so a host builds its own admin screens against it.

#### Scenario: Reusing validation rules

- **WHEN** a host builds a form request for creating or updating an operator
- **THEN** it can call `AiOperatorManager::rules()` (or `createRules()`/`updateRules()`) rather than reimplementing validation
