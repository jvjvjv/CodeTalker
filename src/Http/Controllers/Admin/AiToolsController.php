<?php

namespace Jvjvjv\CodeTalker\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AiToolsController extends BaseAdminController
{
    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('ai/Index', [
            'navBlocks' => $this->navBlocksFor('/admin/ai', $request),
        ]);
    }
}
