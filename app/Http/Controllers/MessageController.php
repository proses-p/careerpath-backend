<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request, Application $application)
    {
        $this->authorize('view', $application);

        $application->messages()->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return $application->messages()->with('sender:id,name')->get();
    }

    public function store(Request $request, Application $application)
    {
        $this->authorize('view', $application);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        return $application->messages()
            ->create(['sender_id' => $request->user()->id, 'body' => $data['body']])
            ->load('sender:id,name');
    }
}
