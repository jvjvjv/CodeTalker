## ADDED Requirements

### Requirement: The system manager validates and translates thinking settings

The system manager SHALL accept a `thinking_level` on create and update, validated against the thinking scale. For one release it SHALL also accept the deprecated boolean `enable_thinking` and translate it when no `thinking_level` is supplied.

#### Scenario: Supplying a level

- **WHEN** a system is created or updated with `thinking_level` set to `low`
- **THEN** the stored level is `low`

#### Scenario: Supplying only the deprecated flag

- **WHEN** a system is created or updated with `enable_thinking` true and no `thinking_level`
- **THEN** the stored level is `medium`
- **AND** with `enable_thinking` false the stored level is `off`

#### Scenario: Supplying both

- **WHEN** a system is submitted with both `thinking_level` and `enable_thinking`
- **THEN** `thinking_level` wins and `enable_thinking` is ignored

#### Scenario: An update that omits thinking keeps it

- **WHEN** a system is updated with neither `thinking_level` nor `enable_thinking` supplied
- **THEN** the stored level is unchanged

#### Scenario: An invalid level

- **WHEN** a system is submitted with a `thinking_level` that is not on the scale
- **THEN** validation fails naming `thinking_level` and nothing is written

### Requirement: The levels a system can honor are discoverable

The system manager SHALL list, for a provider and model, the thinking levels that pair can actually honor, in scale order, together with whether the level controls reasoning effort or only reasoning visibility, so a host can offer only meaningful choices.

#### Scenario: Listing levels for a current model

- **WHEN** a host asks for the levels of a provider and model that supports graded effort
- **THEN** the result lists each supported level in scale order and reports that the level controls effort
- **AND** includes `off` even when the model cannot stop reasoning, because `off` still hides reasoning

#### Scenario: Listing levels where only visibility can be controlled

- **WHEN** a host asks for the levels of a provider or model the package sends no reasoning parameter to
- **THEN** the result lists only `off` and `medium` and reports that the level controls visibility only
- **AND** a host can render that as a show/hide control instead of a level selector

#### Scenario: Listing levels for a voice provider

- **WHEN** a host asks for the levels of a provider that cannot serve the chat role
- **THEN** the result lists no levels
