# thinking-levels Specification

## Purpose
Lets an AiSystem ask its model for a graded amount of reasoning instead of a yes/no switch, using one provider-neutral scale that the package translates into each provider's own request, so a host configures "how hard to think" once and gets a valid request on every provider.

## Requirements

### Requirement: A system declares a thinking level on one ordered scale

An `AiSystem` SHALL carry an optional thinking level drawn from a single ordered scale, `off` < `minimal` < `low` < `medium` < `high` < `xhigh` < `max`. An unset level SHALL mean that no reasoning parameter is sent and the provider's default applies.

#### Scenario: Storing a level

- **WHEN** a system is saved with a thinking level of `high`
- **THEN** reading the system back returns the `high` level

#### Scenario: No level set

- **WHEN** a chat request is built for a system whose thinking level is unset
- **THEN** the request carries no reasoning, thinking, or effort parameter added by the package
- **AND** any value the host supplied through `config.provider_options` is still sent

#### Scenario: An unknown level is refused

- **WHEN** a system is submitted with a thinking level that is not on the scale
- **THEN** validation fails naming the field

### Requirement: A level is clamped to what the provider and model support

When a provider or model does not offer the requested level, the package SHALL send the nearest level it does offer, preferring the next lower level and falling back to the next higher one, and SHALL never send a value the provider is known to reject.

#### Scenario: Clamping down

- **WHEN** a system requests `xhigh` from a provider whose model offers only `low`, `medium`, and `high`
- **THEN** the request carries `high`

#### Scenario: Clamping up

- **WHEN** a system requests `minimal` from a model whose lowest level is `low`
- **THEN** the request carries `low`

#### Scenario: Off on a model that cannot stop thinking

- **WHEN** a system requests `off` from a model that always reasons and rejects a request to disable it
- **THEN** the request asks for that model's lowest effort instead of disabling thinking
- **AND** the request does not fail
- **AND** no reasoning is shown, as for any `off` system

### Requirement: Anthropic systems receive the thinking shape their model accepts

An Anthropic system SHALL receive adaptive thinking with an effort for models that support effort, and a token budget scaled by level for models that only support a budget.

#### Scenario: A current Claude model

- **WHEN** a request is built for an Anthropic system on an effort-capable model with level `medium`
- **THEN** the request enables adaptive thinking with a readable reasoning display
- **AND** carries an effort of `medium`
- **AND** carries no thinking token budget

#### Scenario: An older budget-only Claude model

- **WHEN** a request is built for an Anthropic system on a budget-only model with a level above `off`
- **THEN** the request enables thinking with a token budget that grows with the level
- **AND** the budget is at least 1024 tokens and less than the request's maximum output tokens
- **AND** no thinking is requested when the maximum output tokens leave no room for the minimum budget

#### Scenario: Off on a model that can disable thinking

- **WHEN** a request is built for an Anthropic system with level `off` on a model that runs without thinking when it is not requested
- **THEN** the request carries no thinking parameter

### Requirement: OpenAI and xAI systems receive a reasoning effort

An OpenAI or xAI system with a level SHALL receive a reasoning effort, clamped to the efforts its model accepts, and SHALL request a reasoning summary whenever the level is above `off`, so reasoning can be shown.

#### Scenario: OpenAI with a level

- **WHEN** a request is built for an OpenAI system on a reasoning model with level `low`
- **THEN** the request carries a reasoning effort of `low`
- **AND** requests a reasoning summary

#### Scenario: A model that takes no effort

- **WHEN** a request is built for a system whose model is known not to accept a reasoning effort
- **THEN** the request carries no reasoning effort
- **AND** the request does not fail

### Requirement: Gemini systems receive a thinking configuration for their generation

A Gemini system with a level SHALL receive a thinking configuration using a thinking level on models that take one and a thinking token budget on models that take a budget, and SHALL include thoughts in the response whenever the level is above `off`.

#### Scenario: A level-capable Gemini model

- **WHEN** a request is built for a Gemini system on a level-capable model with level `high`
- **THEN** the generation config carries a thinking level of `high` and asks for thoughts to be included

#### Scenario: A budget-only Gemini model

- **WHEN** a request is built for a Gemini system on a budget-only model with level `medium`
- **THEN** the generation config carries a thinking budget scaled to `medium` and asks for thoughts to be included

### Requirement: LM Studio systems receive their native reasoning level

An LM Studio system SHALL receive the level through LM Studio's own reasoning field, and only when the model has been reported as reasoning-capable.

#### Scenario: A reasoning-capable local model

- **WHEN** a request is built for an LM Studio system whose model is reported as reasoning-capable, with level `medium`
- **THEN** the request carries a reasoning value of `medium`

#### Scenario: A model not reported as reasoning-capable

- **WHEN** a request is built for an LM Studio system whose model is not reported as reasoning-capable
- **THEN** the request carries no reasoning value, whatever the level

### Requirement: Generic OpenAI-compatible systems are untouched

An `openai-compatible` system SHALL receive no reasoning parameter from the level, because those endpoints share no standard for one. Reasoning visibility SHALL still follow the level.

#### Scenario: A level on a generic endpoint

- **WHEN** a request is built for an `openai-compatible` system with level `high`
- **THEN** the request carries no reasoning parameter added by the package
- **AND** reasoning the endpoint streams is shown

### Requirement: Reasoning is shown only when the level asks for it

Reasoning text SHALL reach a turn's events only when the system's resolved level is above `off`, for every provider, regardless of whether the provider returned reasoning anyway.

#### Scenario: Level above off

- **WHEN** a turn runs on a system with level `low` and the model streams reasoning
- **THEN** the turn emits that reasoning as reasoning events and stores it with the reply

#### Scenario: Level off or unset

- **WHEN** a turn runs on a system whose level is `off` or unset and the model streams reasoning anyway
- **THEN** the turn emits no reasoning events and stores no reasoning
- **AND** the reply text is unaffected

### Requirement: Existing thinking settings carry over on upgrade

The upgrade SHALL preserve each existing system's intent: a system that had thinking enabled SHALL think at `medium`, one that had it disabled SHALL be `off`, and one with no setting SHALL have no level.

#### Scenario: Migrating existing systems

- **WHEN** the migration runs against systems whose thinking flag is true, false, and unset
- **THEN** their thinking levels become `medium`, `off`, and unset respectively
