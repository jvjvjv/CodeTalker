<?php

namespace Jvjvjv\CodeTalker\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AiToolsController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('ai/Index');
    }
}
