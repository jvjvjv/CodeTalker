## ADDED Requirements

### Requirement: An AiSystem declares the role it serves

`AiSystem` SHALL carry a role of `chat`, `audio`, or `transcription`, defaulting to `chat`, so a single table can describe chat endpoints and voice endpoints without ambiguity.

#### Scenario: Existing records keep working

- **WHEN** the migration runs against a database containing `AiSystem` records created before this change
- **THEN** every existing record resolves as the `chat` role
- **AND** no backfill command is required
- **AND** every existing chat call path behaves identically

#### Scenario: An audio record is created

- **WHEN** an administrator creates an `AiSystem` with a provider that supports speech and a role of `audio`
- **THEN** the record is stored with that role
- **AND** it is excluded from resolution for chat features

### Requirement: Provider/role combinations are validated before they are stored

The package SHALL reject an `AiSystem` whose provider cannot serve its role, at validation time rather than at call time.

#### Scenario: A local provider is assigned an audio role

- **WHEN** an administrator submits an `AiSystem` with provider `lm-studio` or `openai-compatible` and a role of `audio` or `transcription`
- **THEN** validation fails with a message naming both the provider and the role
- **AND** no record is created

#### Scenario: An audio-only provider is assigned a chat role

- **WHEN** an administrator submits an `AiSystem` with provider `elevenlabs` and a role of `chat`
- **THEN** validation fails
- **AND** the provider is not offered for chat in the admin interface

#### Scenario: A capable provider is accepted

- **WHEN** an administrator submits an `AiSystem` with provider `openai`, `gemini`, or `elevenlabs` and a role of `audio` or `transcription`
- **THEN** the record is created

### Requirement: Audio providers resolve credentials from AiSystem records

Speech and transcription calls SHALL resolve their provider and credentials from the `AiSystem` record, through the same configurator the chat path uses, so a host configures each provider exactly once.

#### Scenario: Credentials come from the record, not the environment

- **WHEN** a speech or transcription call is made for an `AiSystem`
- **THEN** the provider is resolved from a config entry injected for that record, carrying the record's own encrypted API key and base URL
- **AND** no value from the host's `ai.providers.*` environment configuration is consulted

#### Scenario: ElevenLabs maps to its upstream driver

- **WHEN** a record with provider `elevenlabs` is resolved
- **THEN** the injected provider config declares the `eleven` driver

#### Scenario: A record with no model uses the provider default

- **WHEN** a speech or transcription call is made for a record whose model is blank
- **THEN** the call reaches the provider with no explicit model
- **AND** the provider's own default model applies

### Requirement: Speech and transcription are invocable by system or by feature

The package SHALL expose a speech service and a transcription service, each addressable by an explicit `AiSystem` or by a feature key, mirroring the chat path's `forSystem()` / `forFeature()` shape.

#### Scenario: Generating speech from text

- **WHEN** a host calls the speech service with an audio-role system and a string of text
- **THEN** the provider generates speech and the upstream audio response is returned unwrapped
- **AND** an optional voice and instruction may be supplied

#### Scenario: Transcribing audio

- **WHEN** a host calls the transcription service with a transcription-role system and an audio file or upload
- **THEN** the provider transcribes it and the upstream transcription response is returned unwrapped, including any diarized segments
- **AND** an optional language and a diarization flag may be supplied

#### Scenario: Resolving by feature

- **WHEN** a host calls either service with a feature key mapped to a system through the feature-default table
- **THEN** the mapped system is used

### Requirement: Role and activation are enforced at resolution

Both audio services and the existing chat agent factory SHALL apply the same resolution guards, so a system can never be used for a purpose it was not configured for.

#### Scenario: A wrong-role record is rejected

- **WHEN** a feature key used for speech resolves to a record whose role is `chat`
- **THEN** resolution fails with an error naming the expected and the actual role
- **AND** no provider call is made

#### Scenario: An inactive record is rejected

- **WHEN** a resolved record is inactive
- **THEN** resolution fails
- **AND** no provider call is made

#### Scenario: Chat resolution is unchanged

- **WHEN** a chat feature is resolved through the agent factory
- **THEN** it behaves exactly as before this change, including its existing error messages for a missing default and an inactive system
- **AND** it additionally fails if the mapped record is an audio or transcription record

### Requirement: Audio provider exchanges are capturable

Speech and transcription calls SHALL participate in the package's raw exchange capture on the same terms as chat calls.

#### Scenario: Capture is opted into by provider

- **WHEN** a host has added the audio record's provider to the raw-exchange provider allow-list
- **THEN** the verbatim request and response of a speech or transcription call are recorded against that system

#### Scenario: The default allow-list excludes audio providers

- **WHEN** a host has left the raw-exchange provider allow-list at its default
- **THEN** audio calls to cloud providers are not captured
- **AND** the README documents this, so the absence is understood as configuration rather than a defect

### Requirement: Audio calls are documented as untracked

The package SHALL state plainly that audio calls are not cost-tracked and not written to the LLM message log, so the omission is understood as a decision.

#### Scenario: A host developer looks for audio spend

- **WHEN** a developer reads the audio section of the README
- **THEN** it states that speech and transcription calls are not included in conversation usage or cost totals
- **AND** it gives the reason: the upstream audio response carries no usage data, and per-character and per-minute billing cannot be represented by the token-based pricing model
