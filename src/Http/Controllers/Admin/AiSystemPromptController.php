<?php

namespace Jvjvjv\CodeTalker\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Jvjvjv\CodeTalker\Http\Requests\StoreAiSystemPromptRequest;
use Jvjvjv\CodeTalker\Http\Requests\UpdateAiSystemPromptRequest;
use Jvjvjv\CodeTalker\Models\AiSystem;
use Jvjvjv\CodeTalker\Models\AiSystemPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AiSystemPromptController extends Controller
{
    /**
     * Display all system prompts.
     */
    public function index(): InertiaResponse
    {
        $prompts = AiSystemPrompt::withCount('aiSystems')
            ->ordered()
            ->get();

        return Inertia::render('ai/system-prompts/Index', [
            'prompts' => $prompts,
        ]);
    }

    /**
     * Show the form for creating a new system prompt.
     */
    public function create(): InertiaResponse
    {
        return Inertia::render('ai/system-prompts/Create');
    }

    /**
     * Store a new system prompt.
     */
    public function store(StoreAiSystemPromptRequest $request): RedirectResponse
    {
        $prompt = AiSystemPrompt::create($request->validated());

        return redirect()->route('admin.ai.system-prompts.index')
            ->with('success', "System prompt \"{$prompt->title}\" created successfully.");
    }

    /**
     * Show the form for editing a system prompt.
     */
    public function edit(AiSystemPrompt $aiSystemPrompt): InertiaResponse
    {
        return Inertia::render('ai/system-prompts/Edit', [
            'prompt' => $aiSystemPrompt->loadCount('aiSystems'),
        ]);
    }

    /**
     * Update a system prompt.
     */
    public function update(UpdateAiSystemPromptRequest $request, AiSystemPrompt $aiSystemPrompt): RedirectResponse
    {
        $aiSystemPrompt->update($request->validated());

        return redirect()->route('admin.ai.system-prompts.index')
            ->with('success', "System prompt \"{$aiSystemPrompt->title}\" updated successfully.");
    }

    /**
     * Delete a system prompt and null out references on any AI systems.
     */
    public function destroy(AiSystemPrompt $aiSystemPrompt): RedirectResponse
    {
        $title = $aiSystemPrompt->title;
        $systemCount = AiSystem::where('system_prompt_id', $aiSystemPrompt->id)->count();

        AiSystem::where('system_prompt_id', $aiSystemPrompt->id)->update(['system_prompt_id' => null]);

        $aiSystemPrompt->delete();

        $message = $systemCount > 0
            ? "System prompt \"{$title}\" deleted. {$systemCount} AI system(s) no longer have a prompt assigned."
            : "System prompt \"{$title}\" deleted successfully.";

        return redirect()->route('admin.ai.system-prompts.index')
            ->with('success', $message);
    }

    /**
     * Update a system prompt via JSON (used by the inline Edit Prompt modal).
     */
    public function apiUpdate(UpdateAiSystemPromptRequest $request, AiSystemPrompt $aiSystemPrompt): JsonResponse
    {
        $aiSystemPrompt->update($request->validated());

        return response()->json(['prompt' => $aiSystemPrompt->fresh()]);
    }
}
