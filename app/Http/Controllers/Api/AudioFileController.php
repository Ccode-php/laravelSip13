<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AudioFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudioFileController extends Controller
{
    public function index()
    {
        $audioFiles = AudioFile::query()
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $audioFiles,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'audio' => [
                'required',
                'file',
                'mimes:mp3,wav,ogg',
                'max:51200',
            ],
        ]);

        $file = $request->file('audio');

        $extension = strtolower(
            $file->getClientOriginalExtension()
        );

        $filename = Str::uuid() . '.' . $extension;

        $path = $file->storeAs(
            'audio',
            $filename,
            'public'
        );

        $audio = AudioFile::create([
            'name' => $validated['name'],
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'duration' => null,
            'enabled' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Audio fayl yuklandi.',
            'data' => $audio,
        ], 201);
    }

    public function show(AudioFile $audioFile)
    {
        return response()->json([
            'success' => true,
            'data' => $audioFile,
        ]);
    }

    public function update(
        Request $request,
        AudioFile $audioFile
    ) {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'enabled' => [
                'boolean',
            ],
        ]);

        $audioFile->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Audio maʼlumotlari yangilandi.',
            'data' => $audioFile->fresh(),
        ]);
    }

    public function destroy(AudioFile $audioFile)
    {
        if (
            $audioFile->file_path &&
            Storage::disk('public')->exists(
                $audioFile->file_path
            )
        ) {
            Storage::disk('public')->delete(
                $audioFile->file_path
            );
        }

        $audioFile->delete();

        return response()->json([
            'success' => true,
            'message' => 'Audio fayl o‘chirildi.',
        ]);
    }
}