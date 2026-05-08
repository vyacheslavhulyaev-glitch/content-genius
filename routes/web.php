<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ai', function () {

    $client = OpenAI::client(env('OPENAI_API_KEY'));

    $response = $client->chat()->create([
        'model' => env('OPENAI_MODEL'),
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Hello from Laravel',
            ],
        ],
    ]);

    return $response['choices'][0]['message']['content'];
});
