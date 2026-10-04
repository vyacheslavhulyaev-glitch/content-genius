<?php

namespace App\Services;

use App\Exceptions\AiAccountingUnavailable;
use App\Exceptions\AiBudgetExceeded;
use App\Models\AIRequest;
use App\Models\ProviderCall;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use Throwable;

class TrackedOpenAI
{
    public function __construct(private GlobalAiBudget $budget, private AiPricing $pricing) {}

    public function chat(ClientContract $client, AIRequest $request, string $operation, array $parameters): CreateResponse
    {
        try {
            $call = $this->budget->reserve($request, $operation, $parameters);
        } catch (AiBudgetExceeded|AiAccountingUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiAccountingUnavailable;
        }

        try {
            $response = $client->chat()->create($parameters);
        } catch (Throwable $exception) {
            $this->save($call, ['status' => 'failed', 'finished_at' => now('UTC')]);
            throw $exception;
        }

        $input = $response->usage?->promptTokens;
        $output = $response->usage?->completionTokens;
        $input = $input !== null && $input >= 0 ? $input : null;
        $output = $output !== null && $output >= 0 ? $output : null;
        $total = $input !== null && $output !== null ? $input + $output : $response->usage?->totalTokens;
        $total = $total !== null && $total >= 0 ? $total : null;
        $rates = $call->input_price_per_million === null || $call->output_price_per_million === null ? null
            : ['input' => (float) $call->input_price_per_million, 'output' => (float) $call->output_price_per_million];
        $this->save($call, [
            'status' => 'completed', 'response_model' => $response->model, 'finished_at' => now('UTC'),
            'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total,
            'estimated_cost' => $this->pricing->estimate($rates, $input, $output),
        ]);

        return $response;
    }

    private function save(ProviderCall $call, array $attributes): void
    {
        try {
            $call->update($attributes);
        } catch (Throwable) {
            // Retain the committed reservation and stop the pipeline if accounting cannot be saved.
            Log::error('Unable to persist provider call accounting', ['provider_call_id' => $call->id]);
            throw new AiAccountingUnavailable;
        }
    }
}
