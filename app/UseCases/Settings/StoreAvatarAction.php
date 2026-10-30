<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ログインユーザー自身のアバター画像を保存するユースケース。
 *
 * public disk の avatars ディレクトリに画像を保存し、
 * users.avatar_url に公開URLを記録する。
 *
 * 画像差し替え時はDB更新成功後に古い画像を削除する。
 * DB更新失敗時は新しい画像を削除し、不要なファイルを残さない。
 */
final class StoreAvatarAction
{
    public function __invoke(User $user, UploadedFile $file): void
    {
        $disk = Storage::disk('public');

        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw new RuntimeException('対応していない画像形式です。'),
        };

        $filename = Str::ulid().'.'.$extension;
        $path = 'avatars/'.$filename;

        $storedPath = $disk->putFileAs('avatars', $file, $filename);

        if ($storedPath === false) {
            throw new RuntimeException('アバター画像の保存に失敗しました。');
        }

        $oldUrl = $user->avatar_url;

        try {
            $user->update([
                'avatar_url' => '/storage/'.$path,
            ]);
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($oldUrl !== null && str_starts_with($oldUrl, '/storage/avatars/')) {
            $oldPath = substr($oldUrl, strlen('/storage/'));

            if ($oldPath !== $path) {
                $disk->delete($oldPath);
            }
        }
    }
}
