<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UploadController extends Controller
{
  //

  public function uploads(Request $request)
  {
    $data = Validator::make($request->all(), [
      'file' => 'required|array|min:1',
      'file.*' => 'required|file|mimes:pdf,docx',
      'jobDescription' => 'required|string',
    ]);

    

    if ($data->fails()) {
      return response()->json([
        'message' => 'Unexpected Error Please try Again',
        'error' => $data->error(),
        'verified' => false,
      ], 422);
    }

    $validated = $data->validated();

    

    foreach ($validated['file'] as $file) {

      
            $path = $file->store('cv', 'public');
      
            $dumb= Upload::create([
              'file' => $path,
              'jobDescription' => $validated['jobDescription'],
            ]);

    }
      

    return response()->json([
      'success' => true,
      'message' => 'CV uploaded successfully',
      'analysis' => [
            'total_cv' => count($file),
            'files' => collect($file)->map(fn($file) => [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]),
        ],
    ], 200);
  }
}
