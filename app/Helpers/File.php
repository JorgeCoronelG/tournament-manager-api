<?php

namespace App\Helpers;

use App\Core\Enum\Path;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class File
{
    const FILE_NAME_LENGHT = 30;

    public static function uploadFile(UploadedFile $file, string $customPath): string
    {
        $filename = self::getFilename(self::FILE_NAME_LENGHT).self::getFileExtension($file->getClientOriginalName());
        $pathUrl = self::getFilePublicPath($customPath);
        $file->move($pathUrl, $filename);

        return $filename;
    }

    public static function deleteFile(string $path, string $filename): void
    {
        Storage::delete(self::getFileStoragePath($path, $filename));
    }

    public static function getFilePublicPath(string $path, ?string $filename = null): string
    {
        return (! is_null($filename))
            ? public_path(Path::STORAGE->value.$path.$filename)
            : public_path(Path::STORAGE->value.$path);
    }

    public static function getFileStoragePath(string $path, ?string $filename = null): string
    {
        return (! is_null($filename))
            ? Path::STORAGE_PUBLIC->value.$path.$filename
            : Path::STORAGE_PUBLIC->value.$path;
    }

    public static function getExposedPath(string $path, ?string $filename = null): string
    {
        return (! is_null($filename))
            ? Path::STORAGE->value.$path.$filename
            : Path::STORAGE->value.$path;
    }

    public static function storagePath(string $path, ?string $filename = null): string
    {
        return (! is_null($filename))
            ? storage_path('app/public/').$path.$filename
            : storage_path('app/public/').$path;
    }

    public static function getFilename(int $lenght): string
    {
        $random = Str::random($lenght);
        $timestamp = now()->getTimestamp();

        return "{$random}_$timestamp";
    }

    public static function getFileExtension(string $file): string
    {
        return '.'.pathinfo($file, PATHINFO_EXTENSION);
    }
}
