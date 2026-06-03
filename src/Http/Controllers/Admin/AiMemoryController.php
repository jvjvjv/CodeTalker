<?php

namespace Jvjvjv\CodeTalker\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Jvjvjv\CodeTalker\Http\Requests\Admin\StoreAiFeatureMemoryRequest;
use Jvjvjv\CodeTalker\Http\Requests\Admin\UpdateAiFeatureMemoryRequest;
use Jvjvjv\CodeTalker\Jobs\ProcessAiMemoryJob;
use Jvjvjv\CodeTalker\Models\AiFeatureMemory;
use Jvjvjv\CodeTalker\Services\AiMemoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AiMemoryController extends Controller
{
    /**
     * Display all AI feature memories.
     */
    public function index(Request $request): InertiaResponse
    {
        $query = AiFeatureMemory::query()
            ->with('sourceConversation')
            ->orderByDesc('is_active')
            ->orderByDesc('confidence')
            ->orderByDesc('times_reinforced');

        if ($request->filled('feature')) {
            $query->forFeature($request->input('feature'));
        }

        if ($request->filled('category')) {
            $query->byCategory($request->input('category'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $memories = $query->paginate(50);

        $features = AiFeatureMemory::query()
            ->select('feature')
            ->distinct()
            ->pluck('feature');

        return Inertia::render('ai/memories/Index', [
            'memories' => $memories,
            'features' => $features,
            'filters' => $request->only(['feature', 'category', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new memory entry.
     */
    public function create(): InertiaResponse
    {
        return Inertia::render('ai/memories/Create');
    }

    /**
     * Store a manually created memory entry.
     */
    public function store(StoreAiFeatureMemoryRequest $request): RedirectResponse
    {
        AiFeatureMemory::create($request->validated());

        return redirect()->route('admin.ai.memories.index')
            ->with('success', 'Memory entry created successfully.');
    }

    /**
     * Show the form for editing a memory entry.
     */
    public function edit(AiFeatureMemory $memory): InertiaResponse
    {
        return Inertia::render('ai/memories/Edit', [
            'memory' => $memory,
        ]);
    }

    /**
     * Update the specified memory entry.
     */
    public function update(UpdateAiFeatureMemoryRequest $request, AiFeatureMemory $memory): RedirectResponse
    {
        $memory->update($request->validated());

        return redirect()->route('admin.ai.memories.index')
            ->with('success', 'Memory entry updated successfully.');
    }

    /**
     * Delete the specified memory entry.
     */
    public function destroy(AiFeatureMemory $memory): RedirectResponse
    {
        $memory->delete();

        return redirect()->route('admin.ai.memories.index')
            ->with('success', 'Memory entry deleted.');
    }

    /**
     * Rebuild all memories for a feature from scratch.
     */
    public function rebuild(string $feature, AiMemoryService $memoryService): RedirectResponse
    {
        $memoryService->rebuildMemories($feature);

        return redirect()->route('admin.ai.memories.index', ['feature' => $feature])
            ->with('success', "Memories for \"{$feature}\" have been rebuilt.");
    }
}
