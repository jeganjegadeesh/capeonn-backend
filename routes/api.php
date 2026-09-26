<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/uploads', function (\Illuminate\Http\Request $request) {
        $path = $request->file('file')->store('uploads', 'public');
        return response()->json(['success' => true, 'path' => $path]);
    });
    Route::get('/ping', function () {
        return response()->json(['success' => true, 'message' => 'pong']);
    });
});