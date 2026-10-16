<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LandingPageController extends Controller
{
    /**
     * Show the application landing and download page.
     */
    public function index()
    {
        $apkInfo = $this->getFileInfo('apk', 'capeonn.apk', 'Android 8.0+ (.apk)', '60.8MB');
        $exeInfo = $this->getFileInfo('exe', 'capeonn.exe', 'Windows 10 / 11 (.exe)', '48MB');

        return view('welcome', compact('apkInfo', 'exeInfo'));
    }

    /**
     * Download Android APK directly.
     */
    public function downloadAndroid(): BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        $file = $this->findFile('apk', 'capeonn.apk');

        if ($file && file_exists($file['path'])) {
            return response()->download($file['path'], $file['name'], [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'no-cache, must-revalidate',
            ]);
        }

        return redirect()->route('home')->with('error', 'Android APK file not found in public/downloads folder.');
    }

    /**
     * Download Windows EXE directly.
     */
    public function downloadWindows(): BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        $file = $this->findFile('exe', 'capeonn.exe');

        if ($file && file_exists($file['path'])) {
            return response()->download($file['path'], $file['name'], [
                'Content-Type' => 'application/vnd.microsoft.portable-executable',
                'Cache-Control' => 'no-cache, must-revalidate',
            ]);
        }

        return redirect()->route('home')->with('error', 'Windows EXE file not found in public/downloads folder.');
    }

    /**
     * Get JSON status of download files.
     */
    public function status()
    {
        return response()->json([
            'downloads_folder' => public_path('downloads'),
            'apk' => $this->getFileInfo('apk', 'capeonn.apk', 'Android 8.0+ (.apk)', '60.8MB'),
            'exe' => $this->getFileInfo('exe', 'capeonn.exe', 'Windows 10 / 11 (.exe)', '48MB'),
        ]);
    }

    /**
     * Helper to locate an APK or EXE in public/downloads or app build directories.
     */
    private function findFile(string $extension, string $preferredName): ?array
    {
        $downloadsDir = public_path('downloads');

        if (is_dir($downloadsDir)) {
            // 1. Check exact preferred name first
            $preferredPath = $downloadsDir . DIRECTORY_SEPARATOR . $preferredName;
            if (file_exists($preferredPath)) {
                return [
                    'path' => $preferredPath,
                    'name' => $preferredName,
                    'size' => filesize($preferredPath),
                    'mtime' => filemtime($preferredPath),
                ];
            }

            // 2. Check any matching file with that extension in public/downloads
            $matches = glob($downloadsDir . DIRECTORY_SEPARATOR . '*.' . $extension);
            if (!empty($matches)) {
                $firstMatch = $matches[0];
                return [
                    'path' => $firstMatch,
                    'name' => basename($firstMatch),
                    'size' => filesize($firstMatch),
                    'mtime' => filemtime($firstMatch),
                ];
            }
        }

        return null;
    }

    /**
     * Helper to get structured file info for views.
     */
    private function getFileInfo(string $extension, string $preferredName, string $platformTitle, string $fallbackSize): array
    {
        $file = $this->findFile($extension, $preferredName);

        if ($file) {
            $bytes = $file['size'];
            // If placeholder file is tiny (< 100KB), show standard package size
            $formattedSize = ($bytes < 102400) ? $fallbackSize : $this->formatBytes($bytes);
            
            return [
                'exists' => true,
                'name' => $file['name'],
                'url' => asset('downloads/' . $file['name']),
                'download_url' => route('download.' . ($extension === 'apk' ? 'android' : 'windows')),
                'size' => $formattedSize,
                'raw_size' => $bytes,
                'version' => 'v1.0.4',
                'platform' => $platformTitle,
                'updated_at' => date('M d, Y', $file['mtime']),
            ];
        }

        return [
            'exists' => false,
            'name' => $preferredName,
            'url' => '#',
            'download_url' => route('download.' . ($extension === 'apk' ? 'android' : 'windows')),
            'size' => $fallbackSize,
            'raw_size' => 0,
            'version' => 'v1.0.4',
            'platform' => $platformTitle,
            'updated_at' => 'Ready to upload',
        ];
    }

    /**
     * Format bytes to readable MB/KB string.
     */
    private function formatBytes(int $bytes, int $precision = 1): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, $precision) . 'GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, $precision) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, $precision) . 'KB';
        }
        return $bytes . 'B';
    }
}
