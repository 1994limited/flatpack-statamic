<?php

namespace NineteenNinetyFour\Flatpack;

/** Content-addressed heavy-field bodies: bodies/<hash>.ser holding serialize()d arrays. */
final class BodyStore
{
    public function __construct(private string $dir) {}

    public function read(string $hash): ?array
    {
        $s = @file_get_contents($this->file($hash));

        return $s === false ? null : unserialize($s);
    }

    public function write(string $hash, array $data): void
    {
        $f = $this->file($hash);
        if (! is_file($f)) {
            Flatpack::writeAtomic($f, serialize($data));
        }
    }

    public function file(string $hash): string
    {
        return $this->dir.'/'.substr($hash, 0, 2).'/'.$hash.'.ser';
    }
}
