<?php

namespace App\Services\Analysis;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnalysisClient
{
    public function agent(string $key, array $context, int $timeout = 120): array
    {
        $response = Http::baseUrl(rtrim((string) config('services.analysis.url'), '/'))
            ->withHeaders(['X-Internal-Token' => (string) config('services.analysis.token')])
            ->timeout($timeout)
            ->acceptJson()
            ->post('/internal/agents/'.$key, ['context' => $context]);

        if ($response->failed()) {
            throw new RuntimeException('Analysis service '.$key.' returned HTTP '.$response->status());
        }

        return $response->json() ?? [];
    }

    public function render(string $markdown, string $format, string $destPath): array
    {
        $response = Http::baseUrl(rtrim((string) config('services.analysis.url'), '/'))
            ->withHeaders(['X-Internal-Token' => (string) config('services.analysis.token')])
            ->timeout(60)
            ->acceptJson()
            ->post('/internal/render', [
                'markdown' => $markdown,
                'export_format' => $format,
                'dest_path' => $destPath,
            ]);
        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }
}
