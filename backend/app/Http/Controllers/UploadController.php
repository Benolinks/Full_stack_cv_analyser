<?php

namespace App\Http\Controllers;

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

    dd($data);

    if ($data->fails()) {
      return response()->json([
        'message' => 'Unexpected Error Please try Again',
        'error' => $data->errors(),
        'verified' => false,
      ], 422);
    }

    $validated = $data->validated();

    dd($validated);

    foreach ($validated['file'] as $file) {

      $path = $file->store('cv', 'public');

      $dumb= Upload::create([
        'file' => $path,
        'jobDescription' => $validated['jobDescription'],
      ]);
      dd($dumb);
      dd($data);
    }


    return response()->json([
      'success' => true,
      'message' => 'CV uploaded successfully',
    ], 200);
  }
}
