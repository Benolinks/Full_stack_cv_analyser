<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Smalot\PdfParser\Parser;

class UploadController extends Controller
{
    public function uploads(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|array|min:1',
            'file.*' => 'required|file|mimes:pdf|max:10240',
            'jobDescription' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'error' => $validator->errors()->first(),
            ], 422);
        }

        $validated = $validator->validated();

        $results = [];

        // OpenRouter configuration
        $apiKey = config('services.openrouter.key');
        $model = config('services.openrouter.model');

        if (!$apiKey || !$model) {
            return response()->json([
                'success' => false,
                'message' => 'OpenRouter API key or model is missing.',
            ], 500);
        }

        foreach ($validated['file'] as $file) {

            $upload = null;

            try {

                // 1. Store uploaded PDF
                $path = $file->store('cv', 'public');

                if (!$path) {
                    throw new \RuntimeException(
                        'Unable to store uploaded PDF.'
                    );
                }

                // 2. Create database record
                $upload = Upload::create([
                    'file' => $path,
                    'jobDescription' => $validated['jobDescription'],
                    'status' => 'pending',
                ]);

                $upload->update([
                    'status' => 'processing',
                ]);

                // 3. Extract PDF text
                $parser = new Parser();

                $pdf = $parser->parseFile(
                    Storage::disk('public')->path($path)
                );

                $pdfText = trim($pdf->getText());

                if (empty($pdfText)) {
                    throw new \RuntimeException(
                        'No readable text found in PDF. The document may be scanned.'
                    );
                }

                // 4. Create AI prompt
                $prompt = "
You are a professional ATS resume analysis assistant.

Compare the resume against the supplied job description.

JOB DESCRIPTION:
{$upload->jobDescription}

RESUME:
{$pdfText}

Analyse:

1. Relevant skills.
2. Relevant work experience.
3. Education and qualifications.
4. Missing job-related keywords.
5. Resume formatting and readability.
6. Areas that need improvement.

Give an overall match score from 0 to 100.

Do not invent qualifications, skills or experience.

Return valid JSON with exactly these fields:

{
    \"match_score\": 0,
    \"relevant_skills\": [],
    \"relevant_experience\": [],
    \"education\": [],
    \"missing_keywords\": [],
    \"formatting_feedback\": [],
    \"recommendations\": []
}

The match_score must be a number between 0 and 100.
";

                // 5. Send request to OpenRouter
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout(180)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => $model,
                        'max_tokens' => 4000,
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $prompt,
                            ],
                        ],
                        'response_format' => [
                            'type' => 'json_object',
                        ],
                    ]);

                // 6. Check API response
                if (!$response->successful()) {

                    Log::error('OpenRouter API Error', [
                        'status' => $response->status(),
                        'response' => $response->body(),
                        'model' => $model,
                    ]);

                    throw new \RuntimeException(
                        'OpenRouter request failed with HTTP status '
                            . $response->status()
                    );
                }

                // 7. Retrieve OpenRouter response
                $analysisText = $response->json(
                    'choices.0.message.content'
                );

                if (!is_string($analysisText) || trim($analysisText) === '') {

                    Log::error('OpenRouter returned unexpected response', [
                        'response' => $response->json(),
                    ]);

                    throw new \RuntimeException(
                        'OpenRouter returned an empty analysis.'
                    );
                }

                // 8. Decode AI JSON response
                $analysis = json_decode(
                    $analysisText,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                // 9. Validate analysis
                if (
                    !is_array($analysis) ||
                    !isset($analysis['match_score']) ||
                    !is_numeric($analysis['match_score']) ||
                    $analysis['match_score'] < 0 ||
                    $analysis['match_score'] > 100
                ) {
                    throw new \RuntimeException(
                        'AI returned an invalid analysis structure.'
                    );
                }

                // 10. Save analysis to database
                $upload->update([
                    'analysis' => json_encode($analysis),
                    'status' => 'completed',
                ]);

                // 11. Collect successful result
                $results[] = [
                    'id' => $upload->id,
                    'filename' => $file->getClientOriginalName(),
                    'status' => 'completed',
                    'analysis' => $analysis,
                ];
            } catch (\Throwable $e) {

                // 12. Log actual error
                Log::error('CV Analysis Failed', [
                    'filename' => $file->getClientOriginalName(),
                    'upload_id' => $upload?->id,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                if ($upload) {
                    $upload->update([
                        'status' => 'failed',
                    ]);
                }

                // 13. Collect failed result
                $results[] = [
                    'id' => $upload?->id,
                    'filename' => $file->getClientOriginalName(),
                    'status' => 'failed',
                    'message' => 'Unable to analyse this resume.',
                ];
            }
        }

        // 14. Return all results to React
        return response()->json([
            'success' => true,
            'message' => 'CV processing finished.',
            'results' => $results,
        ], 200);
    }
}
