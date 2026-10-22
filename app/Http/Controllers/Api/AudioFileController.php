<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AudioFile;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class AudioFileController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => AudioFile::query()->orderByDesc('id')->get(),
        ]);
    }

    /**
     * Audio Asterisk tushunadigan formatga (wav, 8 kHz, mono, 16-bit) o‘tkaziladi
     * va Asterisk o‘qiy oladigan papkaga (hostPath) yoziladi.
     * DB da file_path = "campaign/<uuid>" (kengaytmasiz) saqlanadi: aynan shu Playback() ga beriladi.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'audio' => ['required', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:51200'],
        ]);

        $dir = rtrim(config('asterisk.sounds_path'), '/');

        if (!is_dir($dir) || !is_writable($dir)) {
            return response()->json([
                'success' => false,
                'message' => 'Asterisk ovoz papkasi topilmadi yoki unga yozib bo‘lmaydi.',
            ], 500);
        }

        $file = $request->file('audio');
        $uuid = (string) Str::uuid();
        $target = "{$dir}/{$uuid}.wav";

        $convert = Process::timeout(180)->run([
            'ffmpeg', '-nostdin', '-y',
            '-i', $file->getRealPath(),
            '-vn', '-ar', '8000', '-ac', '1', '-c:a', 'pcm_s16le',
            $target,
        ]);

        if ($convert->failed() || !is_file($target)) {
            @unlink($target);

            return response()->json([
                'success' => false,
                'message' => 'Audioni konvertatsiya qilib bo‘lmadi. Fayl buzilgan bo‘lishi mumkin.',
            ], 422);
        }

        @chmod($target, 0644);

        $audio = AudioFile::create([
            'name' => $validated['name'],
            'original_name' => $file->getClientOriginalName(),
            'file_path' => config('asterisk.sounds_prefix') . '/' . $uuid,
            'mime_type' => 'audio/wav',
            'size' => filesize($target),
            'duration' => $this->probeDuration($target),
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
        return response()->json(['success' => true, 'data' => $audioFile]);
    }

    /**
     * Vue dagi <audio> pleyer uchun.
     */
    public function stream(AudioFile $audioFile)
    {
        $path = $this->localPath($audioFile);

        abort_unless(is_file($path), 404, 'Audio fayl topilmadi.');

        return response()->file($path, ['Content-Type' => 'audio/wav']);
    }

    public function update(Request $request, AudioFile $audioFile)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['boolean'],
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
        // campaigns.audio_file_id restrictOnDelete: 500 xato o‘rniga tushunarli xabar
        if (Campaign::where('audio_file_id', $audioFile->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Bu audio kampaniyalarda ishlatilgan, o‘chirib bo‘lmaydi.',
            ], 422);
        }

        $path = $this->localPath($audioFile);

        if (is_file($path)) {
            @unlink($path);
        }

        $audioFile->delete();

        return response()->json([
            'success' => true,
            'message' => 'Audio fayl o‘chirildi.',
        ]);
    }

    private function localPath(AudioFile $audioFile): string
    {
        // basename: DB dagi yo‘l orqali papkadan chiqib ketishning oldini oladi
        return rtrim(config('asterisk.sounds_path'), '/') . '/' . basename($audioFile->file_path) . '.wav';
    }

    private function probeDuration(string $path): ?int
    {
        $probe = Process::timeout(30)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        if ($probe->failed()) {
            return null;
        }

        $seconds = (float) trim($probe->output());

        return $seconds > 0 ? (int) ceil($seconds) : null;
    }
}
