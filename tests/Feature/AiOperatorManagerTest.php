<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Jvjvjv\CodeTalker\Models\AiConversation;
use Jvjvjv\CodeTalker\Models\AiOperator;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Services\Management\AiOperatorManager;
use Jvjvjv\CodeTalker\Tests\TestCase;

class AiOperatorManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasColumn('ai_conversations', 'uuid')) {
            Schema::table('ai_conversations', function ($table): void {
                $table->string('uuid')->nullable();
            });
        }
    }

    private function makeSystem(array $overrides = []): AiSystem
    {
        return AiSystem::create(array_merge([
            'name' => 'Test System',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'is_active' => true,
        ], $overrides));
    }

    private function manager(): AiOperatorManager
    {
        return $this->app->make(AiOperatorManager::class);
    }

    public function test_create_persists_a_valid_operator(): void
    {
        $system = $this->makeSystem();

        $operator = $this->manager()->create([
            'ai_system_id' => $system->id,
            'name' => 'Order Processor',
            'slug' => 'order-processor',
            'prompt_template' => 'A new order was placed: {{order.total}}.',
            'allowed_tools' => ['http-request'],
        ]);

        $this->assertSame('order-processor', $operator->slug);
        $this->assertSame(['http-request'], $operator->allowed_tools);
        $this->assertTrue($operator->fresh()->is_active);
    }

    public function test_create_rejects_invalid_data(): void
    {
        $this->expectException(ValidationException::class);

        $this->manager()->create([
            'name' => '',
            'slug' => '',
            'ai_system_id' => 999999,
            'prompt_template' => '',
        ]);
    }

    public function test_update_rules_allow_the_operators_own_slug(): void
    {
        $system = $this->makeSystem();

        $operator = $this->manager()->create([
            'ai_system_id' => $system->id,
            'name' => 'Order Processor',
            'slug' => 'order-processor',
            'prompt_template' => 'Process it.',
        ]);

        $updated = $this->manager()->update($operator, [
            'ai_system_id' => $system->id,
            'name' => 'Order Processor v2',
            'slug' => 'order-processor',
            'prompt_template' => 'Process it, v2.',
        ]);

        $this->assertSame('Order Processor v2', $updated->name);
    }

    public function test_delete_removes_the_operator(): void
    {
        $system = $this->makeSystem();

        $operator = $this->manager()->create([
            'ai_system_id' => $system->id,
            'name' => 'Order Processor',
            'slug' => 'order-processor',
            'prompt_template' => 'Process it.',
        ]);

        $this->manager()->delete($operator);

        $this->assertNull(AiOperator::find($operator->id));
    }

    public function test_list_with_usage_rolls_up_conversation_costs(): void
    {
        $system = $this->makeSystem();

        $operator = $this->manager()->create([
            'ai_system_id' => $system->id,
            'name' => 'Order Processor',
            'slug' => 'order-processor',
            'prompt_template' => 'Process it.',
        ]);

        AiConversation::create([
            'ai_system_id' => $system->id,
            'ai_operator_id' => $operator->id,
            'feature' => $operator->featureKey(),
            'status' => 'completed',
            'usage_cost_usd' => 0.05,
        ]);

        $listed = $this->manager()->listWithUsage();

        $this->assertSame(1, $listed[0]['runs_count']);
        $this->assertSame(0.05, $listed[0]['usage']['cost_usd']);
    }
}
