<?php

namespace App\Workflow;

use App\Models\Order;
use InvalidArgumentException;

// Pictures live next to the database, so they stay on this machine and go away with it.
class ParcelPhotos
{
    private const MAX_BYTES = 2_000_000;

    public function store(Order $order, string $dataUrl): void
    {
        $jpeg = base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,')), true);

        if (! str_starts_with($dataUrl, 'data:image/jpeg;base64,') || $jpeg === false || ! str_starts_with($jpeg, "\xFF\xD8") || strlen($jpeg) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Not a JPEG photo');
        }

        $path = $this->directory()."/{$order->id}.jpg";
        file_put_contents($path, $jpeg);
        $order->update(['photo_path' => $path]);
    }

    private function directory(): string
    {
        $directory = dirname(config('database.connections.sqlite.database')).'/photos';

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
